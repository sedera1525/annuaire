"""
Societies — Tests de la prospection (entreprises sans site web, joignables).

Cohérent avec test_api.py : sans DuckDB prête, les endpoints data renvoient 503.
La logique de filtrage est testée en pur (sans base). La correction des données
réelles est vérifiée en live (voir /api/prospection sur l'instance).
"""

import os

import bcrypt as _bcrypt

os.environ.setdefault("APP_USERNAME", _bcrypt.hashpw(b"admin", _bcrypt.gensalt()).decode())
os.environ.setdefault("APP_PASSWORD", _bcrypt.hashpw(b"testpassword", _bcrypt.gensalt()).decode())
os.environ.setdefault("SECRET_KEY", "test-secret-key-for-testing-only-32ch")
os.environ.setdefault("OPENAI_API_KEY", "sk-test-key")
os.environ["CSRF_ENABLED"] = "false"
os.environ["TESTING"] = "true"

from fastapi.testclient import TestClient  # noqa: E402

from main import app  # noqa: E402
from routers.prospection import (  # noqa: E402
    _build_conditions,
    _demo_url,
    _emails_from_contacts,
    _to_slug,
)


def _auth_client() -> TestClient:
    c = TestClient(app, raise_server_exceptions=False)
    c.post("/login", data={"username": "admin", "password": "testpassword"},
           follow_redirects=False)
    return c


class TestProspectionLogic:
    def test_base_condition_targets_no_website_reachable(self):
        where, params = _build_conditions(None, None)
        assert "url = ''" in where
        assert "phone" in where and "Mail" in where
        assert params == []

    def test_city_filter_adds_clause_and_param(self):
        where, params = _build_conditions("Paris", None)
        assert "city" in where.lower()
        assert params == ["%Paris%"]

    def test_category_filter(self):
        where, params = _build_conditions(None, "Restaurant")
        assert "category" in where.lower()
        assert params == ["%Restaurant%"]

    def test_emails_extracted_from_contacts(self):
        raw = '[{"type":"Mail","value":"a@b.fr"},{"type":"Telephone","value":"01"}]'
        assert _emails_from_contacts(raw) == ["a@b.fr"]

    def test_emails_robust_to_bad_input(self):
        assert _emails_from_contacts(None) == []
        assert _emails_from_contacts("pas du json") == []

    def test_to_slug_matches_frontend(self):
        # Doit reproduire lib/slug.ts (toSlug) du front Next.js pour que le lien résolve
        assert _to_slug("Café de la Paix") == "cafe-de-la-paix"
        assert _to_slug("JACK & JONES") == "jack-jones"
        assert _to_slug("  Boulangerie  Dupont  ") == "boulangerie-dupont"
        assert _to_slug("Été 2024 — Éléphant") == "ete-2024-elephant"

    def test_demo_url_is_absolute_fiche_link(self):
        url = _demo_url("Café de la Paix")
        assert url.endswith("/entreprise/cafe-de-la-paix")
        assert url.startswith("http")

    def test_demo_url_empty_title(self):
        assert _demo_url("") == ""
        assert _demo_url(None) == ""


class TestProspectionRoutes:
    def test_api_requires_auth(self):
        resp = TestClient(app, raise_server_exceptions=False).get("/api/prospection")
        assert resp.status_code == 401

    def test_export_requires_auth(self):
        resp = TestClient(app, raise_server_exceptions=False).get("/api/prospection/export")
        assert resp.status_code == 401

    def test_page_requires_auth(self):
        resp = TestClient(app, raise_server_exceptions=False).get(
            "/prospection", follow_redirects=False,
        )
        assert resp.status_code == 302

    def test_api_without_db_returns_503(self):
        resp = _auth_client().get("/api/prospection")
        assert resp.status_code == 503

    def test_page_authed_serves_html(self):
        resp = _auth_client().get("/prospection")
        assert resp.status_code == 200
        assert "text/html" in resp.headers["content-type"]
