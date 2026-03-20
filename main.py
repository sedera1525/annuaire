"""
Societies — Visualiseur de sociétés (données Google Business)
"""

import logging
import os
import io
import csv as csv_mod
import time
import threading
import json
import sqlite3
import hashlib
import secrets
import asyncio
from logging.handlers import RotatingFileHandler
from pathlib import Path
from datetime import datetime
from typing import Optional, List

from fastapi import FastAPI, HTTPException, Body, Request, Response, Form
from fastapi.staticfiles import StaticFiles
from fastapi.responses import FileResponse, StreamingResponse, RedirectResponse, HTMLResponse
from fastapi.middleware.cors import CORSMiddleware
from slowapi import Limiter, _rate_limit_exceeded_handler
from slowapi.util import get_remote_address
from slowapi.errors import RateLimitExceeded
import duckdb
from dotenv import load_dotenv

from models import (
    GenerateRequest, BatchRequest, AutoStartRequest,
    UpdateFicheRequest, LicenseVerifyRequest,
)

load_dotenv()

# =============================================================================
# CONFIGURATION
# =============================================================================

MONTHS_FR = ["janvier", "février", "mars", "avril", "mai", "juin",
             "juillet", "août", "septembre", "octobre", "novembre", "décembre"]

BASE_DIR    = Path(__file__).parent
CSV_PATH    = str(BASE_DIR / "0.csv")
DB_PATH     = str(BASE_DIR / "societies.duckdb")
FICHES_DB   = str(BASE_DIR / "fiches.db")
STATIC_DIR  = BASE_DIR / "static"
STATIC_DIR.mkdir(exist_ok=True)

OPENAI_API_KEY = os.getenv("OPENAI_API_KEY", "")
OPENAI_MODEL   = os.getenv("OPENAI_MODEL", "gpt-4o-mini")
OPENAI_TIMEOUT = float(os.getenv("OPENAI_TIMEOUT", "30"))

APP_USERNAME = os.getenv("APP_USERNAME", "admin")
APP_PASSWORD = os.getenv("APP_PASSWORD", "changeme123")
SECRET_KEY   = os.getenv("SECRET_KEY", secrets.token_hex(32))
COOKIE_NAME  = "societies_session"

ALLOWED_ORIGINS = [
    o.strip()
    for o in os.getenv("ALLOWED_ORIGINS", "http://localhost:8090,http://localhost:8085").split(",")
    if o.strip()
]

# =============================================================================
# LOGGING
# =============================================================================

LOG_DIR = BASE_DIR / "logs"
LOG_DIR.mkdir(exist_ok=True)

logger = logging.getLogger("societies")
logger.setLevel(logging.INFO)

_fmt = logging.Formatter("%(asctime)s [%(levelname)s] %(message)s", datefmt="%Y-%m-%d %H:%M:%S")
_fh  = RotatingFileHandler(LOG_DIR / "societies.log", maxBytes=5 * 1024 * 1024, backupCount=3, encoding="utf-8")
_fh.setFormatter(_fmt)
_ch  = logging.StreamHandler()
_ch.setFormatter(_fmt)
logger.addHandler(_fh)
logger.addHandler(_ch)


def format_date_fr(date_str: str) -> str:
    try:
        dt = datetime.strptime(date_str[:10], "%Y-%m-%d")
        return f"{dt.day} {MONTHS_FR[dt.month - 1]} {dt.year}"
    except Exception:
        return ""


# =============================================================================
# AUTH
# =============================================================================

_sessions: dict      = {}
_sessions_lock       = threading.Lock()


def make_token() -> str:
    return secrets.token_hex(32)


def check_credentials(username: str, password: str) -> bool:
    ok_user = secrets.compare_digest(username, APP_USERNAME)
    ok_pass = secrets.compare_digest(password, APP_PASSWORD)
    return ok_user and ok_pass


def add_session(token: str) -> None:
    with _sessions_lock:
        _sessions[token] = True


def remove_session(token: str) -> None:
    with _sessions_lock:
        _sessions.pop(token, None)


def is_authenticated(request: Request) -> bool:
    token = request.cookies.get(COOKIE_NAME)
    if not token:
        return False
    with _sessions_lock:
        return bool(_sessions.get(token))


# =============================================================================
# APP + RATE LIMITER
# =============================================================================

limiter = Limiter(key_func=get_remote_address, default_limits=[])
app     = FastAPI(title="Societies", version="1.0.0")
app.state.limiter = limiter
app.add_exception_handler(RateLimitExceeded, _rate_limit_exceeded_handler)

app.add_middleware(
    CORSMiddleware,
    allow_origins=ALLOWED_ORIGINS,
    allow_credentials=True,
    allow_methods=["GET", "POST", "PUT", "DELETE"],
    allow_headers=["Content-Type", "Authorization"],
)

# =============================================================================
# GLOBAL STATE
# =============================================================================

db_state = {
    "ready":       False,
    "initializing": False,
    "message":     "En attente d'initialisation...",
    "rows":        0,
    "progress":    0,
}

auto_state = {
    "running":       False,
    "concurrency":   6,
    "batch_size":    50,
    "processed":     0,
    "done":          0,
    "errors":        0,
    "total":         0,
    "offset":        0,
    "started_at":    None,
    "last_activity": None,
}
_auto_task:         asyncio.Task       = None
_head_pause_events: list[asyncio.Event] = []


# =============================================================================
# INITIALISATION DB — DuckDB
# =============================================================================

def init_db():
    global db_state
    db_state["initializing"] = True
    db_state["message"]      = "Chargement du fichier CSV dans la base de données..."
    db_state["progress"]     = 5
    logger.info("Initialisation DuckDB démarrée")
    try:
        conn    = duckdb.connect(DB_PATH)
        existing = conn.execute(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_name='companies'"
        ).fetchone()[0]

        if not existing:
            db_state["message"]  = "Création de la table (opération unique ~3 min)..."
            db_state["progress"] = 15
            conn.execute(f"""
                CREATE TABLE companies AS
                SELECT
                    title,
                    description,
                    category,
                    COALESCE(phone, '') AS phone,
                    COALESCE(url, '') AS url,
                    COALESCE(domain, '') AS domain,
                    TRY_CAST(latitude AS DOUBLE) AS latitude,
                    TRY_CAST(longitude AS DOUBLE) AS longitude,
                    is_claimed,
                    COALESCE(contacts, '[]') AS contacts,
                    COALESCE("address_info.address", '') AS addr_street,
                    COALESCE("address_info.city", '') AS city,
                    COALESCE("address_info.zip", '') AS zip_code,
                    COALESCE("address_info.region", '') AS region,
                    COALESCE("address_info.country_code", '') AS country_code,
                    TRY_CAST("rating.value" AS FLOAT) AS rating_value,
                    TRY_CAST("rating.votes_count" AS INTEGER) AS rating_votes,
                    COALESCE(snippet, '') AS snippet,
                    COALESCE(logo, '') AS logo,
                    COALESCE(main_image, '') AS main_image,
                    COALESCE(address, '') AS address_full,
                    TRY_CAST(total_photos AS INTEGER) AS total_photos
                FROM read_csv('{CSV_PATH}',
                    ignore_errors=True,
                    quote='"',
                    escape='"',
                    header=True
                )
                WHERE title IS NOT NULL AND title != ''
            """)
            db_state["progress"] = 70
            db_state["message"]  = "Création des index..."
            conn.execute("CREATE INDEX idx_city     ON companies(city)")
            conn.execute("CREATE INDEX idx_zip      ON companies(zip_code)")
            conn.execute("CREATE INDEX idx_category ON companies(category)")
            db_state["progress"] = 90

        rows = conn.execute("SELECT COUNT(*) FROM companies").fetchone()[0]
        conn.close()
        db_state.update({"ready": True, "rows": rows, "progress": 100,
                         "message": f"Base prête — {rows:,} entreprises"})
        logger.info(f"DuckDB prête — {rows:,} entreprises")
    except Exception as e:
        logger.error(f"Erreur init DuckDB : {e}")
        db_state.update({"message": f"Erreur: {e}", "ready": False, "progress": 0})
    finally:
        db_state["initializing"] = False


def get_conn():
    if db_state["ready"] and os.path.exists(DB_PATH):
        return duckdb.connect(DB_PATH, read_only=True)
    raise HTTPException(status_code=503, detail="Base de données non disponible")


@app.on_event("startup")
async def startup():
    if os.path.exists(DB_PATH):
        try:
            conn = duckdb.connect(DB_PATH, read_only=True)
            rows = conn.execute("SELECT COUNT(*) FROM companies").fetchone()[0]
            conn.close()
            db_state.update({"ready": True, "rows": rows, "progress": 100,
                             "message": f"Base prête — {rows:,} entreprises"})
            logger.info(f"DuckDB déjà disponible — {rows:,} entreprises")
            return
        except Exception:
            pass
    threading.Thread(target=init_db, daemon=True).start()


# =============================================================================
# FICHES DB — SQLite
# =============================================================================

def init_fiches_db():
    conn = sqlite3.connect(FICHES_DB)
    try:
        conn.execute("""
            CREATE TABLE IF NOT EXISTS fiches (
                id                INTEGER PRIMARY KEY AUTOINCREMENT,
                company_title     TEXT UNIQUE NOT NULL,
                status            TEXT DEFAULT 'pending',
                qa_answered       TEXT,
                qa_open           TEXT,
                model             TEXT,
                completion_tokens INTEGER,
                generated_at      TEXT,
                error             TEXT,
                deleted_at        TEXT
            )
        """)
        for col in ["qa_answered TEXT", "qa_open TEXT", "deleted_at TEXT", "intro_text TEXT"]:
            try:
                conn.execute(f"ALTER TABLE fiches ADD COLUMN {col}")
            except Exception:
                pass
        conn.execute("""
            CREATE TABLE IF NOT EXISTS settings (
                key   TEXT PRIMARY KEY,
                value TEXT
            )
        """)
        conn.commit()
    finally:
        conn.close()


init_fiches_db()


def get_setting(key: str) -> str | None:
    conn = sqlite3.connect(FICHES_DB)
    try:
        row = conn.execute("SELECT value FROM settings WHERE key=?", [key]).fetchone()
        return row[0] if row else None
    finally:
        conn.close()


def set_setting(key: str, value: str):
    conn = sqlite3.connect(FICHES_DB)
    try:
        conn.execute("INSERT OR REPLACE INTO settings(key,value) VALUES(?,?)", [key, value])
        conn.commit()
    finally:
        conn.close()


def _hash_key(raw: str) -> str:
    return hashlib.sha256(raw.encode()).hexdigest()


def get_fiche(title: str) -> dict | None:
    conn = sqlite3.connect(FICHES_DB)
    conn.row_factory = sqlite3.Row
    try:
        row = conn.execute("SELECT * FROM fiches WHERE company_title = ?", [title]).fetchone()
        return dict(row) if row else None
    finally:
        conn.close()


def save_fiche(title: str, status: str, qa_answered: str = None,
               qa_open: str = None, intro_text: str = None, model: str = None,
               completion_tokens: int = 0, error: str = None):
    conn = sqlite3.connect(FICHES_DB)
    try:
        conn.execute("BEGIN")
        conn.execute("""
            INSERT INTO fiches
                (company_title, status, qa_answered, qa_open, intro_text, model, completion_tokens, generated_at, error)
            VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now'), ?)
            ON CONFLICT(company_title) DO UPDATE SET
                status=excluded.status,
                qa_answered=excluded.qa_answered,
                qa_open=excluded.qa_open,
                intro_text=excluded.intro_text,
                model=excluded.model,
                completion_tokens=excluded.completion_tokens,
                generated_at=excluded.generated_at,
                error=excluded.error
        """, [title, status, qa_answered, qa_open, intro_text, model, completion_tokens, error])
        conn.commit()
    except Exception as e:
        conn.rollback()
        logger.error(f"save_fiche error for '{title}': {e}")
        raise
    finally:
        conn.close()


# =============================================================================
# GÉNÉRATION OPENAI
# =============================================================================

OPEN_QUESTIONS_TEMPLATE = [
    "Comment fonctionne réellement le service client de {nom} en cas de problème ?",
    "Les clients fidèles de {nom} recommandent-ils vraiment leurs services ?",
    "Les tarifs de {nom} sont-ils transparents ?",
    "Les délais annoncés par {nom} sont-ils respectés ?",
    "Le rapport qualité-prix de {nom} est-il intéressant ?",
    "{nom} respecte-t-elle ses délais annoncés ?",
]

GENERATION_PROMPT = """Tu es un analyste de réputation spécialisé dans les entreprises françaises, au style journalistique.

À partir des données ci-dessous, génère :
1. Un texte introductif de EXACTEMENT 3 phrases sur ce que pensent les clients de cet établissement (avis, réputation, satisfaction globale).
2. Les réponses aux 3 questions d'analyse (EXACTEMENT 2 phrases par réponse).

Règles strictes :
- Ne cite JAMAIS "{nom}" dans l'intro ni dans les réponses. Utilise "cet établissement", "cette enseigne", "ce prestataire", etc.
- Style journalistique : factuel, nuancé, appuyé sur la note et le nombre d'avis.
- EXACTEMENT 2 phrases par réponse (ni plus, ni moins).
- EXACTEMENT 3 phrases pour l'intro.

Entreprise : {nom}
Secteur : {categorie}
Ville : {ville} ({code_postal})
Note clients : {note}

Réponds UNIQUEMENT avec ce JSON valide, sans texte avant ou après :
{{
  "intro": "Phrase 1 sur la réputation générale. Phrase 2 nuancée. Phrase 3 de conclusion.",
  "qa_answered": [
    {{"q": "Que pensent réellement les clients de {nom} ?", "r": "Phrase 1. Phrase 2."}},
    {{"q": "{nom} est-elle fiable ?", "r": "Phrase 1. Phrase 2."}},
    {{"q": "Qu'est-ce qui surprend le plus les clients de {nom} ?", "r": "Phrase 1. Phrase 2."}}
  ]
}}"""


async def call_openai(prompt: str) -> dict:
    if not OPENAI_API_KEY:
        raise ValueError("Clé API OpenAI manquante. Configurez OPENAI_API_KEY dans .env")
    from openai import AsyncOpenAI
    client = AsyncOpenAI(api_key=OPENAI_API_KEY, timeout=OPENAI_TIMEOUT)
    response = await client.chat.completions.create(
        model=OPENAI_MODEL,
        messages=[{"role": "user", "content": prompt}],
        temperature=0.7,
        max_tokens=2000,
    )
    msg = response.choices[0].message.content.strip()
    return {
        "text":             msg,
        "model":            response.model,
        "prompt_tokens":    response.usage.prompt_tokens,
        "completion_tokens": response.usage.completion_tokens,
    }


def _sse(event_type: str, **kwargs) -> str:
    return f"data: {json.dumps({'type': event_type, **kwargs}, ensure_ascii=False)}\n\n"


async def stream_generate(title: str, company_data: dict):
    """Async generator yielding SSE events for a single generation."""
    if not OPENAI_API_KEY:
        yield _sse("error", message="Clé API OpenAI manquante dans .env")
        return

    yield _sse("stage", message="Préparation du prompt...", percent=5)
    prompt = GENERATION_PROMPT.format(
        nom=title,
        categorie=company_data.get("category") or "Non renseigné",
        ville=company_data.get("city") or "Non renseignée",
        code_postal=company_data.get("zip_code") or "",
        note=f"{company_data.get('rating_value') or '?'}/5 ({company_data.get('rating_votes') or 0} avis)",
    )
    save_fiche(title, "generating")
    yield _sse("stage", message="Connexion à OpenAI...", percent=10)

    try:
        from openai import AsyncOpenAI
        client = AsyncOpenAI(api_key=OPENAI_API_KEY, timeout=OPENAI_TIMEOUT)
        stream = await client.chat.completions.create(
            model=OPENAI_MODEL,
            messages=[{"role": "user", "content": prompt}],
            temperature=0.7,
            max_tokens=2000,
            stream=True,
        )
        yield _sse("stage", message="Génération en cours...", percent=15)

        full_text      = ""
        token_count    = 0
        ESTIMATED_TOKENS = 900

        async for chunk in stream:
            delta = chunk.choices[0].delta.content or ""
            if delta:
                full_text   += delta
                token_count += 1
                percent = min(15 + int(token_count / ESTIMATED_TOKENS * 70), 85)
                yield _sse("token", token=delta, count=token_count, percent=percent)

        yield _sse("stage", message="Analyse du JSON...", percent=88)
        parsed = json.loads(full_text.strip())
        if not isinstance(parsed, dict) or "qa_answered" not in parsed:
            raise ValueError("Réponse inattendue (format JSON invalide)")

        qa_answered    = parsed["qa_answered"]
        intro          = parsed.get("intro", "")
        open_questions = [q.replace("{nom}", title) for q in OPEN_QUESTIONS_TEMPLATE]
        date_fr        = format_date_fr(datetime.now().strftime("%Y-%m-%d"))

        yield _sse("stage", message="Sauvegarde...", percent=95)
        save_fiche(title, "done",
                   qa_answered=json.dumps(qa_answered, ensure_ascii=False),
                   intro_text=intro,
                   model=OPENAI_MODEL,
                   completion_tokens=token_count)

        yield _sse("done",
                   qa_answered=qa_answered,
                   intro_text=intro,
                   open_questions=open_questions,
                   date_fr=date_fr,
                   model=OPENAI_MODEL,
                   completion_tokens=token_count,
                   percent=100)
        logger.info(f"Fiche générée (stream) : {title} — {token_count} tokens")

    except Exception as e:
        logger.error(f"stream_generate error for '{title}': {e}")
        save_fiche(title, "error", error=str(e))
        yield _sse("error", message=str(e))


# =============================================================================
# AUTH HTML + ENDPOINTS
# =============================================================================

LOGIN_HTML = """<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Societies — Connexion</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{background:#0f1117;font-family:system-ui,sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center}
  .box{background:#1a1d27;border:1px solid #2e3247;border-radius:16px;padding:40px;width:360px}
  .logo{font-size:22px;font-weight:700;color:#6c8aff;margin-bottom:4px}
  .logo span{color:#e2e8f0}
  .sub{font-size:13px;color:#8892a4;margin-bottom:28px}
  label{font-size:12px;color:#8892a4;font-weight:600;text-transform:uppercase;letter-spacing:.6px;display:block;margin-bottom:6px}
  input{width:100%;background:#242736;border:1px solid #2e3247;color:#e2e8f0;border-radius:8px;padding:10px 12px;font-size:14px;outline:none;margin-bottom:16px;transition:border-color .2s}
  input:focus{border-color:#6c8aff}
  button{width:100%;background:#6c8aff;color:white;border:none;border-radius:8px;padding:11px;font-size:14px;font-weight:600;cursor:pointer;transition:opacity .2s}
  button:hover{opacity:.9}
  .error{color:#f87171;font-size:13px;margin-bottom:14px;background:rgba(248,113,113,.1);padding:8px 12px;border-radius:8px}
</style>
</head>
<body>
<div class="box">
  <div class="logo">Socie<span>ties</span></div>
  <div class="sub">Accès restreint — connectez-vous</div>
  {error}
  <form method="post" action="/login">
    <label>Identifiant</label>
    <input type="text" name="username" autocomplete="username" autofocus required>
    <label>Mot de passe</label>
    <input type="password" name="password" autocomplete="current-password" required>
    <button type="submit">Se connecter</button>
  </form>
</div>
</body>
</html>"""


@app.get("/login", response_class=HTMLResponse)
def login_page(request: Request):
    if is_authenticated(request):
        return RedirectResponse("/", status_code=302)
    return HTMLResponse(LOGIN_HTML.replace("{error}", ""))


@app.post("/login")
@limiter.limit("10/minute")
async def login(request: Request,
                username: str = Form(...), password: str = Form(...)):
    if check_credentials(username, password):
        token = make_token()
        add_session(token)
        logger.info(f"Connexion réussie : {username}")
        resp = RedirectResponse("/", status_code=302)
        resp.set_cookie(COOKIE_NAME, token, httponly=True, samesite="lax", max_age=86400 * 7)
        return resp
    logger.warning(f"Tentative de connexion échouée : {username}")
    return HTMLResponse(
        LOGIN_HTML.replace("{error}", '<div class="error">Identifiant ou mot de passe incorrect.</div>'),
        status_code=401,
    )


@app.get("/logout")
def logout(request: Request):
    token = request.cookies.get(COOKIE_NAME)
    if token:
        remove_session(token)
    resp = RedirectResponse("/login", status_code=302)
    resp.delete_cookie(COOKIE_NAME)
    return resp


@app.middleware("http")
async def auth_middleware(request: Request, call_next):
    path = request.url.path
    if path in ("/login", "/api/license/verify") or path.startswith("/static/"):
        return await call_next(request)
    if path == "/login" and request.method == "POST":
        return await call_next(request)
    if not is_authenticated(request):
        if path.startswith("/api/"):
            return Response(
                content='{"detail":"Non authentifié"}',
                status_code=401,
                media_type="application/json",
            )
        return RedirectResponse("/login", status_code=302)
    return await call_next(request)


# =============================================================================
# ENDPOINTS — STATUS / SEARCH
# =============================================================================

@app.get("/api/status")
def get_status():
    return db_state


@app.get("/api/categories")
def list_categories():
    conn = get_conn()
    try:
        rows = conn.execute("""
            SELECT category, COUNT(*) AS cnt
            FROM companies
            WHERE category IS NOT NULL AND category != ''
            GROUP BY category
            ORDER BY cnt DESC
            LIMIT 100
        """).fetchall()
        return [{"category": r[0], "count": r[1]} for r in rows]
    finally:
        conn.close()


@app.get("/api/cities")
def list_cities(q: Optional[str] = None):
    conn = get_conn()
    try:
        params = []
        where  = ""
        if q:
            where = "AND UPPER(city) LIKE UPPER(?)"
            params.append(f"%{q[:100]}%")
        rows = conn.execute(f"""
            SELECT city, COUNT(*) AS cnt
            FROM companies
            WHERE city IS NOT NULL AND city != '' {where}
            GROUP BY city
            ORDER BY cnt DESC
            LIMIT 50
        """, params).fetchall()
        return [{"city": r[0], "count": r[1]} for r in rows]
    finally:
        conn.close()


@app.get("/api/search")
def search(
    q:                Optional[str]  = None,
    city:             Optional[str]  = None,
    zip_code:         Optional[str]  = None,
    category:         Optional[str]  = None,
    has_phone:        Optional[bool] = None,
    has_website:      Optional[bool] = None,
    no_web_with_email: bool          = False,
    no_web_no_email:   bool          = False,
    page:    int = 1,
    per_page: int = 50,
    sort_by: str = "rating",
):
    conn       = get_conn()
    conditions = []
    params     = []

    if q:
        pct = f"%{q[:150]}%"
        conditions.append("(UPPER(title) LIKE UPPER(?) OR UPPER(city) LIKE UPPER(?) OR UPPER(category) LIKE UPPER(?))")
        params.extend([pct, pct, pct])
    if city:
        conditions.append("UPPER(city) LIKE UPPER(?)")
        params.append(f"%{city[:100]}%")
    if zip_code:
        conditions.append("zip_code LIKE ?")
        params.append(f"{zip_code[:10]}%")
    if category:
        conditions.append("UPPER(category) LIKE UPPER(?)")
        params.append(f"%{category[:150]}%")
    if has_phone is True:
        conditions.append("phone != '' AND phone IS NOT NULL")
    if has_phone is False:
        conditions.append("(phone = '' OR phone IS NULL)")
    if has_website is True:
        conditions.append("url != '' AND url IS NOT NULL")
    if has_website is False:
        conditions.append("(url = '' OR url IS NULL)")
    if no_web_with_email:
        conditions.append("(url = '' OR url IS NULL)")
        conditions.append("contacts LIKE '%\"type\":\"Mail\"%'")
    if no_web_no_email:
        conditions.append("(url = '' OR url IS NULL)")
        conditions.append("(contacts NOT LIKE '%\"type\":\"Mail\"%')")

    where  = " AND ".join(conditions) if conditions else "1=1"
    offset = (page - 1) * per_page
    order  = {
        "rating": "rating_value DESC NULLS LAST, rating_votes DESC NULLS LAST",
        "votes":  "rating_votes DESC NULLS LAST",
        "name":   "title ASC",
        "city":   "city ASC",
    }.get(sort_by, "rating_value DESC NULLS LAST")

    try:
        total = conn.execute(f"SELECT COUNT(*) FROM companies WHERE {where}", params).fetchone()[0]
    except Exception:
        total = 0

    t0   = time.time()
    rows = conn.execute(f"""
        SELECT
            title, category, phone, url, domain,
            addr_street, city, zip_code, region,
            rating_value, rating_votes,
            contacts, logo, snippet, is_claimed,
            latitude, longitude, address_full
        FROM companies
        WHERE {where}
        ORDER BY {order}
        LIMIT {int(per_page)} OFFSET {int(offset)}
    """, params).fetchall()
    cols    = [d[0] for d in conn.description]
    elapsed = round(time.time() - t0, 3)
    conn.close()

    results = []
    for row in rows:
        d = dict(zip(cols, row))
        try:
            contacts_data = json.loads(d.get("contacts") or "[]")
            d["emails"]   = [c["value"] for c in contacts_data if c.get("type") == "Mail"]
        except Exception:
            d["emails"] = []
        results.append(d)

    return {
        "results":  results,
        "total":    total,
        "page":     page,
        "per_page": per_page,
        "pages":    max(1, (total + per_page - 1) // per_page) if total > 0 else 1,
        "elapsed":  elapsed,
    }


@app.get("/api/company/{title:path}")
def get_company(title: str):
    conn = get_conn()
    try:
        row = conn.execute("SELECT * FROM companies WHERE title = ? LIMIT 1", [title]).fetchone()
        if not row:
            raise HTTPException(status_code=404, detail="Entreprise non trouvée")
        cols = [d[0] for d in conn.description]
        d    = dict(zip(cols, row))
        try:
            contacts_data    = json.loads(d.get("contacts") or "[]")
            d["emails"]      = [c["value"] for c in contacts_data if c.get("type") == "Mail"]
            d["phones_extra"] = [c["value"] for c in contacts_data if c.get("type") == "Telephone"]
        except Exception:
            d["emails"]      = []
            d["phones_extra"] = []
        return d
    finally:
        conn.close()


@app.get("/api/export")
def export(
    q:                Optional[str]  = None,
    city:             Optional[str]  = None,
    zip_code:         Optional[str]  = None,
    category:         Optional[str]  = None,
    has_phone:        Optional[bool] = None,
    has_website:      Optional[bool] = None,
    no_web_with_email: bool          = False,
    no_web_no_email:   bool          = False,
    limit: int = 2000,
):
    conn       = get_conn()
    conditions = []
    params     = []

    if q:
        pct = f"%{q[:150]}%"
        conditions.append("(UPPER(title) LIKE UPPER(?) OR UPPER(city) LIKE UPPER(?) OR UPPER(category) LIKE UPPER(?))")
        params.extend([pct, pct, pct])
    if city:
        conditions.append("UPPER(city) LIKE UPPER(?)")
        params.append(f"%{city[:100]}%")
    if zip_code:
        conditions.append("zip_code LIKE ?")
        params.append(f"{zip_code[:10]}%")
    if category:
        conditions.append("UPPER(category) LIKE UPPER(?)")
        params.append(f"%{category[:150]}%")
    if has_phone is True:
        conditions.append("phone != '' AND phone IS NOT NULL")
    if has_website is True:
        conditions.append("url != '' AND url IS NOT NULL")
    if no_web_with_email:
        conditions.append("(url = '' OR url IS NULL)")
        conditions.append("contacts LIKE '%\"type\":\"Mail\"%'")
    if no_web_no_email:
        conditions.append("(url = '' OR url IS NULL)")
        conditions.append("(contacts NOT LIKE '%\"type\":\"Mail\"%')")

    where = " AND ".join(conditions) if conditions else "1=1"
    rows  = conn.execute(f"""
        SELECT title, category, phone, url, addr_street, city, zip_code,
               rating_value, rating_votes, contacts
        FROM companies WHERE {where}
        LIMIT {min(int(limit), 5000)}
    """, params).fetchall()
    cols = [d[0] for d in conn.description]
    conn.close()

    output = io.StringIO()
    w      = csv_mod.writer(output)
    w.writerow(["Nom", "Catégorie", "Téléphone", "Site web", "Adresse", "Ville", "Code postal",
                "Note", "Nb avis", "Emails"])
    for row in rows:
        d = dict(zip(cols, row))
        try:
            emails = "; ".join(c["value"] for c in json.loads(d.get("contacts") or "[]") if c.get("type") == "Mail")
        except Exception:
            emails = ""
        w.writerow([d["title"], d["category"], d["phone"], d["url"],
                    d["addr_street"], d["city"], d["zip_code"],
                    d["rating_value"], d["rating_votes"], emails])
    output.seek(0)
    return StreamingResponse(
        io.BytesIO(output.getvalue().encode("utf-8-sig")),
        media_type="text/csv",
        headers={"Content-Disposition": "attachment; filename=societies_export.csv"},
    )


# =============================================================================
# ENDPOINTS — GÉNÉRATION
# =============================================================================

@app.post("/api/generate/stream")
@limiter.limit("30/minute")
async def generate_stream_endpoint(request: Request, data: dict = Body(...)):
    """SSE streaming : génère une fiche et envoie les événements en temps réel."""
    title = data.get("title", "").strip()
    if not title:
        raise HTTPException(status_code=400, detail="title requis")
    return StreamingResponse(
        stream_generate(title, data),
        media_type="text/event-stream",
        headers={"Cache-Control": "no-cache", "X-Accel-Buffering": "no"},
    )


@app.get("/api/fiche/{title:path}")
def get_fiche_endpoint(title: str):
    """Récupère la fiche générée d'une entreprise."""
    fiche = get_fiche(title)
    if not fiche:
        return {"status": "none"}
    if fiche["status"] == "done":
        try:
            if fiche.get("qa_answered"):
                fiche["qa_answered"] = json.loads(fiche["qa_answered"])
            if fiche.get("qa_open"):
                fiche["qa_open"] = json.loads(fiche["qa_open"])
        except Exception:
            pass
        if fiche.get("generated_at"):
            fiche["date_fr"] = format_date_fr(fiche["generated_at"])
        fiche["open_questions"] = [q.replace("{nom}", title) for q in OPEN_QUESTIONS_TEMPLATE]
    try:
        company = get_company(title)
        fiche["company_info"] = {
            "category":    company.get("category", ""),
            "city":        company.get("city", ""),
            "zip_code":    company.get("zip_code", ""),
            "phone":       company.get("phone", ""),
            "url":         company.get("url", ""),
            "rating_value": company.get("rating_value"),
            "rating_votes": company.get("rating_votes"),
            "emails":      company.get("emails", []),
        }
    except Exception:
        fiche["company_info"] = None
    return fiche


@app.post("/api/generate")
@limiter.limit("30/minute")
async def generate_fiche(request: Request, data: GenerateRequest):
    """Génère les Q&R pour une entreprise via OpenAI."""
    title    = data.title.strip()
    existing = get_fiche(title)
    if existing and existing["status"] == "done":
        try:
            if existing.get("qa_answered"):
                existing["qa_answered"] = json.loads(existing["qa_answered"])
            if existing.get("qa_open"):
                existing["qa_open"] = json.loads(existing["qa_open"])
        except Exception:
            pass
        return existing

    save_fiche(title, "generating")
    prompt = GENERATION_PROMPT.format(
        nom=title,
        categorie=data.category or "Non renseigné",
        ville=data.city or "Non renseignée",
        code_postal=data.zip_code or "",
        note=f"{data.rating_value or '?'}/5 ({data.rating_votes or 0} avis)",
    )
    try:
        result = await call_openai(prompt)
        parsed = json.loads(result["text"])
        if not isinstance(parsed, dict) or "qa_answered" not in parsed:
            raise ValueError("Format inattendu")
        intro = parsed.get("intro", "")
        save_fiche(title, "done",
                   qa_answered=json.dumps(parsed["qa_answered"], ensure_ascii=False),
                   intro_text=intro,
                   model=result["model"],
                   completion_tokens=result["completion_tokens"])
        logger.info(f"Fiche générée : {title} — {result['completion_tokens']} tokens")
        return {
            "status":           "done",
            "qa_answered":      parsed["qa_answered"],
            "intro_text":       intro,
            "open_questions":   [q.replace("{nom}", title) for q in OPEN_QUESTIONS_TEMPLATE],
            "date_fr":          format_date_fr(datetime.now().strftime("%Y-%m-%d")),
            "model":            result["model"],
            "completion_tokens": result["completion_tokens"],
        }
    except Exception as e:
        logger.error(f"generate_fiche error for '{title}': {e}")
        save_fiche(title, "error", error=str(e))
        raise HTTPException(status_code=500, detail=str(e))


@app.post("/api/generate/batch")
@limiter.limit("10/minute")
async def generate_batch(request: Request, data: BatchRequest):
    """Génère les Q&R pour une liste d'entreprises en parallèle."""
    companies   = data.companies[:data.max]
    concurrency = data.concurrency

    results     = []
    to_generate = []
    for company in companies:
        title    = company.title.strip()
        existing = get_fiche(title)
        if existing and existing["status"] == "done":
            results.append({"title": title, "status": "already_done"})
        else:
            save_fiche(title, "generating")
            to_generate.append(company)

    semaphore = asyncio.Semaphore(concurrency)

    async def _generate_one(company: "CompanyItem") -> dict:
        title = company.title.strip()
        async with semaphore:
            prompt = GENERATION_PROMPT.format(
                nom=title,
                categorie=company.category or "Non renseigné",
                ville=company.city or "Non renseignée",
                code_postal=company.zip_code or "",
                note=f"{company.rating_value or '?'}/5",
            )
            try:
                result = await call_openai(prompt)
                parsed = json.loads(result["text"])
                if not isinstance(parsed, dict) or "qa_answered" not in parsed:
                    raise ValueError("Format inattendu")
                save_fiche(title, "done",
                           qa_answered=json.dumps(parsed["qa_answered"], ensure_ascii=False),
                           intro_text=parsed.get("intro", ""),
                           model=result["model"],
                           completion_tokens=result["completion_tokens"])
                return {"title": title, "status": "done",
                        "qa_answered_count": len(parsed["qa_answered"])}
            except Exception as e:
                logger.error(f"batch error for '{title}': {e}")
                save_fiche(title, "error", error=str(e))
                return {"title": title, "status": "error", "error": str(e)}

    parallel_results = await asyncio.gather(*[_generate_one(c) for c in to_generate])
    results.extend(parallel_results)

    done_count  = sum(1 for r in results if r["status"] == "done")
    error_count = sum(1 for r in results if r["status"] == "error")
    logger.info(f"Batch terminé : {done_count} ok / {error_count} erreurs")
    return {
        "results":      results,
        "total":        len(results),
        "done":         done_count,
        "errors":       error_count,
        "already_done": len(results) - done_count - error_count,
        "concurrency":  concurrency,
    }


# =============================================================================
# AUTO-GÉNÉRATION EN ARRIÈRE-PLAN (avec suivi par tête)
# =============================================================================

async def _auto_generate_loop():
    global auto_state, _head_pause_events
    concurrency = auto_state["concurrency"]
    batch_size  = auto_state["batch_size"]

    _head_pause_events = [asyncio.Event() for _ in range(concurrency)]
    for e in _head_pause_events:
        e.set()

    auto_state["heads"] = [
        {"id": i, "status": "idle", "title": "", "done": 0, "errors": 0}
        for i in range(concurrency)
    ]

    slot_queue: asyncio.Queue = asyncio.Queue()
    for i in range(concurrency):
        await slot_queue.put(i)

    async def _one(company: dict) -> str:
        title = company["title"].strip()
        slot  = await slot_queue.get()
        try:
            auto_state["heads"][slot]["status"] = "working"
            auto_state["heads"][slot]["title"]  = title

            while not _head_pause_events[slot].is_set():
                auto_state["heads"][slot]["status"] = "paused"
                await asyncio.sleep(0.3)
            auto_state["heads"][slot]["status"] = "working"

            prompt = GENERATION_PROMPT.format(
                nom=title,
                categorie=company.get("category") or "Non renseigné",
                ville=company.get("city") or "Non renseignée",
                code_postal=company.get("zip_code") or "",
                note=f"{company.get('rating_value') or '?'}/5",
            )
            result = await call_openai(prompt)
            parsed = json.loads(result["text"])
            if not isinstance(parsed, dict) or "qa_answered" not in parsed:
                raise ValueError("Format inattendu")
            save_fiche(title, "done",
                       qa_answered=json.dumps(parsed["qa_answered"], ensure_ascii=False),
                       intro_text=parsed.get("intro", ""),
                       model=result["model"],
                       completion_tokens=result["completion_tokens"])
            auto_state["heads"][slot]["done"] += 1
            return "done"
        except asyncio.CancelledError:
            raise
        except Exception as e:
            logger.error(f"auto_generate error for '{title}': {e}")
            save_fiche(title, "error", error=str(e))
            auto_state["heads"][slot]["errors"] += 1
            return "error"
        finally:
            auto_state["heads"][slot]["status"] = "idle"
            auto_state["heads"][slot]["title"]  = ""
            await slot_queue.put(slot)

    offset = auto_state["offset"]
    try:
        while auto_state["running"]:
            try:
                conn  = get_conn()
                total = conn.execute("SELECT COUNT(*) FROM companies").fetchone()[0]
                rows  = conn.execute(
                    "SELECT title, category, city, zip_code, rating_value "
                    "FROM companies LIMIT ? OFFSET ?",
                    [batch_size, offset]
                ).fetchall()
                conn.close()
            except asyncio.CancelledError:
                raise
            except Exception as e:
                logger.warning(f"auto_generate batch fetch error: {e}")
                await asyncio.sleep(5)
                continue

            auto_state["total"] = total
            if not rows:
                break

            titles       = [r[0] for r in rows]
            placeholders = ",".join("?" * len(titles))
            fc = sqlite3.connect(FICHES_DB)
            try:
                already = {row[0] for row in fc.execute(
                    f"SELECT company_title FROM fiches "
                    f"WHERE company_title IN ({placeholders}) "
                    f"AND status IN ('done','generating') AND deleted_at IS NULL",
                    titles
                ).fetchall()}
            finally:
                fc.close()

            to_generate = [
                {"title": r[0], "category": r[1], "city": r[2],
                 "zip_code": r[3], "rating_value": r[4]}
                for r in rows if r[0] not in already
            ]

            if to_generate:
                for c in to_generate:
                    save_fiche(c["title"], "generating")
                results = await asyncio.gather(*[_one(c) for c in to_generate])
                auto_state["done"]      += sum(1 for r in results if r == "done")
                auto_state["errors"]    += sum(1 for r in results if r == "error")
                auto_state["processed"] += len(to_generate)

            offset += batch_size
            auto_state["offset"]        = offset
            auto_state["last_activity"] = datetime.now().isoformat()
            await asyncio.sleep(0.5)

    except asyncio.CancelledError:
        pass
    finally:
        auto_state["running"] = False
        for h in auto_state.get("heads", []):
            h["status"] = "idle"
            h["title"]  = ""
        logger.info("Auto-génération terminée")


@app.post("/api/auto-generate/start")
async def auto_generate_start(data: AutoStartRequest):
    global _auto_task, auto_state
    if auto_state["running"]:
        return {"status": "already_running",
                "concurrency": auto_state["concurrency"],
                "processed":   auto_state["processed"],
                "done":        auto_state["done"]}
    auto_state.update({
        "running":       True,
        "concurrency":   data.concurrency,
        "batch_size":    data.batch_size,
        "processed":     0,
        "done":          0,
        "errors":        0,
        "offset":        data.resume_offset,
        "heads":         [],
        "started_at":    datetime.now().isoformat(),
        "last_activity": None,
    })
    _auto_task = asyncio.create_task(_auto_generate_loop())
    logger.info(f"Auto-génération démarrée : {data.concurrency} têtes, batch {data.batch_size}")
    return {"status": "started",
            "concurrency": auto_state["concurrency"],
            "batch_size":  auto_state["batch_size"]}


@app.post("/api/auto-generate/stop")
async def auto_generate_stop():
    global _auto_task, auto_state
    auto_state["running"] = False
    if _auto_task and not _auto_task.done():
        _auto_task.cancel()
        try:
            await _auto_task
        except (asyncio.CancelledError, Exception):
            pass
    logger.info(f"Auto-génération stoppée — {auto_state['done']} fiches générées")
    return {"status": "stopped",
            "processed": auto_state["processed"],
            "done":      auto_state["done"],
            "offset":    auto_state["offset"]}


@app.post("/api/auto-generate/head/{slot}/pause")
async def auto_generate_head_pause(slot: int):
    if slot < 0 or slot >= len(_head_pause_events):
        raise HTTPException(status_code=404, detail="Tête introuvable")
    _head_pause_events[slot].clear()
    if slot < len(auto_state.get("heads", [])):
        auto_state["heads"][slot]["status"] = "paused"
    return {"slot": slot, "status": "paused"}


@app.post("/api/auto-generate/head/{slot}/resume")
async def auto_generate_head_resume(slot: int):
    if slot < 0 or slot >= len(_head_pause_events):
        raise HTTPException(status_code=404, detail="Tête introuvable")
    _head_pause_events[slot].set()
    if slot < len(auto_state.get("heads", [])):
        auto_state["heads"][slot]["status"] = "working"
    return {"slot": slot, "status": "resumed"}


@app.get("/api/auto-generate/status")
def auto_generate_status():
    total = auto_state["total"] or db_state.get("rows", 0)
    pct   = round(auto_state["done"] / total * 100, 2) if total > 0 else 0
    return {
        "running":       auto_state["running"],
        "concurrency":   auto_state["concurrency"],
        "batch_size":    auto_state["batch_size"],
        "processed":     auto_state["processed"],
        "done":          auto_state["done"],
        "errors":        auto_state["errors"],
        "total":         total,
        "offset":        auto_state["offset"],
        "percent":       pct,
        "heads":         auto_state.get("heads", []),
        "started_at":    auto_state["started_at"],
        "last_activity": auto_state["last_activity"],
    }


# =============================================================================
# ENDPOINTS — FICHES (CRUD)
# =============================================================================

@app.get("/api/fiches/stats")
def fiches_stats():
    conn = sqlite3.connect(FICHES_DB)
    try:
        rows          = conn.execute("SELECT status, COUNT(*) FROM fiches WHERE deleted_at IS NULL GROUP BY status").fetchall()
        total_deleted = conn.execute("SELECT COUNT(*) FROM fiches WHERE deleted_at IS NOT NULL").fetchone()[0]
        result        = {r[0]: r[1] for r in rows}
        result["deleted"] = total_deleted
        return result
    finally:
        conn.close()


@app.get("/api/fiches")
def list_fiches(
    page:     int  = 1,
    per_page: int  = 30,
    q:        Optional[str] = None,
    deleted:  bool = False,
):
    """Liste paginée des fiches (actives ou supprimées)."""
    conn   = sqlite3.connect(FICHES_DB)
    conn.row_factory = sqlite3.Row
    try:
        where  = "deleted_at IS NOT NULL" if deleted else "deleted_at IS NULL AND status = 'done'"
        params = []
        if q:
            where += " AND LOWER(company_title) LIKE LOWER(?)"
            params.append(f"%{q}%")
        total  = conn.execute(f"SELECT COUNT(*) FROM fiches WHERE {where}", params).fetchone()[0]
        offset = (page - 1) * per_page
        rows   = conn.execute(
            f"SELECT id, company_title, status, model, completion_tokens, generated_at, deleted_at, "
            f"       LENGTH(qa_answered) as qa_a_len, LENGTH(qa_open) as qa_o_len "
            f"FROM fiches WHERE {where} ORDER BY generated_at DESC LIMIT ? OFFSET ?",
            params + [per_page, offset]
        ).fetchall()
        return {
            "results": [dict(r) for r in rows],
            "total":   total,
            "page":    page,
            "pages":   max(1, (total + per_page - 1) // per_page),
        }
    finally:
        conn.close()


@app.put("/api/fiche/{title:path}")
async def update_fiche(title: str, data: UpdateFicheRequest):
    """Modifie le contenu d'une fiche."""
    fiche = get_fiche(title)
    if not fiche:
        raise HTTPException(status_code=404, detail="Fiche non trouvée")

    updates = {}
    if data.qa_answered is not None:
        updates["qa_answered"] = json.dumps(data.qa_answered, ensure_ascii=False)
    if data.intro_text is not None:
        updates["intro_text"] = data.intro_text
    if data.open_answers is not None:
        updates["qa_open"] = json.dumps(data.open_answers, ensure_ascii=False)

    if updates:
        conn = sqlite3.connect(FICHES_DB)
        try:
            conn.execute("BEGIN")
            set_clause = ", ".join(f"{k}=?" for k in updates)
            conn.execute(
                f"UPDATE fiches SET {set_clause} WHERE company_title=?",
                list(updates.values()) + [title],
            )
            conn.commit()
        except Exception as e:
            conn.rollback()
            logger.error(f"update_fiche error for '{title}': {e}")
            raise HTTPException(status_code=500, detail="Erreur de mise à jour")
        finally:
            conn.close()
    return {"ok": True}


@app.delete("/api/fiche/{title:path}")
def delete_fiche(title: str):
    """Soft delete d'une fiche."""
    conn = sqlite3.connect(FICHES_DB)
    try:
        affected = conn.execute(
            "UPDATE fiches SET deleted_at=datetime('now') WHERE company_title=? AND deleted_at IS NULL",
            [title]
        ).rowcount
        conn.commit()
    finally:
        conn.close()
    if not affected:
        raise HTTPException(status_code=404, detail="Fiche non trouvée ou déjà supprimée")
    return {"ok": True}


@app.post("/api/fiche/{title:path}/restore")
def restore_fiche(title: str):
    """Restaure une fiche soft-supprimée."""
    conn = sqlite3.connect(FICHES_DB)
    try:
        affected = conn.execute(
            "UPDATE fiches SET deleted_at=NULL WHERE company_title=? AND deleted_at IS NOT NULL",
            [title]
        ).rowcount
        conn.commit()
    finally:
        conn.close()
    if not affected:
        raise HTTPException(status_code=404, detail="Fiche non trouvée ou déjà active")
    return {"ok": True}


# =============================================================================
# ENDPOINTS — LICENCE
# =============================================================================

@app.get("/api/license/status")
def license_status():
    return {
        "has_key":   bool(get_setting("license_hash")),
        "activated": get_setting("license_activated") == "true",
    }


@app.post("/api/license/generate")
def license_generate():
    """Génère une nouvelle clé de licence (affichée UNE seule fois)."""
    raw = "-".join(secrets.token_hex(2).upper() for _ in range(4))
    set_setting("license_hash",      _hash_key(raw))
    set_setting("license_activated", "false")
    logger.info("Nouvelle clé de licence générée")
    return {"key": raw}


@app.post("/api/license/verify")
def license_verify(data: LicenseVerifyRequest):
    """Vérifie une clé depuis le plugin WordPress (endpoint public)."""
    stored_hash = get_setting("license_hash")
    if not stored_hash:
        raise HTTPException(status_code=404, detail="Aucune licence configurée sur ce serveur")
    if not secrets.compare_digest(_hash_key(data.key.strip()), stored_hash):
        logger.warning("Tentative de vérification de licence invalide")
        raise HTTPException(status_code=403, detail="Clé de licence invalide")
    set_setting("license_activated", "true")
    return {"ok": True, "hash": _hash_key(data.key.strip())}


@app.get("/api/plugin/download")
def download_plugin():
    """Télécharge le plugin WordPress societies-connector en zip."""
    import zipfile
    import pathlib
    plugin_dir = pathlib.Path(__file__).parent / "wordpress" / "societies-connector"
    if not plugin_dir.exists():
        raise HTTPException(status_code=404, detail="Plugin introuvable sur ce serveur")
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, "w", zipfile.ZIP_DEFLATED) as zf:
        for f in plugin_dir.rglob("*"):
            if f.is_file():
                zf.write(f, f"societies-connector/{f.relative_to(plugin_dir)}")
    buf.seek(0)
    return StreamingResponse(
        buf,
        media_type="application/zip",
        headers={"Content-Disposition": "attachment; filename=societies-connector.zip"},
    )


# =============================================================================
# ENDPOINTS — SEO
# =============================================================================

@app.get("/api/seo/sector/{sector:path}")
def seo_sector(sector: str, limit: int = 20):
    conn = duckdb.connect(DB_PATH, read_only=True)
    try:
        rows = conn.execute("""
            SELECT title, category, city, zip_code, phone, url, rating_value, rating_votes
            FROM companies
            WHERE LOWER(category) = LOWER(?)
            ORDER BY rating_votes DESC NULLS LAST
            LIMIT ?
        """, [sector, min(int(limit), 200)]).fetchall()
        cols = ["title", "category", "city", "zip_code", "phone", "url", "rating_value", "rating_votes"]
        return {"sector": sector, "total": len(rows), "results": [dict(zip(cols, r)) for r in rows]}
    finally:
        conn.close()


@app.get("/api/seo/city/{city:path}")
def seo_city(city: str, limit: int = 20):
    conn = duckdb.connect(DB_PATH, read_only=True)
    try:
        rows = conn.execute("""
            SELECT title, category, city, zip_code, phone, url, rating_value, rating_votes
            FROM companies
            WHERE LOWER(city) = LOWER(?)
            ORDER BY rating_votes DESC NULLS LAST
            LIMIT ?
        """, [city, min(int(limit), 200)]).fetchall()
        cols = ["title", "category", "city", "zip_code", "phone", "url", "rating_value", "rating_votes"]
        return {"city": city, "total": len(rows), "results": [dict(zip(cols, r)) for r in rows]}
    finally:
        conn.close()


@app.get("/api/seo/top-sectors")
def seo_top_sectors(limit: int = 50):
    conn = duckdb.connect(DB_PATH, read_only=True)
    try:
        rows = conn.execute("""
            SELECT category, COUNT(*) as count
            FROM companies
            WHERE category IS NOT NULL AND category != ''
            GROUP BY category
            ORDER BY count DESC
            LIMIT ?
        """, [min(int(limit), 200)]).fetchall()
        return {"results": [{"sector": r[0], "count": r[1]} for r in rows]}
    finally:
        conn.close()


@app.get("/api/seo/top-cities")
def seo_top_cities(limit: int = 50):
    conn = duckdb.connect(DB_PATH, read_only=True)
    try:
        rows = conn.execute("""
            SELECT city, COUNT(*) as count
            FROM companies
            WHERE city IS NOT NULL AND city != ''
            GROUP BY city
            ORDER BY count DESC
            LIMIT ?
        """, [min(int(limit), 200)]).fetchall()
        return {"results": [{"city": r[0], "count": r[1]} for r in rows]}
    finally:
        conn.close()


# =============================================================================
# STATIC + SPA FALLBACK
# =============================================================================

app.mount("/static", StaticFiles(directory=str(STATIC_DIR)), name="static")


@app.get("/")
@app.get("/{full_path:path}")
def serve_spa(full_path: str = ""):
    if full_path.startswith("api/"):
        raise HTTPException(status_code=404)
    return FileResponse(str(STATIC_DIR / "index.html"))


if __name__ == "__main__":
    import uvicorn
    host = os.getenv("APP_HOST", "0.0.0.0")
    port = int(os.getenv("APP_PORT", "8090"))
    uvicorn.run(app, host=host, port=port, reload=False)
