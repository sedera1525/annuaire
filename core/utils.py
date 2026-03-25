"""
Societies — Fonctions utilitaires partagées
"""
import json
from datetime import datetime

from .config import MONTHS_FR


def format_date_fr(date_str: str) -> str:
    try:
        dt = datetime.strptime(date_str[:10], "%Y-%m-%d")
        return f"{dt.day} {MONTHS_FR[dt.month - 1]} {dt.year}"
    except Exception:
        return ""


def sse(event_type: str, **kwargs) -> str:
    return f"data: {json.dumps({'type': event_type, **kwargs}, ensure_ascii=False)}\n\n"


# Mots-clés exclus — la catégorie est rejetée si elle CONTIENT l'un de ces mots
_EXCLUDED_KEYWORDS = [
    # Lieux de culte
    "church", "mosque", "temple", "synagogue", "worship", "cathedral",
    "chapel", "parish", "eglise", "église", "mosquée", "culte",
    "religious", "religion",
    # Établissements publics / administrations
    "city hall", "town hall", "mairie", "prefecture", "préfecture",
    "government", "administration", "dgfip", "ddpp", "impot", "impôt",
    "tax office", "trésor public", "caf ", "pôle emploi", "pole emploi",
    # Santé publique
    "hospital", "hopital", "hôpital", "clinic", "clinique",
    "emergency room", "urgence", "chu ", "chru", "aphp",
    # Forces de l'ordre
    "police", "gendarmerie", "commissariat", "fire station",
    "firefighter", "pompier", "brigade",
    # Syndicats / partis politiques
    "syndicat", "trade union", "labor union", "political party",
    "parti politique", "cgt", "cfdt", "fo ", "cfe-cgc",
]


def is_excluded_category(category: str | None) -> bool:
    """Retourne True si la catégorie contient un mot-clé exclu."""
    if not category:
        return False
    cat = category.strip().lower()
    return any(kw in cat for kw in _EXCLUDED_KEYWORDS)
