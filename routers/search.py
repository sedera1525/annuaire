"""
Societies — Endpoints recherche, export, statut DB
Phase C : cache dict+TTL pour les recherches fréquentes
"""
import csv as csv_mod
import io
import json
import re
import time
from typing import Any, Optional

import json as _json

from fastapi import APIRouter, Request

import sqlite3

from core.config import FICHES_DB, REDIS_URL
from core.db import db_state, fetch_company, get_conn

router = APIRouter(tags=["search"])

# =============================================================================
# CACHE — Redis si REDIS_URL défini, sinon dict+TTL en mémoire (fallback)
# Inspiré de RealeseSeo : Cache::remember() / spatie/responsecache
# =============================================================================
_CACHE_TTL  = 300  # 5 minutes

# Tentative de connexion Redis (import optionnel)
_redis: Any = None
if REDIS_URL:
    try:
        import redis as _redis_lib
        _r = _redis_lib.from_url(REDIS_URL, decode_responses=True, socket_connect_timeout=2)
        _r.ping()
        _redis = _r
    except Exception:
        _redis = None  # Fallback silencieux vers dict

# Fallback dict+TTL
_cache: dict[str, tuple[Any, float]] = {}


def _cache_get(key: str) -> Any:
    if _redis:
        try:
            raw = _redis.get(f"societies:{key}")
            return _json.loads(raw) if raw else None
        except Exception:
            pass  # Fallback vers dict si Redis devient indisponible
    entry = _cache.get(key)
    if entry and time.time() - entry[1] < _CACHE_TTL:
        return entry[0]
    return None


def _build_q_conditions(q: str, conditions: list, params: list) -> None:
    """
    Recherche multi-mots avec normalisation des apostrophes et tirets.
    "L'Atelier Locavore" → ["Atelier", "Locavore"] → AND sur chaque mot dans
    title/city/category. Gère les apostrophes droites et typographiques.
    """
    # Remplace apostrophes (droit + typographique) et tirets par des espaces
    normalized = re.sub(r"['''’ʼ\-]", " ", q[:150])
    words = [w for w in normalized.split() if len(w) >= 2][:6]

    if not words:
        # Fallback : phrase entière si la normalisation a tout supprimé
        pct = f"%{q[:150]}%"
        conditions.append("(UPPER(title) LIKE UPPER(?) OR UPPER(city) LIKE UPPER(?) OR UPPER(category) LIKE UPPER(?))")
        params.extend([pct, pct, pct])
        return

    if len(words) == 1:
        pct = f"%{words[0]}%"
        conditions.append("(UPPER(title) LIKE UPPER(?) OR UPPER(city) LIKE UPPER(?) OR UPPER(category) LIKE UPPER(?))")
        params.extend([pct, pct, pct])
    else:
        # Chaque mot doit matcher dans au moins un des trois champs
        word_conds = []
        for word in words:
            pct = f"%{word}%"
            word_conds.append("(UPPER(title) LIKE UPPER(?) OR UPPER(city) LIKE UPPER(?) OR UPPER(category) LIKE UPPER(?))")
            params.extend([pct, pct, pct])
        conditions.append("(" + " AND ".join(word_conds) + ")")


def _cache_set(key: str, value: Any) -> None:
    if _redis:
        try:
            _redis.setex(f"societies:{key}", _CACHE_TTL, _json.dumps(value, ensure_ascii=False))
            return
        except Exception:
            pass  # Fallback vers dict
    _cache[key] = (value, time.time())
    if len(_cache) > 1000:
        now = time.time()
        expired = [k for k, (_, t) in _cache.items() if now - t > _CACHE_TTL]
        for k in expired:
            del _cache[k]


# =============================================================================
# ENDPOINTS
# =============================================================================

@router.get("/status")
def get_status():
    return db_state


@router.get("/healthz")
def healthz():
    """Endpoint Docker HEALTHCHECK — répond 200 si l'app est prête, 503 sinon."""
    import sqlite3 as _sqlite3
    from core.config import FICHES_DB as _FICHES_DB
    checks: dict = {"app": "ok", "duckdb": "ok", "sqlite": "ok"}
    status = 200

    if not db_state.get("ready"):
        checks["duckdb"] = "not_ready"
        status = 503

    try:
        c = _sqlite3.connect(_FICHES_DB, timeout=2)
        c.execute("SELECT 1 FROM sqlite_master LIMIT 1")
        c.close()
    except Exception:
        checks["sqlite"] = "error"
        status = 503

    from fastapi.responses import JSONResponse
    return JSONResponse(content=checks, status_code=status)


@router.get("/categories")
def list_categories():
    cached = _cache_get("categories")
    if cached:
        return cached
    conn = get_conn()
    try:
        rows = conn.execute("""
            SELECT category, COUNT(*) AS cnt
            FROM companies
            WHERE category IS NOT NULL AND category != ''
            GROUP BY category ORDER BY cnt DESC LIMIT 100
        """).fetchall()
        result = [{"category": r[0], "count": r[1]} for r in rows]
        _cache_set("categories", result)
        return result
    finally:
        conn.close()


@router.get("/cities")
def list_cities(q: Optional[str] = None):
    cache_key = f"cities:{q or ''}"
    cached = _cache_get(cache_key)
    if cached:
        return cached
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
            GROUP BY city ORDER BY cnt DESC LIMIT 50
        """, params).fetchall()
        result = [{"city": r[0], "count": r[1]} for r in rows]
        _cache_set(cache_key, result)
        return result
    finally:
        conn.close()


@router.get("/search")
def search(
    q:                 Optional[str]  = None,
    city:              Optional[str]  = None,
    zip_code:          Optional[str]  = None,
    category:          Optional[str]  = None,
    has_phone:         Optional[bool] = None,
    has_website:       Optional[bool] = None,
    no_web_with_email: bool           = False,
    no_web_no_email:   bool           = False,
    page:              int            = 1,
    per_page:          int            = 50,
    sort_by:           str            = "rating",
    only_with_fiche:   bool           = False,
):
    cache_key = (
        f"search:{q}:{city}:{zip_code}:{category}:{has_phone}:{has_website}:"
        f"{no_web_with_email}:{no_web_no_email}:{page}:{per_page}:{sort_by}:{only_with_fiche}"
    )
    cached = _cache_get(cache_key)
    if cached:
        return cached

    conn       = get_conn()
    conditions = []
    params     = []

    if q:
        _build_q_conditions(q, conditions, params)
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
    # Score bayésien : (note × avis) / (avis + 10) — pénalise les entreprises avec peu d'avis
    _weighted = "(CASE WHEN rating_votes > 0 THEN (rating_value * rating_votes) / (CAST(rating_votes AS FLOAT) + 10) ELSE 0 END) DESC NULLS LAST, rating_votes DESC NULLS LAST"
    order  = {
        "rating": _weighted,
        "votes":  "rating_votes DESC NULLS LAST",
        "name":   "title ASC",
        "city":   "city ASC",
    }.get(sort_by, _weighted)

    SELECT_COLS = """title, category, phone, url, domain,
               addr_street, city, zip_code, region,
               rating_value, rating_votes,
               contacts, logo, snippet, is_claimed,
               latitude, longitude, address_full"""

    t0 = time.time()
    if only_with_fiche:
        # Charge les titres générés depuis fiches.db (SQLite)
        try:
            fdb  = sqlite3.connect(FICHES_DB)
            done = {r[0] for r in fdb.execute(
                "SELECT company_title FROM fiches WHERE status='done' AND deleted_at IS NULL"
            ).fetchall()}
            fdb.close()
        except Exception:
            done = set()

        # Surcharge pour avoir assez de candidats après filtrage
        candidate_limit = max(per_page * 8, 200)
        rows_all = conn.execute(f"""
            SELECT {SELECT_COLS}
            FROM companies WHERE {where}
            ORDER BY {order}
            LIMIT {candidate_limit} OFFSET {offset}
        """, params).fetchall()
        cols = [d[0] for d in conn.description]

        # Total précis pour les recherches filtrées (ville/catégorie/zip) — sinon approximation globale
        if conditions:
            try:
                count_rows = conn.execute(
                    f"SELECT title FROM companies WHERE {where} LIMIT 3000", params
                ).fetchall()
                total = sum(1 for r in count_rows if r[0] in done)
            except Exception:
                total = len(done)
        else:
            total = len(done)

        conn.close()
        rows = [r for r in rows_all if r[0] in done][:per_page]
    else:
        try:
            total = conn.execute(f"SELECT COUNT(*) FROM companies WHERE {where}", params).fetchone()[0]
        except Exception:
            total = 0
        rows = conn.execute(f"""
            SELECT {SELECT_COLS}
            FROM companies WHERE {where}
            ORDER BY {order}
            LIMIT {int(per_page)} OFFSET {int(offset)}
        """, params).fetchall()
        cols = [d[0] for d in conn.description]
        conn.close()

    elapsed = round(time.time() - t0, 3)

    results = []
    for row in rows:
        d = dict(zip(cols, row))
        try:
            contacts_data = json.loads(d.get("contacts") or "[]")
            d["emails"]   = [c["value"] for c in contacts_data if c.get("type") == "Mail"]
        except Exception:
            d["emails"] = []
        results.append(d)

    result = {
        "results":  results,
        "total":    total,
        "page":     page,
        "per_page": per_page,
        "pages":    max(1, (total + per_page - 1) // per_page) if total > 0 else 1,
        "elapsed":  elapsed,
    }
    _cache_set(cache_key, result)
    return result


@router.get("/company/by-slug/{slug:path}")
def get_company_by_slug(slug: str):
    """
    Trouve une entreprise par son slug WP (sanitize_title).
    Utilisé par le handler 404 → création de page à la demande.
    Gère les noms avec &, accents, tirets multiples, etc.
    Ex : 'jack-jones' → 'JACK & JONES', 'cafe-de-la-paix' → 'Café de la Paix'
    """
    import re
    import unicodedata as _ud

    if not slug or len(slug) < 2 or len(slug) > 200:
        from fastapi import HTTPException
        raise HTTPException(status_code=400, detail="Slug invalide")

    def to_slug(s: str) -> str:
        """Approximation de WordPress sanitize_title en Python."""
        s = s.lower().strip()
        s = _ud.normalize("NFKD", s).encode("ascii", "ignore").decode("ascii")
        s = re.sub(r"[^a-z0-9]+", "-", s)
        return s.strip("-")

    # Construit le pattern LIKE depuis le slug :
    # 'jack-jones' → '%jack%jones%'  /  'cafe-de-la-paix' → '%cafe%de%la%paix%'
    parts   = [p for p in re.split(r"-+", slug) if p]
    pattern = "%" + "%".join(parts) + "%"

    conn = get_conn()
    try:
        rows = conn.execute(
            "SELECT title, city, category, zip_code FROM companies "
            "WHERE LOWER(title) LIKE LOWER(?) LIMIT 30",
            [pattern]
        ).fetchall()
        for row in rows:
            if to_slug(row[0]) == slug:
                return {"title": row[0], "city": row[1], "category": row[2], "zip_code": row[3]}
        return None
    finally:
        conn.close()


@router.get("/company/{title:path}")
def get_company(title: str):
    company = fetch_company(title)
    if not company:
        from fastapi import HTTPException
        raise HTTPException(status_code=404, detail="Entreprise non trouvée")
    return company


@router.get("/export")
def export(
    q:                 Optional[str]  = None,
    city:              Optional[str]  = None,
    zip_code:          Optional[str]  = None,
    category:          Optional[str]  = None,
    has_phone:         Optional[bool] = None,
    has_website:       Optional[bool] = None,
    no_web_with_email: bool           = False,
    no_web_no_email:   bool           = False,
    limit: int = 2000,
):
    from fastapi.responses import StreamingResponse
    conn       = get_conn()
    conditions = []
    params     = []

    if q:
        _build_q_conditions(q, conditions, params)
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
    w.writerow(["Nom", "Catégorie", "Téléphone", "Site web", "Adresse",
                "Ville", "Code postal", "Note", "Nb avis", "Emails"])
    for row in rows:
        d = dict(zip(cols, row))
        try:
            emails = "; ".join(
                c["value"] for c in json.loads(d.get("contacts") or "[]")
                if c.get("type") == "Mail"
            )
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
