"""
Societies — Endpoints de génération OpenAI (simple, stream, batch)
"""
import json
import logging
import os
import re
import sqlite3
from datetime import datetime

from fastapi import APIRouter, Body, Depends, HTTPException, Request
from fastapi.responses import StreamingResponse

from core.auth import require_admin
from core.config import FICHES_DB, OPENAI_MODEL, OPENAI_TIMEOUT
from core.db import fetch_company
from core.limiter import limiter
from core.utils import format_date_fr, sse
from models import BatchRequest, GenerateRequest
from services.fiches import get_fiche, get_openai_key, save_fiche
from core.utils import is_excluded_category
from services.generator import (
    GENERATION_PROMPT,
    build_prompt,
    call_openai,
    get_active_prompt,
    get_open_questions,
    validate_qa,
)
from services.fiches import get_setting, set_setting

logger   = logging.getLogger("societies")
router   = APIRouter(tags=["generation"])


def _notify_wp_create_page(title: str) -> None:
    """Appelle le webhook WordPress pour créer la page WP de la fiche dès génération."""
    import httpx
    wp_url    = os.getenv("WP_SITE_URL", "").rstrip("/")
    wp_secret = os.getenv("WP_API_PASSWORD", "")
    if not wp_url or not wp_secret:
        return
    try:
        r = httpx.post(
            f"{wp_url}/wp-json/sc/v1/sync-fiche",
            json={"title": title},
            headers={"X-SC-Secret": wp_secret},
            timeout=10,
        )
        logger.info(f"WP sync '{title}' → {r.status_code}")
    except Exception as e:
        logger.warning(f"WP webhook failed for '{title}': {e}")


async def stream_generate(title: str, company_data: dict):
    """Async generator — SSE temps réel pour une génération unique."""
    category = company_data.get("category")
    if not category:
        company_row = fetch_company(title)
        if company_row:
            category = company_row.get("category")
    if is_excluded_category(category):
        logger.info(f"Fiche exclue (stream, catégorie non éligible) : {title} — {category}")
        yield sse("excluded", message="Catégorie non éligible à la génération")
        return

    api_key = get_openai_key()
    if not api_key:
        yield sse("error", message="Clé API OpenAI manquante dans .env")
        return

    yield sse("stage", message="Préparation du prompt...", percent=5)
    prompt = build_prompt(
        title=title,
        category=company_data.get("category"),
        city=company_data.get("city"),
        zip_code=company_data.get("zip_code"),
        rating_value=company_data.get("rating_value"),
        rating_votes=company_data.get("rating_votes"),
    )
    save_fiche(title, "generating")
    yield sse("stage", message="Connexion à OpenAI...", percent=10)

    try:
        from openai import AsyncOpenAI
        client = AsyncOpenAI(api_key=api_key, timeout=OPENAI_TIMEOUT)
        stream = await client.chat.completions.create(
            model=OPENAI_MODEL,
            messages=[{"role": "user", "content": prompt}],
            max_completion_tokens=4000,
            response_format={"type": "json_object"},
            stream=True,
        )
        yield sse("stage", message="Génération en cours...", percent=15)

        full_text        = ""
        token_count      = 0
        ESTIMATED_TOKENS = 900

        async for chunk in stream:
            delta = chunk.choices[0].delta.content or ""
            if delta:
                full_text   += delta
                token_count += 1
                percent = min(15 + int(token_count / ESTIMATED_TOKENS * 70), 85)
                yield sse("token", token=delta, count=token_count, percent=percent)

        yield sse("stage", message="Analyse du JSON...", percent=88)
        text = full_text.strip()
        # Certains modèles enveloppent le JSON dans des blocs markdown
        text = re.sub(r'^```(?:json)?\s*', '', text)
        text = re.sub(r'\s*```$', '', text).strip()
        if not text:
            raise ValueError(f"Réponse vide reçue du modèle ({OPENAI_MODEL})")
        # Répare le JSON tronqué : ferme les accolades/crochets manquants
        open_b = text.count('{') - text.count('}')
        open_br = text.count('[') - text.count(']')
        if open_b > 0 or open_br > 0:
            text = text.rstrip(',').rstrip()
            text += ']' * open_br + '}' * open_b
        parsed = json.loads(text)
        if not isinstance(parsed, dict):
            raise ValueError(f"Format JSON invalide — reçu : {text[:200]}")
        validate_qa(parsed)

        qa_answered    = parsed["qa_answered"]
        intro          = parsed.get("intro", "")
        bonus          = parsed.get("bonus", "")
        open_questions = [{"q": q, "r": ""} for q in get_open_questions(title, category or "")]
        date_fr        = format_date_fr(datetime.now().strftime("%Y-%m-%d"))

        yield sse("stage", message="Sauvegarde...", percent=95)
        save_fiche(title, "done",
                   qa_answered=json.dumps(qa_answered, ensure_ascii=False),
                   qa_open=json.dumps(open_questions, ensure_ascii=False),
                   intro_text=intro,
                   bonus_text=bonus,
                   model=OPENAI_MODEL,
                   completion_tokens=token_count)

        yield sse("done",
                  qa_answered=qa_answered,
                  intro_text=intro,
                  bonus_text=bonus,
                  open_questions=[q["q"] for q in open_questions],
                  date_fr=date_fr,
                  model=OPENAI_MODEL,
                  completion_tokens=token_count,
                  percent=100)
        logger.info(f"Fiche générée (stream) : {title} — {token_count} tokens")

    except Exception as e:
        logger.error(f"stream_generate error for '{title}': {e}")
        save_fiche(title, "error", error=str(e))
        yield sse("error", message=str(e))


@router.post("/generate/stream")
@limiter.limit("120/minute")
async def generate_stream_endpoint(request: Request, data: dict = Body(...)):
    title = data.get("title", "").strip()
    if not title:
        raise HTTPException(status_code=400, detail="title requis")
    return StreamingResponse(
        stream_generate(title, data),
        media_type="text/event-stream",
        headers={"Cache-Control": "no-cache", "X-Accel-Buffering": "no"},
    )


@router.get("/fiche/{title:path}")
def get_fiche_endpoint(title: str):
    fiche = get_fiche(title)
    if not fiche:
        return {"status": "none"}
    if fiche["status"] == "done":
        try:
            if fiche.get("qa_answered"):
                fiche["qa_answered"] = json.loads(fiche["qa_answered"])
            if fiche.get("qa_open"):
                fiche["qa_open"] = json.loads(fiche["qa_open"])
        except Exception:
            pass
        if fiche.get("generated_at"):
            fiche["date_fr"] = format_date_fr(fiche["generated_at"])
    company = fetch_company(title)
    if company:
        fiche["company_info"] = {
            "category":     company.get("category", ""),
            "city":         company.get("city", ""),
            "zip_code":     company.get("zip_code", ""),
            "phone":        company.get("phone", ""),
            "url":          company.get("url", ""),
            "rating_value": company.get("rating_value"),
            "rating_votes": company.get("rating_votes"),
            "emails":       company.get("emails", []),
        }
    else:
        fiche["company_info"] = None
    if fiche["status"] == "done":
        fiche["open_questions"] = get_open_questions(
            title, (fiche["company_info"] or {}).get("category", "")
        )
    return fiche


@router.post("/generate")
@limiter.limit("30/minute")
async def generate_fiche(request: Request, data: GenerateRequest):
    title    = data.title.strip()
    category = data.category
    # Si la catégorie n'est pas fournie dans la requête, on la récupère depuis la base
    if not category:
        company_row = fetch_company(title)
        if company_row:
            category = company_row.get("category")
    if is_excluded_category(category):
        logger.info(f"Fiche exclue (catégorie non éligible) : {title} — {category}")
        return {"status": "excluded", "title": title, "reason": "Catégorie non éligible à la génération"}
    existing = get_fiche(title)
    if existing and existing["status"] == "done":
        try:
            if existing.get("qa_answered"):
                existing["qa_answered"] = json.loads(existing["qa_answered"])
            if existing.get("qa_open"):
                existing["qa_open"] = json.loads(existing["qa_open"])
        except Exception:
            pass
        return existing

    save_fiche(title, "generating")
    prompt = build_prompt(title, data.category, data.city, data.zip_code,
                          data.rating_value, data.rating_votes)
    try:
        result = await call_openai(get_openai_key(), OPENAI_MODEL, OPENAI_TIMEOUT, prompt)
        parsed = json.loads(result["text"])
        if not isinstance(parsed, dict):
            raise ValueError("Format inattendu")
        validate_qa(parsed)
        intro = parsed.get("intro", "")
        bonus = parsed.get("bonus", "")
        open_qs = [{"q": q, "r": ""} for q in get_open_questions(title, category or "")]
        save_fiche(title, "done",
                   qa_answered=json.dumps(parsed["qa_answered"], ensure_ascii=False),
                   qa_open=json.dumps(open_qs, ensure_ascii=False),
                   intro_text=intro,
                   bonus_text=bonus,
                   model=result["model"],
                   completion_tokens=result["completion_tokens"])
        logger.info(f"Fiche générée : {title} — {result['completion_tokens']} tokens")
        _notify_wp_create_page(title)
        return {
            "status":            "done",
            "qa_answered":       parsed["qa_answered"],
            "intro_text":        intro,
            "bonus_text":        bonus,
            "open_questions":    get_open_questions(title, category or ""),
            "date_fr":           format_date_fr(datetime.now().strftime("%Y-%m-%d")),
            "model":             result["model"],
            "completion_tokens": result["completion_tokens"],
        }
    except Exception as e:
        logger.error(f"generate_fiche error for '{title}': {e}")
        save_fiche(title, "error", error=str(e))
        raise HTTPException(status_code=500, detail=str(e))


@router.post("/generate/batch")
@limiter.limit("10/minute")
async def generate_batch(request: Request, data: BatchRequest):
    import asyncio
    companies   = data.companies[:data.max]
    concurrency = data.concurrency
    results     = []
    to_generate = []

    for company in companies:
        title    = company.title.strip()
        if is_excluded_category(getattr(company, "category", None)):
            results.append({"title": title, "status": "excluded"})
            continue
        existing = get_fiche(title)
        if existing and existing["status"] == "done":
            results.append({"title": title, "status": "already_done"})
        else:
            save_fiche(title, "generating")
            to_generate.append(company)

    semaphore = asyncio.Semaphore(concurrency)

    async def _generate_one(company) -> dict:
        title = company.title.strip()
        async with semaphore:
            prompt = build_prompt(title, company.category, company.city,
                                  company.zip_code, company.rating_value, company.rating_votes)
            try:
                result = await call_openai(get_openai_key(), OPENAI_MODEL, OPENAI_TIMEOUT, prompt)
                parsed = json.loads(result["text"])
                if not isinstance(parsed, dict) or "qa_answered" not in parsed:
                    raise ValueError("Format inattendu")
                open_qs = [{"q": q, "r": ""} for q in get_open_questions(title, company.category or "")]
                save_fiche(title, "done",
                           qa_answered=json.dumps(parsed["qa_answered"], ensure_ascii=False),
                           qa_open=json.dumps(open_qs, ensure_ascii=False),
                           intro_text=parsed.get("intro", ""),
                           bonus_text=parsed.get("bonus", ""),
                           model=result["model"],
                           completion_tokens=result["completion_tokens"])
                _notify_wp_create_page(title)
                return {"title": title, "status": "done",
                        "qa_answered_count": len(parsed["qa_answered"])}
            except Exception as e:
                logger.error(f"batch error for '{title}': {e}")
                save_fiche(title, "error", error=str(e))
                return {"title": title, "status": "error", "error": str(e)}

    parallel_results = await asyncio.gather(*[_generate_one(c) for c in to_generate])
    results.extend(parallel_results)

    done_count  = sum(1 for r in results if r["status"] == "done")
    error_count = sum(1 for r in results if r["status"] == "error")
    logger.info(f"Batch terminé : {done_count} ok / {error_count} erreurs")
    return {
        "results":      results,
        "total":        len(results),
        "done":         done_count,
        "errors":       error_count,
        "already_done": len(results) - done_count - error_count,
        "concurrency":  concurrency,
    }


# =============================================================================
# RE-GÉNÉRATION DES INTROS EXISTANTES (correction incohérences prompt)
# =============================================================================

@router.post("/generate/fix-intros", dependencies=[Depends(require_admin)])
async def fix_existing_intros(data: dict = Body(default={})):
    """
    Re-génère intro_text de TOUTES les fiches status=done, par batches de 500.
    Paramètre optionnel : concurrency (défaut 6), offset (reprendre depuis).
    Retourne progression + total restant pour pouvoir être appelé en boucle.
    """
    import asyncio
    concurrency = min(int(data.get("concurrency", 6)), 15)
    offset      = int(data.get("offset", 0))
    batch_size  = 500
    api_key     = get_openai_key()

    # Compte le total
    conn = sqlite3.connect(FICHES_DB)
    try:
        total_count = conn.execute(
            "SELECT COUNT(*) FROM fiches WHERE status='done' AND intro_text IS NOT NULL AND intro_text != ''"
        ).fetchone()[0]
        rows = conn.execute(
            "SELECT id, company_title FROM fiches "
            "WHERE status='done' AND intro_text IS NOT NULL AND intro_text != '' "
            "ORDER BY id ASC LIMIT ? OFFSET ?",
            [batch_size, offset],
        ).fetchall()
    finally:
        conn.close()

    if not rows:
        return {"message": "Toutes les fiches ont été traitées.", "fixed": 0, "errors": 0,
                "total_done": total_count, "next_offset": None}

    semaphore = asyncio.Semaphore(concurrency)

    async def _fix_one(fiche_id: int, title: str) -> dict:
        async with semaphore:
            try:
                company = fetch_company(title) or {}
                prompt = build_prompt(
                    title=title,
                    category=company.get("category"),
                    city=company.get("city"),
                    zip_code=company.get("zip_code"),
                    rating_value=company.get("rating_value"),
                    rating_votes=company.get("rating_votes"),
                )
                result = await call_openai(api_key, OPENAI_MODEL, OPENAI_TIMEOUT, prompt)
                raw = result.get("content", "")
                m = re.search(r'\{.*\}', raw, re.DOTALL)
                if not m:
                    raise ValueError("JSON non trouvé")
                parsed = json.loads(m.group())
                new_intro = parsed.get("intro", "").strip()
                if not new_intro:
                    raise ValueError("intro vide")
                conn2 = sqlite3.connect(FICHES_DB)
                try:
                    conn2.execute(
                        "UPDATE fiches SET intro_text=? WHERE id=?",
                        [new_intro, fiche_id],
                    )
                    conn2.commit()
                finally:
                    conn2.close()
                return {"status": "fixed"}
            except Exception as e:
                logger.error(f"fix-intros '{title}': {e}")
                return {"status": "error", "error": str(e)}

    results   = await asyncio.gather(*[_fix_one(r[0], r[1]) for r in rows])
    fixed     = sum(1 for r in results if r["status"] == "fixed")
    errors    = sum(1 for r in results if r["status"] == "error")
    next_off  = offset + len(rows) if len(rows) == batch_size else None

    return {
        "batch":       len(rows),
        "fixed":       fixed,
        "errors":      errors,
        "offset":      offset,
        "next_offset": next_off,       # None = terminé
        "total_fiches": total_count,
        "remaining":   max(0, total_count - offset - len(rows)),
    }


# =============================================================================
# SYNC BULK — crée les pages WP pour toutes les fiches sans page
# =============================================================================

_sync_wp_state: dict = {"running": False, "done": 0, "errors": 0, "total": 0, "finished": False}

@router.post("/generate/sync-wp-pages", dependencies=[Depends(require_admin)])
async def sync_wp_pages_start(data: dict = Body(default={})):
    """
    Lance en arrière-plan la création des pages WP pour toutes les fiches status=done.
    Appeler GET /generate/sync-wp-pages pour suivre la progression.
    """
    import asyncio as _aio
    if _sync_wp_state["running"]:
        return {"status": "already_running", **_sync_wp_state}

    concurrency = min(int(data.get("concurrency", 5)), 20)

    async def _run():
        import asyncio as _aio
        import httpx as _httpx
        from core.db import get_conn as _get_conn

        _sync_wp_state.update({"running": True, "done": 0, "errors": 0, "total": 0, "finished": False})

        conn = sqlite3.connect(FICHES_DB)
        try:
            rows = conn.execute(
                "SELECT company_title FROM fiches WHERE status='done' AND deleted_at IS NULL ORDER BY id ASC"
            ).fetchall()
        finally:
            conn.close()

        titles = [r[0] for r in rows if r[0]]
        _sync_wp_state["total"] = len(titles)

        wp_url    = os.getenv("WP_SITE_URL", "").rstrip("/")
        wp_secret = os.getenv("WP_API_PASSWORD", "")
        if not wp_url or not wp_secret:
            _sync_wp_state.update({"running": False, "finished": True})
            return

        # Pre-fetch city + category from DuckDB so WP doesn't need to call back to the API
        # for every single company (eliminates N×2 MySQL queries from sc_resolve_page_parent).
        meta: dict[str, dict] = {}
        try:
            titles_set = set(titles)
            dconn = _get_conn()
            try:
                cursor = dconn.execute("SELECT title, city, category FROM companies")
                while True:
                    batch = cursor.fetchmany(50_000)
                    if not batch:
                        break
                    for r in batch:
                        if r[0] in titles_set:
                            meta[r[0]] = {"city": r[1] or "", "category": r[2] or ""}
            finally:
                dconn.close()
            logger.info(f"sync-wp-pages : {len(meta)} entreprises trouvées dans DuckDB")
        except Exception as e:
            logger.warning(f"sync-wp-pages : DuckDB meta fetch échoué ({e}) — ville/catégorie non transmises")

        queue: _aio.Queue = _aio.Queue()
        for t in titles:
            queue.put_nowait(t)

        # Shared client — one TCP connection pool for all workers, properly closed at the end.
        limits = _httpx.Limits(max_connections=concurrency + 4, max_keepalive_connections=concurrency)
        async with _httpx.AsyncClient(timeout=25, limits=limits) as client:
            async def _worker():
                while True:
                    try:
                        title = queue.get_nowait()
                    except Exception:
                        break
                    payload = {"title": title, **meta.get(title, {})}
                    for attempt in range(3):
                        try:
                            r = await client.post(
                                f"{wp_url}/wp-json/sc/v1/sync-fiche",
                                json=payload,
                                headers={"X-SC-Secret": wp_secret},
                            )
                            if r.status_code in (200, 201):
                                _sync_wp_state["done"] += 1
                            elif r.status_code >= 500 and attempt < 2:
                                await _aio.sleep(2.0 * (attempt + 1))
                                continue
                            else:
                                _sync_wp_state["errors"] += 1
                                logger.warning(
                                    f"WP sync '{title[:60]}' → HTTP {r.status_code}: {r.text[:200]}"
                                )
                            break
                        except _httpx.TimeoutException:
                            if attempt < 2:
                                await _aio.sleep(3.0 * (attempt + 1))
                            else:
                                _sync_wp_state["errors"] += 1
                                logger.warning(f"WP sync '{title[:60]}' → timeout (3 tentatives)")
                        except Exception as e:
                            _sync_wp_state["errors"] += 1
                            logger.warning(f"WP sync '{title[:60]}' → {e}")
                            break
                    queue.task_done()

            workers = [_aio.create_task(_worker()) for _ in range(concurrency)]
            await _aio.gather(*workers)

        _sync_wp_state.update({"running": False, "finished": True})
        logger.info(f"sync-wp-pages terminé : {_sync_wp_state['done']} ok / {_sync_wp_state['errors']} erreurs")

    import asyncio as _aio
    _aio.create_task(_run())
    return {"status": "started", "total": _sync_wp_state["total"]}


@router.get("/generate/sync-wp-pages", dependencies=[Depends(require_admin)])
def sync_wp_pages_status():
    """Retourne l'état courant de la synchronisation WP pages."""
    return _sync_wp_state


# =============================================================================
# GESTION DU PROMPT DE GÉNÉRATION
# =============================================================================

@router.get("/generate/prompt", dependencies=[Depends(require_admin)])
def get_prompt():
    """Retourne le prompt actif (custom DB ou défaut)."""
    custom = get_setting("generation_prompt")
    return {
        "prompt":     custom or GENERATION_PROMPT,
        "is_custom":  bool(custom and custom.strip()),
        "default":    GENERATION_PROMPT,
    }


@router.post("/generate/prompt", dependencies=[Depends(require_admin)])
def save_prompt(body: dict = Body(...)):
    """Sauvegarde un prompt personnalisé. Envoyer {"prompt": ""} pour remettre le défaut."""
    prompt = body.get("prompt", "").strip()
    if prompt:
        # Validation basique : le prompt doit contenir les placeholders obligatoires
        required = ["{nom}", "{categorie}", "{ville}", "{note}"]
        missing  = [p for p in required if p not in prompt]
        if missing:
            from fastapi import HTTPException
            raise HTTPException(
                status_code=422,
                detail=f"Placeholders manquants dans le prompt : {', '.join(missing)}"
            )
        set_setting("generation_prompt", prompt)
        return {"status": "saved", "is_custom": True}
    else:
        # Prompt vide = remettre le défaut
        set_setting("generation_prompt", "")
        return {"status": "reset", "is_custom": False}
