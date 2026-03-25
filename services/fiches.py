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
    model: str = None,
    completion_tokens: int = 0,
    error: str = None,
) -> None:
    conn = sqlite3.connect(FICHES_DB)
    try:
        conn.execute("BEGIN")
        conn.execute("""
            INSERT INTO fiches
                (company_title, status, qa_answered, qa_open, intro_text,
                 model, completion_tokens, generated_at, error)
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
        """, [title, status, qa_answered, qa_open, intro_text,
              model, completion_tokens, error])
        conn.commit()
    except Exception as e:
        conn.rollback()
        logger.error(f"save_fiche error for '{title}': {e}")
        raise
    finally:
        conn.close()
