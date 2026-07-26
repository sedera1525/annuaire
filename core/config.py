"""
Societies — Configuration centralisée (variables d'environnement)
"""
import os
import secrets
from pathlib import Path

from dotenv import load_dotenv

load_dotenv()

MONTHS_FR = [
    "janvier", "février", "mars", "avril", "mai", "juin",
    "juillet", "août", "septembre", "octobre", "novembre", "décembre",
]

BASE_DIR   = Path(__file__).parent.parent
CSV_PATH   = str(BASE_DIR / "0.csv")
DB_PATH    = str(BASE_DIR / "societies.duckdb")
FICHES_DB  = str(BASE_DIR / "fiches.db")
STATIC_DIR = BASE_DIR / "static"
STATIC_DIR.mkdir(exist_ok=True)

LOG_DIR = BASE_DIR / "logs"
LOG_DIR.mkdir(exist_ok=True)

OPENAI_API_KEY = os.getenv("OPENAI_API_KEY", "")
OPENAI_MODEL   = os.getenv("OPENAI_MODEL", "gpt-5")
OPENAI_TIMEOUT = float(os.getenv("OPENAI_TIMEOUT", "30"))

APP_USERNAME = os.getenv("APP_USERNAME", "admin")
APP_PASSWORD = os.getenv("APP_PASSWORD", "")
SECRET_KEY    = os.getenv("SECRET_KEY", secrets.token_hex(32))
COOKIE_NAME   = "societies_session"
CSRF_ENABLED  = os.getenv("CSRF_ENABLED", "true").lower() == "true"
REDIS_URL     = os.getenv("REDIS_URL", "")      # Ex: redis://localhost:6379/0

# URL publique du front Next.js (annuaire) — sert à générer les liens de fiche/démo.
PUBLIC_SITE_URL = os.getenv("PUBLIC_SITE_URL", "http://localhost:3100").rstrip("/")

ALLOWED_ORIGINS = [
    o.strip()
    for o in os.getenv(
        "ALLOWED_ORIGINS",
        "http://localhost:8090,http://localhost:8085",
    ).split(",")
    if o.strip()
]
