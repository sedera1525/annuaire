"""
Societies — Gestion des sessions (SQLite persistant) avec rotation de token
Inspiré de RealeseSeo : Sanctum tokens en DB + token refresh
"""
import logging
import secrets
import sqlite3
import time

from fastapi import HTTPException, Request, Response

from .config import APP_PASSWORD, APP_USERNAME, COOKIE_NAME, FICHES_DB

logger = logging.getLogger("societies")

_TOKEN_ROTATE_AFTER = 86400      # Rotation si token > 1 jour
_TOKEN_MAX_AGE      = 86400 * 7  # Durée max 7 jours
CSRF_COOKIE_NAME    = "csrf_token"

# Endpoints exemptés du contrôle CSRF (intégrations externes sans cookie)
_CSRF_EXEMPT_PATHS  = {"/api/license/verify"}


def make_csrf_token() -> str:
    return secrets.token_hex(32)


def verify_csrf(request: Request) -> bool:
    """
    Double-submit cookie pattern — la valeur du cookie csrf_token doit
    correspondre exactement au header X-CSRF-Token.
    Protège contre les requêtes cross-origin sans modifier le frontend SPA.
    """
    cookie_csrf = request.cookies.get(CSRF_COOKIE_NAME, "")
    header_csrf = request.headers.get("X-CSRF-Token", "")
    if not cookie_csrf or not header_csrf:
        return False
    return secrets.compare_digest(cookie_csrf, header_csrf)


def make_token() -> str:
    return secrets.token_hex(32)


def check_credentials(username: str, password: str) -> bool:
    ok_user = secrets.compare_digest(username, APP_USERNAME)
    ok_pass = secrets.compare_digest(password, APP_PASSWORD)
    return ok_user and ok_pass


def add_session(token: str, username: str = "") -> None:
    conn = sqlite3.connect(FICHES_DB)
    try:
        conn.execute(
            "INSERT OR REPLACE INTO sessions(token, created_at, username) VALUES(?, ?, ?)",
            [token, time.time(), username],
        )
        conn.commit()
    finally:
        conn.close()


def get_session_user(token: str) -> str | None:
    """Retourne le username associé au token, None si inexistant."""
    conn = sqlite3.connect(FICHES_DB)
    try:
        row = conn.execute(
            "SELECT username FROM sessions WHERE token=?", [token]
        ).fetchone()
        return row[0] if row else None
    finally:
        conn.close()


def is_admin(request: Request) -> bool:
    """Vérifie que l'utilisateur authentifié est l'administrateur.
    Les sessions créées avant la migration v4 ont username='' — traitées
    comme admin car il n'existe qu'un seul utilisateur dans cette app."""
    token = request.cookies.get(COOKIE_NAME)
    if not token:
        return False
    user = get_session_user(token)
    if user is None:
        return False
    return user == APP_USERNAME or user == ""


def require_admin(request: Request) -> None:
    """
    Dépendance FastAPI — lève 403 si l'utilisateur n'est pas admin.
    Inspiré de Spatie permissions (RealeseSeo) — s'applique sur les endpoints sensibles.
    Usage : @router.post("/...", dependencies=[Depends(require_admin)])
    """
    if not is_authenticated(request):
        raise HTTPException(status_code=401, detail="Non authentifié")
    if not is_admin(request):
        raise HTTPException(status_code=403, detail="Accès réservé à l'administrateur")


def remove_session(token: str) -> None:
    conn = sqlite3.connect(FICHES_DB)
    try:
        conn.execute("DELETE FROM sessions WHERE token=?", [token])
        conn.commit()
    finally:
        conn.close()


def get_session_age(token: str) -> float:
    """Retourne l'âge du token en secondes, -1 si inexistant."""
    conn = sqlite3.connect(FICHES_DB)
    try:
        row = conn.execute(
            "SELECT created_at FROM sessions WHERE token=?", [token]
        ).fetchone()
        return time.time() - row[0] if row else -1.0
    finally:
        conn.close()


def is_authenticated(request: Request) -> bool:
    token = request.cookies.get(COOKIE_NAME)
    if not token:
        return False
    conn = sqlite3.connect(FICHES_DB)
    try:
        row = conn.execute("SELECT 1 FROM sessions WHERE token=?", [token]).fetchone()
        return bool(row)
    finally:
        conn.close()


def rotate_if_needed(request: Request, response: Response) -> None:
    """
    Émet un nouveau token si l'actuel a plus de 1 jour.
    Inspiré du token refresh de Sanctum — protège contre le vol de token long-term.
    """
    token = request.cookies.get(COOKIE_NAME)
    if not token:
        return
    if get_session_age(token) > _TOKEN_ROTATE_AFTER:
        new_token = make_token()
        remove_session(token)
        add_session(new_token)
        response.set_cookie(
            COOKIE_NAME, new_token,
            httponly=True, samesite="lax", max_age=_TOKEN_MAX_AGE,
        )
        logger.info("Token de session renouvelé (rotation automatique)")
