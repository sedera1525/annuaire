"""
Societies — Import CSV de fiches pré-générées vers fiches.db
"""
import csv
import io
import json
import logging
import sqlite3
from datetime import datetime

from fastapi import APIRouter, Depends, HTTPException, UploadFile, File

from core.auth import require_admin
from core.config import FICHES_DB

logger = logging.getLogger("societies")
router = APIRouter(tags=["import"])

# Colonnes CSV acceptées (les autres sont ignorées silencieusement)
_FIELDS = {"company_title", "intro_text", "bonus_text", "qa_answered", "qa_open"}


def _normalize_json(value: str, field: str) -> str | None:
    """Valide et renvoie la valeur JSON telle quelle, ou None si vide/invalide."""
    if not value or not value.strip():
        return None
    try:
        json.loads(value)
        return value.strip()
    except json.JSONDecodeError:
        logger.warning("Import CSV : JSON invalide pour %s — valeur ignorée", field)
        return None


@router.post("/import/csv", dependencies=[Depends(require_admin)])
async def import_csv(file: UploadFile = File(...)):
    """
    Importe des fiches pré-générées depuis un fichier CSV.

    Colonnes attendues : company_title (obligatoire), intro_text, bonus_text,
                         qa_answered (JSON), qa_open (JSON).
    Les fiches existantes (même company_title) sont ignorées.
    """
    if not file.filename or not file.filename.lower().endswith(".csv"):
        raise HTTPException(status_code=400, detail="Fichier CSV requis (.csv)")

    content = await file.read()
    try:
        text = content.decode("utf-8-sig")   # gère le BOM Excel
    except UnicodeDecodeError:
        text = content.decode("latin-1")

    reader = csv.DictReader(io.StringIO(text))
    if not reader.fieldnames or "company_title" not in reader.fieldnames:
        raise HTTPException(
            status_code=422,
            detail="Colonne 'company_title' manquante dans le CSV",
        )

    inserted = 0
    skipped  = 0
    errors   = 0
    now      = datetime.utcnow().strftime("%Y-%m-%d %H:%M:%S")

    conn = sqlite3.connect(FICHES_DB)
    try:
        for row in reader:
            title = (row.get("company_title") or "").strip()
            if not title:
                errors += 1
                continue

            existing = conn.execute(
                "SELECT id FROM fiches WHERE company_title = ?", [title]
            ).fetchone()
            if existing:
                skipped += 1
                continue

            try:
                conn.execute(
                    """
                    INSERT INTO fiches
                        (company_title, status, intro_text, bonus_text,
                         qa_answered, qa_open, generated_at)
                    VALUES (?, 'done', ?, ?, ?, ?, ?)
                    """,
                    [
                        title,
                        (row.get("intro_text") or "").strip() or None,
                        (row.get("bonus_text") or "").strip() or None,
                        _normalize_json(row.get("qa_answered", ""), "qa_answered"),
                        _normalize_json(row.get("qa_open", ""),     "qa_open"),
                        now,
                    ],
                )
                inserted += 1
            except sqlite3.Error as e:
                logger.error("Import CSV : erreur insertion '%s' : %s", title, e)
                errors += 1

        conn.commit()
    finally:
        conn.close()

    logger.info("Import CSV terminé : %d insérées, %d ignorées, %d erreurs", inserted, skipped, errors)
    return {"inserted": inserted, "skipped": skipped, "errors": errors}
