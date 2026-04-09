"""
Societies — Gestion des sessions (SQLite persistant) avec rotation de token
Inspiré de RealeseSeo : Sanctum tokens en DB + token refresh
"""
import logging
import secrets
import sqlite3
import time

import bcrypt
from fastapi import HTTPException, Request, Response

from .config import APP_PASSWORD, APP_USERNAME, COOKIE_NAME, FICHES_DB

logger = logging.getLogger("societies")

_SESSION_TIMEOUT = 28800  # Déconnexion après 8h d'inactivité
_TOKEN_MAX_AGE   = 28800  # Durée max du cookie (identique au timeout)
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
    try:
        ok_user = bcrypt.checkpw(username.encode(), APP_USERNAME.encode())
        ok_pass = bcrypt.checkpw(password.encode(), APP_PASSWORD.encode())
        return ok_user and ok_pass
    except Exception:
        return False


def add_session(token: str, username: str = "") -> None:
    now = time.time()
    conn = sqlite3.connect(FICHES_DB)
    try:
        conn.execute(
            "INSERT OR REPLACE INTO sessions(token, created_at, username, last_activity) VALUES(?, ?, ?, ?)",
            [token, now, username, now],
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
    if user == "":
        return True  # sessions legacy (avant migration v4)
    try:
        return bcrypt.checkpw(user.encode(), APP_USERNAME.encode())
    except Exception:
        return False


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




def is_authenticated(request: Request) -> bool:
    token = request.cookies.get(COOKIE_NAME)
    if not token:
        return False
    conn = sqlite3.connect(FICHES_DB)
    try:
        row = conn.execute(
            "SELECT last_activity FROM sessions WHERE token=?", [token]
        ).fetchone()
        if not row:
            return False
        if time.time() - row[0] > _SESSION_TIMEOUT:
            conn.execute("DELETE FROM sessions WHERE token=?", [token])
            conn.commit()
            return False
        return True
    finally:
        conn.close()


def touch_session(request: Request, response: Response) -> None:
    """Met à jour last_activity et renouvelle les cookies à chaque requête authentifiée."""
    token = request.cookies.get(COOKIE_NAME)
    if not token:
        return
    conn = sqlite3.connect(FICHES_DB)
    try:
        conn.execute(
            "UPDATE sessions SET last_activity=? WHERE token=?",
            [time.time(), token],
        )
        conn.commit()
    finally:
        conn.close()
    response.set_cookie(
        COOKIE_NAME, token,
        httponly=True, samesite="lax", max_age=_TOKEN_MAX_AGE,
    )
    # Renouvelle aussi le cookie CSRF pour éviter l'expiration sur opérations longues
    csrf = request.cookies.get(CSRF_COOKIE_NAME, "")
    if csrf:
        response.set_cookie(
            CSRF_COOKIE_NAME, csrf,
            httponly=False, samesite="lax", max_age=_TOKEN_MAX_AGE,
        )
