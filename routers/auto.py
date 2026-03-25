"""
Societies — Auto-génération en arrière-plan (avec suivi par tête)
"""
import asyncio
import json
import logging
import sqlite3
from datetime import datetime

from fastapi import APIRouter, Depends, HTTPException

from core.auth import require_admin
from core.config import FICHES_DB, OPENAI_MODEL, OPENAI_TIMEOUT
from core.db import db_state, get_conn
from core.utils import is_excluded_category
from models import AutoStartRequest
from services.fiches import get_openai_key, get_last_auto_job, save_auto_job, save_fiche, update_auto_job
from services.generator import build_prompt, call_openai, validate_qa

logger = logging.getLogger("societies")
router = APIRouter(tags=["auto-generate"])


auto_state: dict = {
    "running":        False,
    "concurrency":    6,
    "batch_size":     50,
    "processed":      0,
    "done":           0,
    "errors":         0,
    "total":          0,
    "offset":         0,
    "started_at":     None,
    "last_activity":  None,
}
_auto_task:         asyncio.Task       = None
_head_pause_events: list[asyncio.Event] = []
_auto_job_id:       int | None          = None


async def _auto_generate_loop():
    global auto_state, _head_pause_events, _auto_job_id
    concurrency = auto_state["concurrency"]
    batch_size  = auto_state["batch_size"]

    _head_pause_events = [asyncio.Event() for _ in range(concurrency)]
    for e in _head_pause_events:
        e.set()

    auto_state["heads"] = [
        {"id": i, "status": "idle", "title": "", "done": 0, "errors": 0}
        for i in range(concurrency)
    ]

    slot_queue: asyncio.Queue = asyncio.Queue()
    for i in range(concurrency):
        await slot_queue.put(i)

    async def _one(company: dict) -> str:
        title = company["title"].strip()
        slot  = await slot_queue.get()
        try:
            auto_state["heads"][slot]["status"] = "working"
            auto_state["heads"][slot]["title"]  = title

            while not _head_pause_events[slot].is_set():
                auto_state["heads"][slot]["status"] = "paused"
                await asyncio.sleep(0.3)
            auto_state["heads"][slot]["status"] = "working"

            prompt = build_prompt(
                title,
                company.get("category"),
                company.get("city"),
                company.get("zip_code"),
                company.get("rating_value"),
                company.get("rating_votes"),
            )
            result = await call_openai(get_openai_key(), OPENAI_MODEL, OPENAI_TIMEOUT, prompt)
            parsed = json.loads(result["text"])
            if not isinstance(parsed, dict):
                raise ValueError("Format inattendu")
            validate_qa(parsed)
            save_fiche(title, "done",
                       qa_answered=json.dumps(parsed["qa_answered"], ensure_ascii=False),
                       intro_text=parsed.get("intro", ""),
                       model=result["model"],
                       completion_tokens=result["completion_tokens"])
            auto_state["heads"][slot]["done"] += 1
            auto_state["done"] += 1
            auto_state["processed"] += 1
            return "done"
        except asyncio.CancelledError:
            raise
        except Exception as e:
            logger.error(f"auto_generate error for '{title}': {e}")
            save_fiche(title, "error", error=str(e))
            auto_state["heads"][slot]["errors"] += 1
            auto_state["errors"] += 1
            auto_state["processed"] += 1
            return "error"
        finally:
            auto_state["heads"][slot]["status"] = "idle"
            auto_state["heads"][slot]["title"]  = ""
            await slot_queue.put(slot)

    offset = auto_state["offset"]
    try:
        while auto_state["running"]:
            try:
                conn  = get_conn()
                total = conn.execute("SELECT COUNT(*) FROM companies").fetchone()[0]
                rows  = conn.execute(
                    "SELECT title, category, city, zip_code, rating_value "
                    "FROM companies LIMIT ? OFFSET ?",
                    [batch_size, offset],
                ).fetchall()
                conn.close()
            except asyncio.CancelledError:
                raise
            except Exception as e:
                logger.warning(f"auto_generate batch fetch error: {e}")
                await asyncio.sleep(5)
                continue

            auto_state["total"] = total
            if not rows:
                break

            titles       = [r[0] for r in rows]
            placeholders = ",".join("?" * len(titles))
            fc = sqlite3.connect(FICHES_DB)
            try:
                already = {row[0] for row in fc.execute(
                    f"SELECT company_title FROM fiches "
                    f"WHERE company_title IN ({placeholders}) "
                    f"AND status IN ('done','generating') AND deleted_at IS NULL",
                    titles,
                ).fetchall()}
            finally:
                fc.close()

            to_generate = [
                {"title": r[0], "category": r[1], "city": r[2],
                 "zip_code": r[3], "rating_value": r[4], "rating_votes": None}
                for r in rows
                if r[0] not in already and not is_excluded_category(r[1])
            ]

            if to_generate:
                for c in to_generate:
                    save_fiche(c["title"], "generating")
                await asyncio.gather(*[_one(c) for c in to_generate])

            offset += batch_size
            auto_state["offset"]        = offset
            auto_state["last_activity"] = datetime.now().isoformat()
            # Persistance — survit aux crashs / redémarrages
            if _auto_job_id:
                try:
                    update_auto_job(_auto_job_id, offset,
                                    auto_state["done"], auto_state["errors"])
                except Exception:
                    pass
            await asyncio.sleep(0.5)

    except asyncio.CancelledError:
        pass
    finally:
        auto_state["running"] = False
        for h in auto_state.get("heads", []):
            h["status"] = "idle"
            h["title"]  = ""
        if _auto_job_id:
            try:
                update_auto_job(_auto_job_id, auto_state["offset"],
                                auto_state["done"], auto_state["errors"],
                                status="stopped")
            except Exception:
                pass
        logger.info("Auto-génération terminée")


@router.post("/auto-generate/start", dependencies=[Depends(require_admin)])
async def auto_generate_start(data: AutoStartRequest):
    global _auto_task, auto_state, _auto_job_id
    if auto_state["running"]:
        return {"status": "already_running",
                "concurrency": auto_state["concurrency"],
                "processed":   auto_state["processed"],
                "done":        auto_state["done"]}
    auto_state.update({
        "running":      True,
        "concurrency":  data.concurrency,
        "batch_size":   data.batch_size,
        "processed":    0,
        "done":         0,
        "errors":       0,
        "offset":       data.resume_offset,
        "heads":        [],
        "started_at":   datetime.now().isoformat(),
        "last_activity": None,
    })
    # Persistance — enregistre le job en SQLite avant de démarrer
    try:
        _auto_job_id = save_auto_job(data.concurrency, data.batch_size, data.resume_offset)
    except Exception:
        _auto_job_id = None
    _auto_task = asyncio.create_task(_auto_generate_loop())
    logger.info(f"Auto-génération démarrée : {data.concurrency} têtes, batch {data.batch_size}")
    return {"status": "started",
            "concurrency": auto_state["concurrency"],
            "batch_size":  auto_state["batch_size"]}


@router.post("/auto-generate/stop", dependencies=[Depends(require_admin)])
async def auto_generate_stop():
    global _auto_task, auto_state
    auto_state["running"] = False
    if _auto_task and not _auto_task.done():
        _auto_task.cancel()
        try:
            await _auto_task
        except (asyncio.CancelledError, Exception):
            pass
    logger.info(f"Auto-génération stoppée — {auto_state['done']} fiches générées")
    return {"status": "stopped",
            "processed": auto_state["processed"],
            "done":      auto_state["done"],
            "offset":    auto_state["offset"]}


@router.post("/auto-generate/head/{slot}/pause")
async def auto_generate_head_pause(slot: int):
    if slot < 0 or slot >= len(_head_pause_events):
        raise HTTPException(status_code=404, detail="Tête introuvable")
    _head_pause_events[slot].clear()
    if slot < len(auto_state.get("heads", [])):
        auto_state["heads"][slot]["status"] = "paused"
    return {"slot": slot, "status": "paused"}


@router.post("/auto-generate/head/{slot}/resume")
async def auto_generate_head_resume(slot: int):
    if slot < 0 or slot >= len(_head_pause_events):
        raise HTTPException(status_code=404, detail="Tête introuvable")
    _head_pause_events[slot].set()
    if slot < len(auto_state.get("heads", [])):
        auto_state["heads"][slot]["status"] = "working"
    return {"slot": slot, "status": "resumed"}


@router.get("/auto-generate/status")
def auto_generate_status():
    total = auto_state["total"] or db_state.get("rows", 0)
    pct   = round(auto_state["done"] / total * 100, 2) if total > 0 else 0
    return {
        "running":       auto_state["running"],
        "concurrency":   auto_state["concurrency"],
        "batch_size":    auto_state["batch_size"],
        "processed":     auto_state["processed"],
        "done":          auto_state["done"],
        "errors":        auto_state["errors"],
        "total":         total,
        "offset":        auto_state["offset"],
        "percent":       pct,
        "heads":         auto_state.get("heads", []),
        "started_at":    auto_state["started_at"],
        "last_activity": auto_state["last_activity"],
        "job_id":        _auto_job_id,
    }


@router.get("/auto-generate/last-job")
def auto_generate_last_job():
    """Retourne le dernier job persisté — utile pour reprendre après un crash."""
    job = get_last_auto_job()
    if not job:
        return {"job": None, "resume_offset": 0}
    return {"job": job, "resume_offset": job.get("offset", 0)}
