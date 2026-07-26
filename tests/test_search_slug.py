"""
Societies — Résolution d'une fiche par slug (routers.search.get_company_by_slug).

Reproduit un bug de production : les noms **accentués** (« Café ») n'étaient jamais
résolus car le slug enlève les accents (café→cafe) alors que la requête SQL cherchait
la forme accentuée. Testé sur une base DuckDB **en mémoire** (pas la vraie base).
"""

import os

os.environ.setdefault("SECRET_KEY", "test-secret-key-for-testing-only-32ch")
os.environ.setdefault("OPENAI_API_KEY", "sk-test-key")

import duckdb  # noqa: E402

from routers import search  # noqa: E402


def _memory_companies(titles: list[str]) -> duckdb.DuckDBPyConnection:
    con = duckdb.connect(":memory:")
    con.execute(
        "CREATE TABLE companies (title VARCHAR, city VARCHAR, category VARCHAR, zip_code VARCHAR)"
    )
    for t in titles:
        con.execute("INSERT INTO companies VALUES (?, 'Paris', 'Restaurant', '75008')", [t])
    return con


def test_by_slug_resolves_accented_name(monkeypatch):
    con = _memory_companies(["Autre Chose", "Azur Café"])
    monkeypatch.setattr(search, "get_conn", lambda: con)
    res = search.get_company_by_slug("azur-cafe")
    assert res is not None
    assert res["title"] == "Azur Café"


def test_by_slug_resolves_plain_name(monkeypatch):
    con = _memory_companies(["Los Brothers"])
    monkeypatch.setattr(search, "get_conn", lambda: con)
    res = search.get_company_by_slug("los-brothers")
    assert res is not None
    assert res["title"] == "Los Brothers"


def test_by_slug_no_false_positive_on_similar_slug(monkeypatch):
    # "Azur Café 06" a un slug différent ("azur-cafe-06") : ne doit pas être renvoyé.
    con = _memory_companies(["Azur Café 06"])
    monkeypatch.setattr(search, "get_conn", lambda: con)
    assert search.get_company_by_slug("azur-cafe") is None
