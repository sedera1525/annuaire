"""
Societies — Gestion de l'état DuckDB, connexions et fetch entreprise
"""
import json
import logging
import os
import threading

import duckdb
from fastapi import HTTPException

from .config import CSV_PATH, DB_PATH

logger = logging.getLogger("societies")

db_state: dict = {
    "ready":        False,
    "initializing": False,
    "message":      "En attente d'initialisation...",
    "rows":         0,
    "progress":     0,
}
_db_lock = threading.Lock()


def get_conn():
    if db_state["ready"] and os.path.exists(DB_PATH):
        return duckdb.connect(DB_PATH, read_only=True)
    raise HTTPException(status_code=503, detail="Base de données non disponible")


def fetch_company(title: str) -> dict | None:
    """Cherche une entreprise dans DuckDB et retourne ses données enrichies."""
    try:
        conn = get_conn()
        try:
            row = conn.execute(
                "SELECT * FROM companies WHERE title = ? LIMIT 1", [title]
            ).fetchone()
            if not row:
                return None
            cols = [d[0] for d in conn.description]
            d = dict(zip(cols, row))
            try:
                contacts_data    = json.loads(d.get("contacts") or "[]")
                d["emails"]       = [c["value"] for c in contacts_data if c.get("type") == "Mail"]
                d["phones_extra"] = [c["value"] for c in contacts_data if c.get("type") == "Telephone"]
            except Exception:
                d["emails"]       = []
                d["phones_extra"] = []
            return d
        finally:
            conn.close()
    except HTTPException:
        return None


def init_db() -> None:
    with _db_lock:
        if db_state["initializing"]:
            return
        db_state["initializing"] = True

    db_state["message"]  = "Chargement du fichier CSV dans la base de données..."
    db_state["progress"] = 5
    logger.info("Initialisation DuckDB démarrée")
    try:
        conn     = duckdb.connect(DB_PATH)
        existing = conn.execute(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_name='companies'"
        ).fetchone()[0]

        if not existing:
            db_state["message"]  = "Création de la table (opération unique ~3 min)..."
            db_state["progress"] = 15
            conn.execute(f"""
                CREATE TABLE companies AS
                SELECT
                    title, description, category,
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
                    ignore_errors=True, quote='"', escape='"', header=True
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
        db_state.update({
            "ready": True, "rows": rows, "progress": 100,
            "message": f"Base prête — {rows:,} entreprises",
        })
        # Phase C — persiste le nombre de lignes pour éviter une reconnexion au prochain démarrage
        try:
            from services.fiches import set_setting
            set_setting("db_rows", str(rows))
        except Exception:
            pass
        logger.info(f"DuckDB prête — {rows:,} entreprises")
    except Exception as e:
        logger.error(f"Erreur init DuckDB : {e}")
        db_state.update({"message": f"Erreur: {e}", "ready": False, "progress": 0})
    finally:
        db_state["initializing"] = False
