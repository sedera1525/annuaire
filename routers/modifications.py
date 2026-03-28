"""
Societies — Modération des modifications de fiches
Soumission par les propriétaires + validation manuelle par l'admin.
"""
import logging
from pydantic import BaseModel

from fastapi import APIRouter, HTTPException

from services.fiches import (
    approve_modification, get_modifications, reject_modification, submit_modification,
)

logger = logging.getLogger("societies")
router = APIRouter(tags=["modifications"])


class ModificationIn(BaseModel):
    company_title: str
    field_name:    str           # 'intro_text' | 'open_answers'
    field_value:   str
    user_email:    str | None = None


class RejectIn(BaseModel):
    reason: str | None = None


@router.post("/modifications")
def create_modification(data: ModificationIn):
    """Soumet une demande de modification (statut : pending)."""
    if data.field_name not in ("intro_text", "open_answers"):
        raise HTTPException(status_code=400, detail="field_name invalide")
    mod_id = submit_modification(
        data.company_title, data.field_name, data.field_value, data.user_email
    )
    return {"id": mod_id, "status": "pending"}


@router.get("/modifications")
def list_modifications(
    status:        str | None = None,
    company_title: str | None = None,
    page:          int = 1,
    per_page:      int = 25,
):
    """Liste les modifications (filtrables par statut / entreprise)."""
    return get_modifications(status=status, company_title=company_title,
                             page=page, per_page=per_page)


@router.get("/modifications/pending-count")
def pending_count():
    """Nombre de modifications en attente — utilisé pour le badge admin."""
    result = get_modifications(status="pending", per_page=1)
    return {"count": result["total"]}


@router.put("/modifications/{mod_id}/approve")
def approve(mod_id: int):
    """Valide une modification et l'applique immédiatement à la fiche."""
    result = approve_modification(mod_id)
    if "error" in result:
        raise HTTPException(status_code=400, detail=result["error"])
    return result


@router.put("/modifications/{mod_id}/reject")
def reject(mod_id: int, data: RejectIn = RejectIn()):
    """Rejette une modification avec une raison optionnelle."""
    result = reject_modification(mod_id, data.reason)
    if "error" in result:
        raise HTTPException(status_code=400, detail=result["error"])
    return result
