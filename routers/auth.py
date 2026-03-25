"""
Societies — Routes d'authentification (login / logout)
"""
import logging
from pathlib import Path

from fastapi import APIRouter, Form, Request
from fastapi.responses import HTMLResponse, RedirectResponse

from core.auth import (
    CSRF_COOKIE_NAME, add_session, check_credentials,
    make_csrf_token, make_token, remove_session,
)
from core.config import COOKIE_NAME
from core.limiter import limiter

logger = logging.getLogger("societies")
router = APIRouter(tags=["auth"])

_TEMPLATE = Path(__file__).parent.parent / "templates" / "login.html"


def _render_login(error: str = "") -> str:
    html = _TEMPLATE.read_text(encoding="utf-8")
    error_html = f'<div class="error">{error}</div>' if error else ""
    return html.replace("{error}", error_html)


@router.get("/login", response_class=HTMLResponse)
def login_page(request: Request):
    from core.auth import is_authenticated
    if is_authenticated(request):
        return RedirectResponse("/", status_code=302)
    return HTMLResponse(_render_login())


@router.post("/login")
@limiter.limit("10/minute")
async def login(
    request: Request,
    username: str = Form(...),
    password: str = Form(...),
):
    if check_credentials(username, password):
        token = make_token()
        add_session(token, username=username)
        csrf  = make_csrf_token()
        logger.info(f"Connexion réussie : {username}")
        resp  = RedirectResponse("/", status_code=302)
        resp.set_cookie(COOKIE_NAME,       token, httponly=True,  samesite="lax", max_age=900)
        # Cookie CSRF lisible par le JS du SPA (httponly=False)
        resp.set_cookie(CSRF_COOKIE_NAME,  csrf,  httponly=False, samesite="lax", max_age=900)
        return resp
    logger.warning(f"Tentative de connexion échouée : {username}")
    return HTMLResponse(
        _render_login("Identifiant ou mot de passe incorrect."),
        status_code=401,
    )


@router.get("/logout")
def logout(request: Request):
    token = request.cookies.get(COOKIE_NAME)
    if token:
        remove_session(token)
    resp = RedirectResponse("/login", status_code=302)
    resp.delete_cookie(COOKIE_NAME)
    return resp
