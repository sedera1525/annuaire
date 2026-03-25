"""
Societies — Endpoints SEO (secteurs, villes, top listes)
"""
from fastapi import APIRouter

from core.db import get_conn

router = APIRouter(tags=["seo"])

_COLS_SEO = ["title", "category", "city", "zip_code", "phone", "url",
             "rating_value", "rating_votes"]


@router.get("/seo/sector/{sector:path}")
def seo_sector(sector: str, limit: int = 20):
    conn = get_conn()
    try:
        rows = conn.execute("""
            SELECT title, category, city, zip_code, phone, url, rating_value, rating_votes
            FROM companies
            WHERE LOWER(category) = LOWER(?)
            ORDER BY rating_votes DESC NULLS LAST
            LIMIT ?
        """, [sector, min(int(limit), 200)]).fetchall()
        return {"sector": sector, "total": len(rows),
                "results": [dict(zip(_COLS_SEO, r)) for r in rows]}
    finally:
        conn.close()


@router.get("/seo/city/{city:path}")
def seo_city(city: str, limit: int = 20):
    conn = get_conn()
    try:
        rows = conn.execute("""
            SELECT title, category, city, zip_code, phone, url, rating_value, rating_votes
            FROM companies
            WHERE LOWER(city) = LOWER(?)
            ORDER BY rating_votes DESC NULLS LAST
            LIMIT ?
        """, [city, min(int(limit), 200)]).fetchall()
        return {"city": city, "total": len(rows),
                "results": [dict(zip(_COLS_SEO, r)) for r in rows]}
    finally:
        conn.close()


@router.get("/seo/top-sectors")
def seo_top_sectors(limit: int = 50):
    conn = get_conn()
    try:
        rows = conn.execute("""
            SELECT category, COUNT(*) as count
            FROM companies
            WHERE category IS NOT NULL AND category != ''
            GROUP BY category ORDER BY count DESC
            LIMIT ?
        """, [min(int(limit), 200)]).fetchall()
        return {"results": [{"sector": r[0], "count": r[1]} for r in rows]}
    finally:
        conn.close()


@router.get("/seo/top-cities")
def seo_top_cities(limit: int = 50):
    conn = get_conn()
    try:
        rows = conn.execute("""
            SELECT city, COUNT(*) as count
            FROM companies
            WHERE city IS NOT NULL AND city != ''
            GROUP BY city ORDER BY count DESC
            LIMIT ?
        """, [min(int(limit), 200)]).fetchall()
        return {"results": [{"city": r[0], "count": r[1]} for r in rows]}
    finally:
        conn.close()
