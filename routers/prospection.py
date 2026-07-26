"""
Societies — Prospection.

Liste les entreprises **sans site web** mais **joignables** (téléphone ou email),
pour démarcher une offre de création de site. Réservé à l'admin (auth requise via
le middleware). Fournit aussi un export CSV et la page HTML.
"""

import csv as csv_mod
import io
import json
import re
import unicodedata
from typing import Any, Optional

from fastapi import APIRouter
from fastapi.responses import FileResponse, StreamingResponse

from core.config import PUBLIC_SITE_URL, STATIC_DIR
from core.db import get_conn

router = APIRouter()

# Prospect = pas de site web ET (téléphone non vide OU email présent).
_PROSPECT_WHERE = (
    "(url = '' OR url IS NULL) "
    "AND ((phone IS NOT NULL AND phone != '') "
    "OR contacts LIKE '%\"type\":\"Mail\"%')"
)

_SELECT_COLS = "title, category, phone, addr_street, city, zip_code, contacts"


def _build_conditions(city: Optional[str], category: Optional[str]) -> tuple[str, list[str]]:
    conditions = [_PROSPECT_WHERE]
    params: list[str] = []
    if city:
        conditions.append("UPPER(city) LIKE UPPER(?)")
        params.append(f"%{city[:100]}%")
    if category:
        conditions.append("UPPER(category) LIKE UPPER(?)")
        params.append(f"%{category[:150]}%")
    return " AND ".join(conditions), params


def _emails_from_contacts(raw: Optional[str]) -> list[str]:
    try:
        return [c["value"] for c in json.loads(raw or "[]") if c.get("type") == "Mail"]
    except Exception:
        return []


def _to_slug(title: Optional[str]) -> str:
    """Reproduit lib/slug.ts (toSlug) du front Next.js pour que le lien résolve."""
    if not title:
        return ""
    s = unicodedata.normalize("NFKD", title.lower().strip())
    s = "".join(c for c in s if not unicodedata.combining(c))
    s = re.sub(r"[^a-z0-9]+", "-", s)
    return s.strip("-")


def _demo_url(title: Optional[str]) -> str:
    """Lien vers la fiche publique (= site de démo à montrer au prospect)."""
    slug = _to_slug(title)
    return f"{PUBLIC_SITE_URL}/entreprise/{slug}" if slug else ""


@router.get("/api/prospection")
def prospection(
    city: Optional[str] = None,
    category: Optional[str] = None,
    page: int = 1,
    per_page: int = 50,
) -> dict[str, Any]:
    where, params = _build_conditions(city, category)
    per_page = min(max(int(per_page), 1), 200)
    page = max(int(page), 1)
    offset = (page - 1) * per_page

    conn = get_conn()
    try:
        total = conn.execute(
            f"SELECT COUNT(*) FROM companies WHERE {where}", params
        ).fetchone()[0]
        rows = conn.execute(
            f"""SELECT {_SELECT_COLS}
                FROM companies WHERE {where}
                ORDER BY rating_votes DESC NULLS LAST
                LIMIT {per_page} OFFSET {offset}""",
            params,
        ).fetchall()
        cols = [d[0] for d in conn.description]
    finally:
        conn.close()

    results = []
    for row in rows:
        d = dict(zip(cols, row))
        results.append({
            "title": d["title"],
            "category": d["category"],
            "phone": d["phone"],
            "addr_street": d["addr_street"],
            "city": d["city"],
            "zip_code": d["zip_code"],
            "emails": _emails_from_contacts(d.get("contacts")),
            "demo_url": _demo_url(d["title"]),
        })

    return {
        "results": results,
        "total": total,
        "page": page,
        "per_page": per_page,
        "pages": max(1, (total + per_page - 1) // per_page) if total else 1,
    }


@router.get("/api/prospection/export")
def prospection_export(
    city: Optional[str] = None,
    category: Optional[str] = None,
    limit: int = 2000,
) -> StreamingResponse:
    where, params = _build_conditions(city, category)

    conn = get_conn()
    try:
        rows = conn.execute(
            f"""SELECT {_SELECT_COLS}
                FROM companies WHERE {where}
                ORDER BY rating_votes DESC NULLS LAST
                LIMIT {min(int(limit), 5000)}""",
            params,
        ).fetchall()
        cols = [d[0] for d in conn.description]
    finally:
        conn.close()

    output = io.StringIO()
    writer = csv_mod.writer(output)
    writer.writerow([
        "Nom", "Catégorie", "Téléphone", "Email",
        "Adresse", "Ville", "Code postal", "Lien démo",
    ])
    for row in rows:
        d = dict(zip(cols, row))
        emails = "; ".join(_emails_from_contacts(d.get("contacts")))
        writer.writerow([
            d["title"], d["category"], d["phone"], emails,
            d["addr_street"], d["city"], d["zip_code"], _demo_url(d["title"]),
        ])
    output.seek(0)
    return StreamingResponse(
        io.BytesIO(output.getvalue().encode("utf-8-sig")),
        media_type="text/csv",
        headers={"Content-Disposition": "attachment; filename=prospection.csv"},
    )


@router.get("/prospection")
def prospection_page() -> FileResponse:
    return FileResponse(str(STATIC_DIR / "prospection.html"))
