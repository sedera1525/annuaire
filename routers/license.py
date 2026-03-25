"""
Societies — Endpoints licence et téléchargement plugin WordPress
"""
import io
import logging
import secrets
import zipfile
from pathlib import Path

from fastapi import APIRouter, Depends, HTTPException
from fastapi.responses import StreamingResponse

from core.auth import require_admin
from models import LicenseVerifyRequest
from services.fiches import _hash_key, get_setting, set_setting

logger = logging.getLogger("societies")
router = APIRouter(tags=["license"])


@router.get("/license/status")
def license_status():
    return {
        "has_key":   bool(get_setting("license_hash")),
        "activated": get_setting("license_activated") == "true",
    }


@router.post("/license/generate", dependencies=[Depends(require_admin)])
def license_generate():
    raw = "-".join(secrets.token_hex(2).upper() for _ in range(4))
    set_setting("license_hash",      _hash_key(raw))
    set_setting("license_activated", "false")
    logger.info("Nouvelle clé de licence générée")
    return {"key": raw}


@router.post("/license/verify")
def license_verify(data: LicenseVerifyRequest):
    stored_hash = get_setting("license_hash")
    if not stored_hash:
        raise HTTPException(status_code=404, detail="Aucune licence configurée sur ce serveur")
    if not secrets.compare_digest(_hash_key(data.key.strip()), stored_hash):
        logger.warning("Tentative de vérification de licence invalide")
        raise HTTPException(status_code=403, detail="Clé de licence invalide")
    set_setting("license_activated", "true")
    return {"ok": True, "hash": _hash_key(data.key.strip())}


@router.get("/plugin/download", dependencies=[Depends(require_admin)])
def download_plugin():
    plugin_dir = Path(__file__).parent.parent / "wordpress" / "societies-connector"
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
