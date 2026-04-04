"""
Societies — Endpoints licence et téléchargement plugin WordPress
"""
import io
import logging
import secrets
import zipfile
from pathlib import Path

from fastapi import APIRouter, Depends, HTTPException, Request
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


def _plugin_version() -> str:
    """Lit la version depuis l'en-tête du fichier PHP du plugin."""
    php = Path(__file__).parent.parent / "wordpress" / "societies-connector" / "societies-connector.php"
    try:
        for line in php.read_text(encoding="utf-8").splitlines():
            if line.strip().startswith("* Version:"):
                return line.split(":", 1)[1].strip()
    except Exception:
        pass
    return "1.0.0"


def _build_zip() -> io.BytesIO:
    plugin_dir = Path(__file__).parent.parent / "wordpress" / "societies-connector"
    if not plugin_dir.exists():
        raise HTTPException(status_code=404, detail="Plugin introuvable sur ce serveur")
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, "w", zipfile.ZIP_DEFLATED) as zf:
        for f in plugin_dir.rglob("*"):
            if f.is_file():
                zf.write(f, f"societies-connector/{f.relative_to(plugin_dir)}")
    buf.seek(0)
    return buf


@router.get("/plugin/info")
def plugin_info(request: Request):
    """Endpoint public — infos de mise à jour pour le plugin WordPress."""
    base = str(request.base_url).rstrip("/")
    version = _plugin_version()
    return {
        "slug":          "societies-connector",
        "version":       version,
        "requires":      "6.0",
        "tested":        "6.9",
        "download_url":  f"{base}/api/plugin/download",
        "sections": {
            "description": "Plugin WordPress Societies — affichage des fiches entreprises générées par IA.",
            "changelog":   f"<h4>v{version}</h4><p>Dernière version disponible.</p>",
        },
    }


@router.get("/plugin/download")
def download_plugin(license: str = "", request: Request = None):
    """
    Télécharge le ZIP du plugin.
    Accepte soit une session admin, soit une clé de licence valide (?license=XXXX).
    """
    # Auth via clé de licence
    if license:
        stored_hash = get_setting("license_hash")
        if not stored_hash or not secrets.compare_digest(_hash_key(license.strip()), stored_hash):
            raise HTTPException(status_code=403, detail="Clé de licence invalide")
    elif request:
        from core.auth import is_authenticated, is_admin
        if not is_authenticated(request) or not is_admin(request):
            raise HTTPException(status_code=401, detail="Non authentifié")
    buf = _build_zip()
    return StreamingResponse(
        buf,
        media_type="application/zip",
        headers={"Content-Disposition": "attachment; filename=societies-connector.zip"},
    )
