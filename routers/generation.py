"""
Societies — Endpoints de génération OpenAI (simple, stream, batch)
"""
import json
import logging
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
    OPEN_QUESTIONS_TEMPLATE,
    build_prompt,
    call_openai,
    validate_qa,
)

logger   = logging.getLogger("societies")
router   = APIRouter(tags=["generation"])


async def stream_generate(title: str, company_data: dict):
    """Async generator — SSE temps réel pour une génération unique."""
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
        open_questions = [{"q": q.replace("{nom}", title), "r": ""} for q in OPEN_QUESTIONS_TEMPLATE]
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
        fiche["open_questions"] = [
            q.replace("{nom}", title) for q in OPEN_QUESTIONS_TEMPLATE
        ]
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
    return fiche


@router.post("/generate")
@limiter.limit("30/minute")
async def generate_fiche(request: Request, data: GenerateRequest):
    title    = data.title.strip()
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
        open_qs = [{"q": q.replace("{nom}", title), "r": ""} for q in OPEN_QUESTIONS_TEMPLATE]
        save_fiche(title, "done",
                   qa_answered=json.dumps(parsed["qa_answered"], ensure_ascii=False),
                   qa_open=json.dumps(open_qs, ensure_ascii=False),
                   intro_text=intro,
                   bonus_text=bonus,
                   model=result["model"],
                   completion_tokens=result["completion_tokens"])
        logger.info(f"Fiche générée : {title} — {result['completion_tokens']} tokens")
        return {
            "status":            "done",
            "qa_answered":       parsed["qa_answered"],
            "intro_text":        intro,
            "bonus_text":        bonus,
            "open_questions":    [q.replace("{nom}", title) for q in OPEN_QUESTIONS_TEMPLATE],
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
                open_qs = [{"q": q.replace("{nom}", title), "r": ""} for q in OPEN_QUESTIONS_TEMPLATE]
                save_fiche(title, "done",
                           qa_answered=json.dumps(parsed["qa_answered"], ensure_ascii=False),
                           qa_open=json.dumps(open_qs, ensure_ascii=False),
                           intro_text=parsed.get("intro", ""),
                           bonus_text=parsed.get("bonus", ""),
                           model=result["model"],
                           completion_tokens=result["completion_tokens"])
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
    Re-génère uniquement le champ intro_text des fiches déjà générées (status=done).
    Utilise le prompt amélioré pour corriger les "Cependant...", "La majorité..." sans antécédent, etc.
    Paramètres optionnels : limit (défaut 50), concurrency (défaut 5).
    """
    import asyncio
    limit       = min(int(data.get("limit", 50)), 500)
    concurrency = min(int(data.get("concurrency", 5)), 10)
    api_key     = get_openai_key()

    # Récupère les fiches done avec intro_text
    conn = sqlite3.connect(FICHES_DB)
    try:
        rows = conn.execute(
            "SELECT id, company_title FROM fiches "
            "WHERE status='done' AND intro_text IS NOT NULL AND intro_text != '' "
            "ORDER BY generated_at DESC LIMIT ?",
            [limit],
        ).fetchall()
    finally:
        conn.close()

    if not rows:
        return {"message": "Aucune fiche à corriger.", "fixed": 0, "errors": 0}

    semaphore = asyncio.Semaphore(concurrency)
    fixed = 0
    errors = 0

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
                # Prompt ciblé : on ne demande que l'intro
                intro_prompt = prompt.replace(
                    'génère :\n1. Un texte introductif',
                    'génère UNIQUEMENT :\n1. Un texte introductif'
                ) + "\n\nIMPORTANT : Réponds avec un JSON contenant UNIQUEMENT la clé \"intro\", \"bonus\" et \"qa_answered\" (qa_answered peut être une liste vide [])."

                result = await call_openai(api_key, OPENAI_MODEL, OPENAI_TIMEOUT, intro_prompt)
                raw = result.get("content", "")
                # Extrait le JSON
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
                        "UPDATE fiches SET intro_text=?, generated_at=datetime('now') WHERE id=?",
                        [new_intro, fiche_id],
                    )
                    conn2.commit()
                finally:
                    conn2.close()
                return {"title": title, "status": "fixed"}
            except Exception as e:
                logger.error(f"fix-intros error for '{title}': {e}")
                return {"title": title, "status": "error", "error": str(e)}

    results = await asyncio.gather(*[_fix_one(r[0], r[1]) for r in rows])
    fixed  = sum(1 for r in results if r["status"] == "fixed")
    errors = sum(1 for r in results if r["status"] == "error")

    return {
        "total":  len(results),
        "fixed":  fixed,
        "errors": errors,
        "details": results,
    }
