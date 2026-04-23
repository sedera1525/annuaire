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
    "religious", "religion", "abbaye", "couvent", "prieuré",
    "lieu de culte", "place of worship",
    # Établissements publics / administrations
    "city hall", "town hall", "mairie", "prefecture", "préfecture",
    "sous-préfecture", "sous-prefecture",
    "government", "gouvernement", "ministère", "ministere",
    "administration", "service public", "établissement public",
    "dgfip", "dgddi", "ddpp", "direccte", "dreal", "drfip",
    "impot", "impôt", "tax office", "trésor public", "tresor public",
    "caf ", "cpam", "urssaf", "rsi ", "msa ", "carsat",
    "pôle emploi", "pole emploi", "france travail",
    "tribunal", "cour d'appel", "cour de cassation", "conseil d'etat",
    "prud'hommes", "prud'homme",
    "sénat", "senat", "assemblée nationale", "assemblee nationale",
    "préfet", "prefet", "sous-prefet", "sous-préfet",
    # Santé publique (hôpitaux publics uniquement)
    "hospital", "hopital", "hôpital", "emergency room",
    "urgence", "chu ", "chru", "aphp", "centre hospitalier",
    # Forces de l'ordre
    "police", "gendarmerie", "commissariat", "fire station",
    "firefighter", "pompier", "brigade", "sdis ",
    # Syndicats / partis politiques
    "syndicat", "trade union", "labor union", "political party",
    "parti politique", "cgt", "cfdt", "cfe-cgc", "unsa ",
    "solidaires", "fsu ", "unef ", "medef", "cpme",
]


def is_excluded_category(category: str | None) -> bool:
    """Retourne True si la catégorie contient un mot-clé exclu."""
    if not category:
        return False
    cat = category.strip().lower()
    return any(kw in cat for kw in _EXCLUDED_KEYWORDS)
