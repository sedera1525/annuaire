"""
Societies — Endpoints CRUD fiches
"""
import json
import logging
import sqlite3
from typing import Optional

from fastapi import APIRouter, HTTPException

from core.config import FICHES_DB
from models import UpdateFicheRequest
from services.fiches import get_fiche, save_fiche

logger = logging.getLogger("societies")
router = APIRouter(tags=["fiches"])


@router.get("/fiches/stats")
def fiches_stats():
    conn = sqlite3.connect(FICHES_DB)
    try:
        rows          = conn.execute(
            "SELECT status, COUNT(*) FROM fiches WHERE deleted_at IS NULL GROUP BY status"
        ).fetchall()
        total_deleted = conn.execute(
            "SELECT COUNT(*) FROM fiches WHERE deleted_at IS NOT NULL"
        ).fetchone()[0]
        result           = {r[0]: r[1] for r in rows}
        result["deleted"] = total_deleted
        return result
    finally:
        conn.close()


@router.get("/fiches")
def list_fiches(
    page:           int  = 1,
    per_page:       int  = 30,
    q:              Optional[str] = None,
    deleted:        bool = False,
    include_errors: bool = False,
):
    conn = sqlite3.connect(FICHES_DB)
    conn.row_factory = sqlite3.Row
    try:
        if deleted:
            where = "deleted_at IS NOT NULL"
        elif include_errors:
            where = "deleted_at IS NULL AND status IN ('done','error')"
        else:
            where = "deleted_at IS NULL AND status = 'done'"
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
            params + [per_page, offset],
        ).fetchall()
        return {
            "results": [dict(r) for r in rows],
            "total":   total,
            "page":    page,
            "pages":   max(1, (total + per_page - 1) // per_page),
        }
    finally:
        conn.close()


@router.put("/fiche/{title:path}")
async def update_fiche(title: str, data: UpdateFicheRequest):
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


@router.delete("/fiche/{title:path}")
def delete_fiche(title: str):
    conn = sqlite3.connect(FICHES_DB)
    try:
        affected = conn.execute(
            "UPDATE fiches SET deleted_at=datetime('now') "
            "WHERE company_title=? AND deleted_at IS NULL",
            [title],
        ).rowcount
        conn.commit()
    finally:
        conn.close()
    if not affected:
        raise HTTPException(status_code=404, detail="Fiche non trouvée ou déjà supprimée")
    return {"ok": True}


@router.post("/fiche/{title:path}/restore")
def restore_fiche(title: str):
    conn = sqlite3.connect(FICHES_DB)
    try:
        affected = conn.execute(
            "UPDATE fiches SET deleted_at=NULL "
            "WHERE company_title=? AND deleted_at IS NOT NULL",
            [title],
        ).rowcount
        conn.commit()
    finally:
        conn.close()
    if not affected:
        raise HTTPException(status_code=404, detail="Fiche non trouvée ou déjà active")
    return {"ok": True}
