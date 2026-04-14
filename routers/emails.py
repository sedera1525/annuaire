"""
Societies — Collecte et gestion des emails visiteurs (popup tunnel de vente)
"""
from fastapi import APIRouter, Depends, Request

from core.auth import require_admin
from services.fiches import collect_email, list_collected_emails

router = APIRouter()


@router.post("/emails/collect")
async def collect(request: Request, data: dict):
    """
    Endpoint public — reçoit un email depuis le popup WordPress.
    Le plugin WP appelle cet endpoint côté visiteur (sans auth).
    """
    email = (data.get("email") or "").strip().lower()
    if not email or "@" not in email:
        return {"ok": False, "error": "Email invalide"}
    ip = request.client.host if request.client else ""
    return collect_email(
        email=email,
        source_url=data.get("source_url", ""),
        source_page=data.get("source_page", ""),
        ip=ip,
    )


@router.get("/emails", dependencies=[Depends(require_admin)])
def get_emails(limit: int = 500):
    """Liste tous les emails collectés — admin uniquement."""
    emails = list_collected_emails(limit=limit)
    return {"emails": emails, "total": len(emails)}
