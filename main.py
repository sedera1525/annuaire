"""
Societies — Point d'entrée de l'application
Toute la logique métier est dans core/, services/ et routers/.
"""
import logging
import os
import threading
from contextlib import asynccontextmanager
from logging.handlers import RotatingFileHandler

import duckdb
import sentry_sdk
from fastapi import FastAPI, Request, Response
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import FileResponse, RedirectResponse
from fastapi.staticfiles import StaticFiles
from slowapi.errors import RateLimitExceeded
from slowapi import _rate_limit_exceeded_handler

# Sentry — optionnel, activé si SENTRY_DSN est défini dans .env
_SENTRY_DSN = os.getenv("SENTRY_DSN", "")
if _SENTRY_DSN:
    sentry_sdk.init(
        dsn=_SENTRY_DSN,
        traces_sample_rate=0.1,
        profiles_sample_rate=0.05,
    )

from core.auth import (
    _CSRF_EXEMPT_PATHS, is_authenticated, touch_session, verify_csrf,
)
from core.config import ALLOWED_ORIGINS, COOKIE_NAME, CSRF_ENABLED, DB_PATH, LOG_DIR, STATIC_DIR
from core.db import db_state, init_db
from core.limiter import limiter
from services.fiches import get_setting, init_fiches_db

from routers import auth as auth_router
from routers import auto, generation, license as license_router, seo
from routers import fiches as fiches_router
from routers import search

# =============================================================================
# LOGGING
# =============================================================================

logger = logging.getLogger("societies")
logger.setLevel(logging.INFO)
_fmt = logging.Formatter("%(asctime)s [%(levelname)s] %(message)s", datefmt="%Y-%m-%d %H:%M:%S")
_fh  = RotatingFileHandler(LOG_DIR / "societies.log", maxBytes=5 * 1024 * 1024,
                            backupCount=3, encoding="utf-8")
_fh.setFormatter(_fmt)
_ch = logging.StreamHandler()
_ch.setFormatter(_fmt)
logger.addHandler(_fh)
logger.addHandler(_ch)

# =============================================================================
# LIFESPAN — remplace @app.on_event("startup")
# =============================================================================

@asynccontextmanager
async def lifespan(app: FastAPI):
    init_fiches_db()
    if os.path.exists(DB_PATH):
        # Phase C — lit le flag SQLite pour éviter une reconnexion DuckDB inutile
        cached_rows = get_setting("db_rows")
        if cached_rows and cached_rows.isdigit():
            rows = int(cached_rows)
            db_state.update({"ready": True, "rows": rows, "progress": 100,
                             "message": f"Base prête — {rows:,} entreprises"})
            logger.info(f"DuckDB disponible (flag SQLite) — {rows:,} entreprises")
        else:
            try:
                conn = duckdb.connect(DB_PATH, read_only=True)
                rows = conn.execute("SELECT COUNT(*) FROM companies").fetchone()[0]
                conn.close()
                db_state.update({"ready": True, "rows": rows, "progress": 100,
                                 "message": f"Base prête — {rows:,} entreprises"})
                logger.info(f"DuckDB déjà disponible — {rows:,} entreprises")
            except Exception:
                threading.Thread(target=init_db, daemon=True).start()
    else:
        threading.Thread(target=init_db, daemon=True).start()
    yield

# =============================================================================
# APP
# =============================================================================

app = FastAPI(title="Societies", version="1.0.0", lifespan=lifespan)
app.state.limiter = limiter
app.add_exception_handler(RateLimitExceeded, _rate_limit_exceeded_handler)

app.add_middleware(
    CORSMiddleware,
    allow_origins=ALLOWED_ORIGINS,
    allow_credentials=True,
    allow_methods=["GET", "POST", "PUT", "DELETE"],
    allow_headers=["Content-Type", "Authorization"],
)

# =============================================================================
# MIDDLEWARE SÉCURITÉ — Headers HTTP
# =============================================================================

@app.middleware("http")
async def security_headers_middleware(request: Request, call_next):
    response = await call_next(request)
    response.headers["X-Content-Type-Options"] = "nosniff"
    response.headers["X-Frame-Options"]         = "DENY"
    response.headers["Referrer-Policy"]          = "strict-origin-when-cross-origin"
    response.headers["Permissions-Policy"]       = "geolocation=(), camera=(), microphone=()"
    if request.url.scheme == "https":
        response.headers["Strict-Transport-Security"] = "max-age=31536000; includeSubDomains"
    # CSP : restreint les sources par défaut, autorise inline pour le SPA
    response.headers["Content-Security-Policy"] = (
        "default-src 'self'; "
        "script-src 'self' 'unsafe-inline'; "
        "style-src 'self' 'unsafe-inline'; "
        "img-src 'self' data: blob:; "
        "connect-src 'self'; "
        "font-src 'self' data:; "
        "frame-ancestors 'none'"
    )
    return response


# =============================================================================
# MIDDLEWARE AUTH + ROTATION DE TOKEN
# =============================================================================

@app.middleware("http")
async def auth_middleware(request: Request, call_next):
    path = request.url.path
    if (path in ("/login", "/api/license/verify", "/api/healthz")
            or path.startswith("/static/")
            or path.startswith("/api/company/")
            or path.startswith("/api/fiche/")
            or path == "/api/plugin/info"
            or path == "/api/plugin/download"):
        return await call_next(request)
    if not is_authenticated(request):
        if path.startswith("/api/"):
            return Response(
                content='{"detail":"Non authentifié"}',
                status_code=401,
                media_type="application/json",
            )
        return RedirectResponse("/login", status_code=302)
    # CSRF — vérifie le header X-CSRF-Token sur les mutations (POST/PUT/DELETE)
    if CSRF_ENABLED and request.method in ("POST", "PUT", "DELETE"):
        if path.startswith("/api/") and path not in _CSRF_EXEMPT_PATHS:
            if not verify_csrf(request):
                return Response(
                    content='{"detail":"Token CSRF invalide ou manquant"}',
                    status_code=403,
                    media_type="application/json",
                )
    response = await call_next(request)
    touch_session(request, response)  # Renouvelle last_activity (timeout 15 min)
    return response

# =============================================================================
# ROUTERS
# =============================================================================

app.include_router(auth_router.router)
app.include_router(search.router,         prefix="/api")
app.include_router(generation.router,     prefix="/api")
app.include_router(fiches_router.router,  prefix="/api")
app.include_router(auto.router,           prefix="/api")
app.include_router(license_router.router, prefix="/api")
app.include_router(seo.router,            prefix="/api")

# =============================================================================
# STATIC + SPA FALLBACK
# =============================================================================

app.mount("/static", StaticFiles(directory=str(STATIC_DIR)), name="static")


@app.get("/")
@app.get("/{full_path:path}")
def serve_spa(full_path: str = ""):
    if full_path.startswith("api/"):
        from fastapi import HTTPException
        raise HTTPException(status_code=404)
    return FileResponse(
        str(STATIC_DIR / "index.html"),
        headers={"Cache-Control": "no-store"},
    )


if __name__ == "__main__":
    import uvicorn
    host = os.getenv("APP_HOST", "0.0.0.0")
    port = int(os.getenv("APP_PORT", "8090"))
    uvicorn.run(app, host=host, port=port, reload=False)
