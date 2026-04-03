"""
Societies — Intégration WooCommerce (packs abonnements + suivi abonnés)
"""
import json
import logging
import sqlite3

import httpx
from fastapi import APIRouter, Depends, HTTPException
from pydantic import BaseModel
from typing import Optional

from core.auth import require_admin
from core.config import FICHES_DB
from services.fiches import get_setting, set_setting

logger = logging.getLogger("societies")
router = APIRouter(tags=["woocommerce"])


# =============================================================================
# MODELS
# =============================================================================

class WcSettingsRequest(BaseModel):
    wc_url:             str
    wc_consumer_key:    str
    wc_consumer_secret: str


class PackRequest(BaseModel):
    name:        str
    slug:        str
    price_ht:    float
    color:       Optional[str] = "#10b981"
    description: Optional[str] = ""
    features:    Optional[list] = []


# =============================================================================
# HELPERS
# =============================================================================

def _wc_auth() -> tuple[str, tuple[str, str]]:
    """Retourne (base_url, (ck, cs)) depuis les settings."""
    wp_url = get_setting("wc_url") or ""
    ck     = get_setting("wc_consumer_key") or ""
    cs     = get_setting("wc_consumer_secret") or ""
    if not wp_url or not ck or not cs:
        raise HTTPException(status_code=400, detail="Clés WooCommerce non configurées")
    return wp_url.rstrip("/") + "/wp-json/wc/v3", (ck, cs)


def _get_packs() -> list[dict]:
    conn = sqlite3.connect(FICHES_DB)
    conn.row_factory = sqlite3.Row
    try:
        rows = conn.execute("SELECT * FROM subscription_packs ORDER BY price_ht ASC").fetchall()
        packs = []
        for r in rows:
            p = dict(r)
            try:
                p["features"] = json.loads(p["features"] or "[]")
            except Exception:
                p["features"] = []
            packs.append(p)
        return packs
    finally:
        conn.close()


# =============================================================================
# SETTINGS WC
# =============================================================================

@router.get("/wc/settings", dependencies=[Depends(require_admin)])
def wc_get_settings():
    return {
        "wc_url":          get_setting("wc_url") or "",
        "wc_consumer_key": get_setting("wc_consumer_key") or "",
        "has_secret":      bool(get_setting("wc_consumer_secret")),
    }


@router.post("/wc/settings", dependencies=[Depends(require_admin)])
def wc_save_settings(data: WcSettingsRequest):
    set_setting("wc_url",             data.wc_url.rstrip("/"))
    set_setting("wc_consumer_key",    data.wc_consumer_key.strip())
    set_setting("wc_consumer_secret", data.wc_consumer_secret.strip())
    logger.info("Clés WooCommerce mises à jour")
    return {"ok": True}


# =============================================================================
# GESTION DES PACKS (CRUD)
# =============================================================================

@router.get("/wc/packs", dependencies=[Depends(require_admin)])
def list_packs():
    return {"packs": _get_packs()}


@router.post("/wc/packs", dependencies=[Depends(require_admin)])
def create_pack(data: PackRequest):
    conn = sqlite3.connect(FICHES_DB)
    try:
        conn.execute(
            "INSERT INTO subscription_packs (name, slug, price_ht, color, description, features) "
            "VALUES (?, ?, ?, ?, ?, ?)",
            [data.name, data.slug, data.price_ht, data.color, data.description,
             json.dumps(data.features, ensure_ascii=False)],
        )
        conn.commit()
        return {"ok": True}
    except sqlite3.IntegrityError:
        raise HTTPException(status_code=409, detail="Un pack avec ce slug existe déjà")
    finally:
        conn.close()


@router.put("/wc/packs/{pack_id}", dependencies=[Depends(require_admin)])
def update_pack(pack_id: int, data: PackRequest):
    conn = sqlite3.connect(FICHES_DB)
    try:
        affected = conn.execute(
            "UPDATE subscription_packs SET name=?, slug=?, price_ht=?, color=?, description=?, "
            "features=?, updated_at=datetime('now') WHERE id=?",
            [data.name, data.slug, data.price_ht, data.color, data.description,
             json.dumps(data.features, ensure_ascii=False), pack_id],
        ).rowcount
        conn.commit()
    finally:
        conn.close()
    if not affected:
        raise HTTPException(status_code=404, detail="Pack introuvable")
    return {"ok": True}


@router.delete("/wc/packs/{pack_id}", dependencies=[Depends(require_admin)])
def delete_pack(pack_id: int):
    conn = sqlite3.connect(FICHES_DB)
    try:
        affected = conn.execute(
            "DELETE FROM subscription_packs WHERE id=?", [pack_id]
        ).rowcount
        conn.commit()
    finally:
        conn.close()
    if not affected:
        raise HTTPException(status_code=404, detail="Pack introuvable")
    return {"ok": True}


# =============================================================================
# SYNCHRONISATION VERS WOOCOMMERCE
# =============================================================================

@router.get("/wc/status", dependencies=[Depends(require_admin)])
def wc_status():
    """Vérifie la connexion WooCommerce."""
    base, auth = _wc_auth()
    try:
        r = httpx.get(f"{base}/products", params={"per_page": 1}, auth=auth, timeout=10)
        r.raise_for_status()
        return {"connected": True}
    except httpx.HTTPError as e:
        return {"connected": False, "error": str(e)}


@router.post("/wc/sync-packs", dependencies=[Depends(require_admin)])
def sync_packs():
    """Envoie tous les packs vers WooCommerce (crée ou met à jour)."""
    base, auth = _wc_auth()
    packs = _get_packs()
    created, updated, errors = [], [], []

    conn = sqlite3.connect(FICHES_DB)
    try:
        for pack in packs:
            try:
                payload = {
                    "name":              pack["name"],
                    "slug":              pack["slug"],
                    "type":              "simple",
                    "status":            "publish",
                    "regular_price":     str(int(pack["price_ht"])),
                    "short_description": pack["description"] or "",
                    "description":       "<ul>" + "".join(f"<li>{f}</li>" for f in pack["features"]) + "</ul>",
                    "meta_data": [
                        {"key": "_sc_pack",                "value": pack["slug"]},
                        {"key": "_sc_subscription_period", "value": "month"},
                    ],
                    "tags": [{"name": "sc-pack"}],
                }

                wc_id = pack.get("wc_product_id")

                if wc_id:
                    # Vérifie que le produit existe encore dans WC
                    chk = httpx.get(f"{base}/products/{wc_id}", auth=auth, timeout=10)
                    if chk.status_code == 404:
                        wc_id = None  # supprimé côté WC, on recrée

                if wc_id:
                    r = httpx.put(f"{base}/products/{wc_id}", json=payload, auth=auth, timeout=10)
                    r.raise_for_status()
                    updated.append({"id": wc_id, "name": pack["name"]})
                    logger.info(f"Pack WC mis à jour : {pack['name']} (id={wc_id})")
                else:
                    r = httpx.post(f"{base}/products", json=payload, auth=auth, timeout=10)
                    r.raise_for_status()
                    wc_id = r.json()["id"]
                    # Sauvegarde l'ID WooCommerce
                    conn.execute(
                        "UPDATE subscription_packs SET wc_product_id=?, updated_at=datetime('now') WHERE id=?",
                        [wc_id, pack["id"]],
                    )
                    conn.commit()
                    created.append({"id": wc_id, "name": pack["name"]})
                    logger.info(f"Pack WC créé : {pack['name']} (id={wc_id})")

            except Exception as e:
                errors.append({"name": pack["name"], "error": str(e)})
                logger.error(f"Erreur sync pack WC '{pack['name']}': {e}")
    finally:
        conn.close()

    return {"created": created, "updated": updated, "errors": errors}


# =============================================================================
# SUIVI DES ABONNÉS
# =============================================================================

@router.get("/wc/subscriptions", dependencies=[Depends(require_admin)])
def list_subscriptions(page: int = 1, per_page: int = 25, status: str = "active"):
    """Récupère les abonnements depuis WooCommerce."""
    base, auth = _wc_auth()
    # YITH stocke les abonnements comme des orders avec meta
    # On récupère via l'endpoint orders filtré par le tag sc-pack
    try:
        params = {
            "per_page": per_page,
            "page":     page,
            "status":   status,
        }
        # Tente d'abord l'endpoint YITH subscriptions s'il existe
        r = httpx.get(f"{base}/yith/subscriptions", params=params, auth=auth, timeout=15)
        if r.status_code == 200:
            data = r.json()
            return {"source": "yith", "results": data, "page": page}

        # Fallback : orders WooCommerce classiques
        r2 = httpx.get(f"{base}/orders", params={**params, "meta_key": "_sc_pack"}, auth=auth, timeout=15)
        r2.raise_for_status()
        orders = r2.json()
        results = []
        for o in orders:
            meta = {m["key"]: m["value"] for m in (o.get("meta_data") or [])}
            results.append({
                "id":           o["id"],
                "status":       o["status"],
                "date_created": o["date_created"],
                "total":        o["total"],
                "customer":     o.get("billing", {}).get("email", ""),
                "pack":         meta.get("_sc_pack", ""),
            })
        return {"source": "orders", "results": results, "page": page}

    except httpx.HTTPError as e:
        raise HTTPException(status_code=502, detail=f"Erreur WooCommerce : {e}")


# =============================================================================
# PUBLICATION PAGE MOTEUR DE RECHERCHE DANS WORDPRESS
# =============================================================================

@router.post("/wp/publish-search-page", dependencies=[Depends(require_admin)])
def publish_search_page():
    """Crée ou met à jour la page moteur de recherche WordPress avec [societies_search]."""
    wp_url = get_setting("wc_url") or ""
    ck     = get_setting("wc_consumer_key") or ""
    cs     = get_setting("wc_consumer_secret") or ""
    if not wp_url or not ck or not cs:
        raise HTTPException(status_code=400, detail="Clés WooCommerce non configurées")

    base = wp_url.rstrip("/") + "/wp-json/wp/v2"
    auth = (ck, cs)

    page_slug    = "recherche-entreprises"
    page_title   = "Recherche d'entreprises"
    page_content = "<!-- wp:shortcode -->[societies_search]<!-- /wp:shortcode -->"

    # Vérifie si la page existe déjà
    try:
        r = httpx.get(f"{base}/pages", params={"slug": page_slug, "status": "any"}, auth=auth, timeout=10)
        r.raise_for_status()
        existing = r.json()
    except httpx.HTTPError as e:
        raise HTTPException(status_code=502, detail=f"Erreur WP REST API : {e}")

    payload = {
        "title":   page_title,
        "slug":    page_slug,
        "content": page_content,
        "status":  "publish",
    }

    try:
        if existing:
            page_id = existing[0]["id"]
            r2 = httpx.put(f"{base}/pages/{page_id}", json=payload, auth=auth, timeout=10)
            r2.raise_for_status()
            page_link = r2.json().get("link", "")
            logger.info(f"Page recherche mise à jour (id={page_id})")
            return {"action": "updated", "id": page_id, "url": page_link}
        else:
            r2 = httpx.post(f"{base}/pages", json=payload, auth=auth, timeout=10)
            r2.raise_for_status()
            page_id   = r2.json()["id"]
            page_link = r2.json().get("link", "")
            logger.info(f"Page recherche créée (id={page_id})")
            return {"action": "created", "id": page_id, "url": page_link}
    except httpx.HTTPError as e:
        raise HTTPException(status_code=502, detail=f"Erreur création page : {e}")
