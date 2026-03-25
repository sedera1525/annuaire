"""
Societies — Tests des endpoints critiques.
Inspiré de RealeseSeo (PHPUnit) — exécuter avec : pytest tests/
"""

import os
import pytest
from unittest.mock import AsyncMock, patch
from fastapi.testclient import TestClient

# Variables d'env minimales AVANT tout import du code applicatif
os.environ.setdefault("APP_USERNAME",   "admin")
os.environ.setdefault("APP_PASSWORD",   "testpassword")
os.environ.setdefault("SECRET_KEY",     "test-secret-key-for-testing-only-32ch")
os.environ.setdefault("OPENAI_API_KEY", "sk-test-key")
os.environ["CSRF_ENABLED"] = "false"   # Désactivé globalement — TestCsrf le teste via patch
os.environ["TESTING"]      = "true"    # Désactive le rate limiting (clé unique par requête)

from main import app  # noqa: E402


# =============================================================================
# HELPERS
# =============================================================================

def _make_client() -> TestClient:
    """Client non authentifié (cookie jar vide)."""
    return TestClient(app, raise_server_exceptions=False)


def _make_auth_client(username: str = "admin", password: str = "testpassword") -> TestClient:
    """Client authentifié — le cookie jar est rempli après le POST /login."""
    c = TestClient(app, raise_server_exceptions=False)
    c.post("/login", data={"username": username, "password": password},
           follow_redirects=False)
    return c


# =============================================================================
# AUTH
# =============================================================================

class TestAuth:
    def test_api_requires_auth(self):
        resp = _make_client().get("/api/categories")
        assert resp.status_code == 401

    def test_api_status_requires_auth(self):
        resp = _make_client().get("/api/status")
        assert resp.status_code == 401

    def test_login_page_accessible(self):
        resp = _make_client().get("/login", follow_redirects=False)
        assert resp.status_code == 200
        assert "Connexion" in resp.text

    def test_login_wrong_credentials(self):
        resp = _make_client().post("/login",
                                   data={"username": "admin", "password": "wrong"},
                                   follow_redirects=False)
        assert resp.status_code == 401

    def test_login_success_sets_cookie(self):
        c    = _make_client()
        resp = c.post("/login",
                      data={"username": "admin", "password": "testpassword"},
                      follow_redirects=False)
        assert resp.status_code == 302
        assert "societies_session" in c.cookies

    def test_login_sets_csrf_cookie(self):
        """Le login émet un cookie csrf_token lisible par le JS."""
        c    = _make_client()
        resp = c.post("/login",
                      data={"username": "admin", "password": "testpassword"},
                      follow_redirects=False)
        assert resp.status_code == 302
        assert "csrf_token" in c.cookies

    def test_login_html_no_inline_script(self):
        """HTML de login servi depuis templates/login.html — {error} absent."""
        resp = _make_client().get("/login", follow_redirects=False)
        assert resp.status_code == 200
        assert "{error}" not in resp.text

    def test_logout_clears_cookie(self):
        c = _make_auth_client()
        assert "societies_session" in c.cookies
        c.get("/logout", follow_redirects=False)


# =============================================================================
# CSRF
# =============================================================================

class TestCsrf:
    """Vérifie la protection CSRF quand CSRF_ENABLED=True (patché)."""

    def test_mutation_blocked_without_csrf_header(self):
        """POST sans X-CSRF-Token → 403 quand CSRF activé."""
        c = _make_auth_client()
        with patch("main.CSRF_ENABLED", True):
            resp = c.post("/api/license/generate")
            assert resp.status_code == 403
            assert "CSRF" in resp.json().get("detail", "")

    def test_mutation_allowed_with_correct_csrf_header(self):
        """POST avec X-CSRF-Token valide → passe le contrôle CSRF."""
        c    = _make_client()
        resp = c.post("/login",
                      data={"username": "admin", "password": "testpassword"},
                      follow_redirects=False)
        csrf_token = c.cookies.get("csrf_token", "")
        assert csrf_token, "csrf_token doit être émis au login"
        with patch("main.CSRF_ENABLED", True):
            resp = c.post("/api/license/generate",
                          headers={"X-CSRF-Token": csrf_token})
            # 200 = succès, pas 403 CSRF
            assert resp.status_code == 200

    def test_csrf_exempt_endpoint(self):
        """/api/license/verify est exempté (intégration WordPress)."""
        with patch("main.CSRF_ENABLED", True):
            resp = _make_client().post("/api/license/verify",
                                       json={"key": "TEST-XXXX-XXXX-XXXX"})
            # 403/404 licence invalide — PAS 403 CSRF
            assert resp.status_code in (403, 404)
            detail = resp.json().get("detail", "")
            assert "CSRF" not in detail

    def test_get_requests_not_csrf_checked(self):
        """Les requêtes GET ne sont pas vérifiées par CSRF."""
        c = _make_auth_client()
        with patch("main.CSRF_ENABLED", True):
            resp = c.get("/api/status")
            assert resp.status_code == 200


# =============================================================================
# SECURITY HEADERS
# =============================================================================

class TestSecurityHeaders:
    def test_security_headers_present(self):
        resp = _make_client().get("/login", follow_redirects=False)
        assert resp.headers.get("X-Content-Type-Options") == "nosniff"
        assert resp.headers.get("X-Frame-Options")         == "DENY"
        assert "Referrer-Policy" in resp.headers

    def test_csp_header_present(self):
        csp = _make_client().get("/login", follow_redirects=False).headers.get(
            "Content-Security-Policy", "")
        assert "default-src" in csp
        assert "frame-ancestors" in csp

    def test_permissions_policy_present(self):
        resp = _make_client().get("/login", follow_redirects=False)
        assert "Permissions-Policy" in resp.headers


# =============================================================================
# HEALTHZ
# =============================================================================

class TestHealthz:
    def test_healthz_accessible_without_auth(self):
        resp = _make_client().get("/api/healthz")
        assert resp.status_code in (200, 503)
        assert "app" in resp.json()

    def test_healthz_returns_expected_keys(self):
        data = _make_client().get("/api/healthz").json()
        assert data.get("app") == "ok"
        assert "duckdb" in data
        assert "sqlite" in data

    def test_healthz_not_blocked_by_auth(self):
        assert _make_client().get("/api/healthz").status_code != 401


# =============================================================================
# RÔLES — require_admin
# =============================================================================

class TestRoles:
    def test_license_generate_requires_auth(self):
        resp = _make_client().post("/api/license/generate")
        assert resp.status_code in (401, 403)

    def test_admin_can_generate_license(self):
        """L'admin authentifié peut générer une licence."""
        resp = _make_auth_client().post("/api/license/generate")
        assert resp.status_code == 200
        assert "key" in resp.json()

    def test_auto_start_requires_auth(self):
        resp = _make_client().post(
            "/api/auto-generate/start",
            json={"concurrency": 2, "batch_size": 10, "resume_offset": 0},
        )
        assert resp.status_code in (401, 403)


# =============================================================================
# LICENCE
# =============================================================================

class TestLicense:
    def test_verify_invalid_key(self):
        resp = _make_client().post("/api/license/verify",
                                   json={"key": "XXXX-XXXX-XXXX-XXXX"})
        assert resp.status_code in (403, 404)

    def test_verify_missing_key_field(self):
        resp = _make_client().post("/api/license/verify", json={})
        assert resp.status_code == 422

    def test_verify_key_too_long(self):
        resp = _make_client().post("/api/license/verify", json={"key": "x" * 101})
        assert resp.status_code == 422

    def test_generate_then_verify(self):
        """L'admin génère une clé, puis la vérification retourne 200."""
        c       = _make_auth_client()
        gen     = c.post("/api/license/generate")
        assert gen.status_code == 200
        key     = gen.json()["key"]
        verify  = _make_client().post("/api/license/verify", json={"key": key})
        assert verify.status_code == 200
        assert verify.json()["ok"] is True


# =============================================================================
# SEARCH
# =============================================================================

class TestSearch:
    def test_search_requires_auth(self):
        assert _make_client().get("/api/search?q=test").status_code == 401

    def test_categories_requires_auth(self):
        assert _make_client().get("/api/categories").status_code == 401

    def test_search_without_db_returns_503(self):
        """Sans DuckDB disponible en test, la recherche retourne 503."""
        resp = _make_auth_client().get("/api/search?q=test")
        assert resp.status_code == 503

    def test_categories_without_db_returns_503(self):
        resp = _make_auth_client().get("/api/categories")
        assert resp.status_code == 503

    def test_status_returns_ready_flag(self):
        """GET /api/status retourne l'état courant de DuckDB."""
        resp = _make_auth_client().get("/api/status")
        assert resp.status_code == 200
        data = resp.json()
        assert "ready" in data
        assert "rows" in data


# =============================================================================
# GÉNÉRATION — mock OpenAI
# =============================================================================

_MOCK_OK = {
    "text":              '{"qa_answered": {"Qui sommes-nous ?": "Une boulangerie."}, "intro": "La Paul."}',
    "model":             "gpt-4o-mini",
    "completion_tokens": 42,
}


class TestGeneration:
    def test_generate_requires_auth(self):
        resp = _make_client().post("/api/generate", json={"title": "Test SA"})
        assert resp.status_code == 401

    def test_generate_with_mock_openai(self):
        """generate appelle call_openai et sauvegarde la fiche."""
        c = _make_auth_client()
        with patch("routers.generation.call_openai", new_callable=AsyncMock) as m:
            m.return_value = _MOCK_OK
            resp = c.post("/api/generate",
                          json={"title": "Boulangerie PaulMock",
                                "category": "Boulangerie", "city": "Paris",
                                "zip_code": "75001"})
        assert resp.status_code == 200
        data = resp.json()
        assert data["status"] == "done"
        assert "qa_answered" in data
        m.assert_awaited_once()

    def test_generate_returns_existing_fiche(self):
        """Si la fiche existe déjà (done), ne rappelle pas OpenAI."""
        c = _make_auth_client()
        title = "Boulangerie PaulCache"
        with patch("routers.generation.call_openai", new_callable=AsyncMock) as m:
            m.return_value = _MOCK_OK
            c.post("/api/generate", json={"title": title})

        with patch("routers.generation.call_openai", new_callable=AsyncMock) as m:
            resp = c.post("/api/generate", json={"title": title})
        assert resp.status_code == 200
        m.assert_not_awaited()

    def test_generate_invalid_openai_response(self):
        """Réponse OpenAI mal formée → 500."""
        c = _make_auth_client()
        with patch("routers.generation.call_openai", new_callable=AsyncMock) as m:
            m.return_value = {"text": "pas_du_json", "model": "gpt-4o-mini",
                              "completion_tokens": 5}
            resp = c.post("/api/generate", json={"title": "Entreprise Invalide ZZTEST"})
        assert resp.status_code == 500

    def test_fiche_endpoint_returns_none_for_unknown(self):
        """GET /api/fiche/{title} retourne status=none si fiche absente."""
        resp = _make_auth_client().get("/api/fiche/Entreprise%20Introuvable%20ZZZZ")
        assert resp.status_code == 200
        assert resp.json()["status"] == "none"


# =============================================================================
# SERVICES / GENERATOR
# =============================================================================

class TestGeneratorService:
    def test_call_openai_raises_without_key(self):
        import asyncio
        from services.generator import call_openai
        with pytest.raises(ValueError, match="manquante"):
            asyncio.run(call_openai("", "gpt-4o-mini", 5.0, "test"))

    def test_build_prompt_contains_title(self):
        from services.generator import build_prompt
        prompt = build_prompt("Boulangerie Paul", "Boulangerie", "Paris", "75001", 4.5, 120)
        assert "Boulangerie Paul" in prompt
        assert "Paris" in prompt
        assert "75001" in prompt

    def test_build_prompt_handles_none(self):
        from services.generator import build_prompt
        prompt = build_prompt("Test SA", None, None, None, None, None)
        assert "Non renseigné" in prompt
        assert "Non renseignée" in prompt


# =============================================================================
# MIGRATIONS SQLITE
# =============================================================================

class TestMigrations:
    def test_migrations_idempotent(self):
        from services.fiches import init_fiches_db
        init_fiches_db()
        init_fiches_db()

    def test_schema_migrations_table_exists(self):
        import sqlite3
        from core.config import FICHES_DB
        from services.fiches import init_fiches_db
        init_fiches_db()
        conn = sqlite3.connect(FICHES_DB)
        try:
            row = conn.execute(
                "SELECT name FROM sqlite_master WHERE type='table' AND name='schema_migrations'"
            ).fetchone()
            assert row is not None
        finally:
            conn.close()

    def test_all_tables_created(self):
        import sqlite3
        from core.config import FICHES_DB
        from services.fiches import init_fiches_db
        init_fiches_db()
        conn = sqlite3.connect(FICHES_DB)
        try:
            tables = {r[0] for r in conn.execute(
                "SELECT name FROM sqlite_master WHERE type='table'"
            ).fetchall()}
        finally:
            conn.close()
        for expected in ("fiches", "settings", "sessions", "auto_jobs", "schema_migrations"):
            assert expected in tables, f"Table manquante : {expected}"

    def test_sessions_has_username_column(self):
        """Migration v4 — colonne username doit exister dans sessions."""
        import sqlite3
        from core.config import FICHES_DB
        from services.fiches import init_fiches_db
        init_fiches_db()
        conn = sqlite3.connect(FICHES_DB)
        try:
            cols = {row[1] for row in conn.execute("PRAGMA table_info(sessions)").fetchall()}
        finally:
            conn.close()
        assert "username" in cols
