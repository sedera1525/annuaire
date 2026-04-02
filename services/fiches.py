"""
Societies — Accès SQLite : fiches, settings, sessions
"""
import hashlib
import hmac
import json
import logging
import sqlite3
import time

from core.config import FICHES_DB, OPENAI_API_KEY, SECRET_KEY

logger = logging.getLogger("societies")


# =============================================================================
# MIGRATIONS SQLITE VERSIONNÉES
# Inspiré de Laravel migrations — chaque version est appliquée une seule fois.
# Pour ajouter une migration : append un tuple (version, sql) dans _MIGRATIONS.
# =============================================================================

_MIGRATIONS: list[tuple[int, str]] = [
    (1, """
        CREATE TABLE IF NOT EXISTS schema_migrations (
            version    INTEGER PRIMARY KEY,
            applied_at TEXT NOT NULL DEFAULT (datetime('now'))
        );
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
        );
        CREATE TABLE IF NOT EXISTS settings (
            key   TEXT PRIMARY KEY,
            value TEXT
        );
        CREATE TABLE IF NOT EXISTS sessions (
            token      TEXT PRIMARY KEY,
            created_at REAL NOT NULL
        );
    """),
    (2, "ALTER TABLE fiches ADD COLUMN intro_text TEXT;"),
    (3, """
        CREATE TABLE IF NOT EXISTS auto_jobs (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            started_at  TEXT,
            stopped_at  TEXT,
            offset      INTEGER DEFAULT 0,
            done        INTEGER DEFAULT 0,
            errors      INTEGER DEFAULT 0,
            status      TEXT DEFAULT 'running',
            concurrency INTEGER DEFAULT 6,
            batch_size  INTEGER DEFAULT 50
        );
    """),
    (4, "ALTER TABLE sessions ADD COLUMN username TEXT DEFAULT '';"),
    (5, "ALTER TABLE sessions ADD COLUMN last_activity REAL DEFAULT 0;"),
    (6, "ALTER TABLE fiches ADD COLUMN bonus_text TEXT;"),
    (7, """
        CREATE TABLE IF NOT EXISTS fiche_modifications (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            company_title    TEXT NOT NULL,
            user_email       TEXT,
            field_name       TEXT NOT NULL,
            field_value      TEXT NOT NULL,
            status           TEXT DEFAULT 'pending',
            submitted_at     TEXT DEFAULT (datetime('now')),
            reviewed_at      TEXT,
            rejection_reason TEXT
        );
    """),
    (8, """
        CREATE TABLE IF NOT EXISTS subscription_packs (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            name         TEXT NOT NULL,
            slug         TEXT UNIQUE NOT NULL,
            price_ht     REAL NOT NULL,
            color        TEXT DEFAULT '#10b981',
            description  TEXT,
            features     TEXT,
            wc_product_id INTEGER,
            created_at   TEXT DEFAULT (datetime('now')),
            updated_at   TEXT DEFAULT (datetime('now'))
        );
        INSERT OR IGNORE INTO subscription_packs (name, slug, price_ht, color, description, features) VALUES
        ('Pack Essentiel', 'pack-essentiel', 29.0, '#10b981',
         'Une présence en ligne claire et professionnelle',
         '["Réponses aux 6 questions clés","Fiche entreprise complète","Présentation de votre activité"]'),
        ('Pack Visibilité', 'pack-visibilite', 49.0, '#3b82f6',
         'Renforcez votre crédibilité et donnez envie de vous contacter',
         '["Tout Pack Essentiel","Badge Entreprise vérifiée par TOPsocietes.com","Produits et services (jusqu''à 10)","Zone d''intervention","Site web","Intervention rapide"]'),
        ('Pack Premium', 'pack-premium', 69.0, '#8b5cf6',
         'Démarquez-vous clairement et inspirez un maximum de confiance',
         '["Tout Pack Visibilité","Badge Entreprise conseillée par TOPsocietes.com","Dépannage urgent","Devis gratuit","Artisan ponctuel et soigneux","Certifié RGE","Type de projets (Maison / Appartement / Commerce)","Marques (jusqu''à 10)","Compteur de visite de cette page"]');
    """),
]


def _run_migrations(conn: sqlite3.Connection) -> None:
    """Applique les migrations manquantes dans l'ordre."""
    conn.execute("""
        CREATE TABLE IF NOT EXISTS schema_migrations (
            version    INTEGER PRIMARY KEY,
            applied_at TEXT NOT NULL DEFAULT (datetime('now'))
        )
    """)
    conn.commit()
    applied = {row[0] for row in conn.execute("SELECT version FROM schema_migrations").fetchall()}
    for version, sql in _MIGRATIONS:
        if version in applied:
            continue
        try:
            conn.executescript(sql)
            conn.execute(
                "INSERT OR IGNORE INTO schema_migrations(version) VALUES (?)", [version]
            )
            conn.commit()
            logger.info(f"Migration SQLite v{version} appliquée")
        except Exception as e:
            # ALTER TABLE échoue si la colonne existe déjà (bases existantes) — on ignore
            if "duplicate column" in str(e).lower() or "already exists" in str(e).lower():
                conn.execute(
                    "INSERT OR IGNORE INTO schema_migrations(version) VALUES (?)", [version]
                )
                conn.commit()
            else:
                logger.error(f"Migration SQLite v{version} échouée : {e}")


def init_fiches_db() -> None:
    conn = sqlite3.connect(FICHES_DB)
    try:
        _run_migrations(conn)
        # Purge des sessions inactives depuis plus de 15 min
        conn.execute(
            "DELETE FROM sessions WHERE last_activity > 0 AND last_activity < ?",
            [time.time() - 900],
        )
        conn.commit()
    finally:
        conn.close()


def get_setting(key: str) -> str | None:
    conn = sqlite3.connect(FICHES_DB)
    try:
        row = conn.execute("SELECT value FROM settings WHERE key=?", [key]).fetchone()
        return row[0] if row else None
    finally:
        conn.close()


def set_setting(key: str, value: str) -> None:
    conn = sqlite3.connect(FICHES_DB)
    try:
        conn.execute(
            "INSERT OR REPLACE INTO settings(key,value) VALUES(?,?)", [key, value]
        )
        conn.commit()
    finally:
        conn.close()


def get_openai_key() -> str:
    """
    Récupère la clé OpenAI depuis la table settings (priorité) ou l'env.
    Inspiré de RealeseSeo ApiKeyService — permet de changer la clé sans redéployer.
    """
    try:
        db_key = get_setting("openai_api_key")
        if db_key:
            return db_key
    except Exception:
        pass
    return OPENAI_API_KEY


def _hash_key(raw: str) -> str:
    """HMAC-SHA256 avec SECRET_KEY — résistant aux rainbow tables."""
    return hmac.new(SECRET_KEY.encode(), raw.encode(), hashlib.sha256).hexdigest()


def get_fiche(title: str) -> dict | None:
    conn = sqlite3.connect(FICHES_DB)
    conn.row_factory = sqlite3.Row
    try:
        row = conn.execute(
            "SELECT * FROM fiches WHERE company_title = ?", [title]
        ).fetchone()
        return dict(row) if row else None
    finally:
        conn.close()


def save_auto_job(concurrency: int, batch_size: int, offset: int = 0) -> int:
    """Crée un enregistrement de job auto-generation et retourne son id."""
    conn = sqlite3.connect(FICHES_DB)
    try:
        cur = conn.execute(
            "INSERT INTO auto_jobs(started_at, offset, done, errors, status, concurrency, batch_size) "
            "VALUES (datetime('now'), ?, 0, 0, 'running', ?, ?)",
            [offset, concurrency, batch_size],
        )
        conn.commit()
        return cur.lastrowid
    finally:
        conn.close()


def update_auto_job(job_id: int, offset: int, done: int, errors: int, status: str = "running") -> None:
    """Met à jour l'état d'un job auto-generation (appelé après chaque batch)."""
    conn = sqlite3.connect(FICHES_DB)
    try:
        stopped_at = "datetime('now')" if status != "running" else "NULL"
        conn.execute(
            f"UPDATE auto_jobs SET offset=?, done=?, errors=?, status=?, "
            f"stopped_at=CASE WHEN ? != 'running' THEN datetime('now') ELSE stopped_at END "
            f"WHERE id=?",
            [offset, done, errors, status, status, job_id],
        )
        conn.commit()
    finally:
        conn.close()


def get_last_auto_job() -> dict | None:
    """Retourne le dernier job pour permettre la reprise après crash."""
    conn = sqlite3.connect(FICHES_DB)
    conn.row_factory = sqlite3.Row
    try:
        row = conn.execute(
            "SELECT * FROM auto_jobs ORDER BY id DESC LIMIT 1"
        ).fetchone()
        return dict(row) if row else None
    finally:
        conn.close()


def save_fiche(
    title: str,
    status: str,
    qa_answered: str = None,
    qa_open: str = None,
    intro_text: str = None,
    bonus_text: str = None,
    model: str = None,
    completion_tokens: int = 0,
    error: str = None,
) -> None:
    conn = sqlite3.connect(FICHES_DB)
    try:
        conn.execute("BEGIN")
        conn.execute("""
            INSERT INTO fiches
                (company_title, status, qa_answered, qa_open, intro_text, bonus_text,
                 model, completion_tokens, generated_at, error)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, datetime('now'), ?)
            ON CONFLICT(company_title) DO UPDATE SET
                status=excluded.status,
                qa_answered=excluded.qa_answered,
                qa_open=excluded.qa_open,
                intro_text=excluded.intro_text,
                bonus_text=excluded.bonus_text,
                model=excluded.model,
                completion_tokens=excluded.completion_tokens,
                generated_at=excluded.generated_at,
                error=excluded.error
        """, [title, status, qa_answered, qa_open, intro_text, bonus_text,
              model, completion_tokens, error])
        conn.commit()
    except Exception as e:
        conn.rollback()
        logger.error(f"save_fiche error for '{title}': {e}")
        raise
    finally:
        conn.close()


# =============================================================================
# MODÉRATION DES MODIFICATIONS
# =============================================================================

def submit_modification(company_title: str, field_name: str, field_value: str,
                        user_email: str | None = None) -> int:
    """Enregistre une demande de modification en attente de validation."""
    conn = sqlite3.connect(FICHES_DB)
    try:
        cur = conn.execute(
            "INSERT INTO fiche_modifications(company_title, user_email, field_name, field_value) "
            "VALUES (?, ?, ?, ?)",
            [company_title, user_email, field_name, field_value],
        )
        conn.commit()
        return cur.lastrowid
    finally:
        conn.close()


def get_modifications(status: str | None = None, company_title: str | None = None,
                      page: int = 1, per_page: int = 25) -> dict:
    conn = sqlite3.connect(FICHES_DB)
    conn.row_factory = sqlite3.Row
    try:
        where, params = [], []
        if status:
            where.append("status = ?"); params.append(status)
        if company_title:
            where.append("company_title = ?"); params.append(company_title)
        clause = "WHERE " + " AND ".join(where) if where else ""
        total  = conn.execute(
            f"SELECT COUNT(*) FROM fiche_modifications {clause}", params
        ).fetchone()[0]
        offset = (page - 1) * per_page
        rows   = conn.execute(
            f"SELECT * FROM fiche_modifications {clause} ORDER BY submitted_at DESC LIMIT ? OFFSET ?",
            params + [per_page, offset],
        ).fetchall()
        return {"total": total, "page": page, "per_page": per_page,
                "results": [dict(r) for r in rows]}
    finally:
        conn.close()


def approve_modification(mod_id: int) -> dict:
    """Valide une modification et l'applique à la fiche."""
    conn = sqlite3.connect(FICHES_DB)
    conn.row_factory = sqlite3.Row
    try:
        mod = conn.execute(
            "SELECT * FROM fiche_modifications WHERE id = ?", [mod_id]
        ).fetchone()
        if not mod:
            return {"error": "Modification introuvable"}
        mod = dict(mod)
        if mod["status"] != "pending":
            return {"error": f"Modification déjà traitée (statut : {mod['status']})"}

        # Appliquer la modification sur la fiche
        field = mod["field_name"]
        value = mod["field_value"]
        if field == "intro_text":
            conn.execute(
                "UPDATE fiches SET intro_text = ? WHERE company_title = ?",
                [value, mod["company_title"]],
            )
        elif field == "open_answers":
            conn.execute(
                "UPDATE fiches SET qa_open = ? WHERE company_title = ?",
                [value, mod["company_title"]],
            )

        conn.execute(
            "UPDATE fiche_modifications SET status = 'approved', reviewed_at = datetime('now') WHERE id = ?",
            [mod_id],
        )
        conn.commit()
        return {"ok": True, "user_email": mod["user_email"], "company_title": mod["company_title"]}
    finally:
        conn.close()


def reject_modification(mod_id: int, reason: str | None = None) -> dict:
    """Rejette une modification."""
    conn = sqlite3.connect(FICHES_DB)
    try:
        affected = conn.execute(
            "UPDATE fiche_modifications SET status = 'rejected', reviewed_at = datetime('now'), "
            "rejection_reason = ? WHERE id = ? AND status = 'pending'",
            [reason, mod_id],
        ).rowcount
        conn.commit()
    finally:
        conn.close()
    if not affected:
        return {"error": "Modification introuvable ou déjà traitée"}
    return {"ok": True}
