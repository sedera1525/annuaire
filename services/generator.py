"""
Societies — Génération de contenu OpenAI
Inspiré de RealeseSeo/CompanyContentGenerator :
  - retry 3 tentatives avec backoff exponentiel 10 / 30 / 60 s
  - circuit breaker : pause automatique si trop d'erreurs consécutives
  - clé API découplée du module principal
"""

import asyncio
import logging
import re
import time
from typing import Optional

logger = logging.getLogger("societies")

# Backoff en secondes — miroir de RealeseSeo GenerateCompanyContent.backoff() = [10, 30, 60]
_BACKOFF = [10, 30, 60]

# Circuit breaker — inspiré de RealeseSeo ThrottlesExceptions(80, 1)
# Si _CB_THRESHOLD erreurs en moins de _CB_WINDOW secondes → pause automatique
_CB_THRESHOLD  = 5
_CB_WINDOW     = 60.0
_cb_errors     = 0
_cb_last_error = 0.0


def _cb_check() -> None:
    """Lève une RuntimeError si le circuit breaker est ouvert."""
    if _cb_errors >= _CB_THRESHOLD and time.time() - _cb_last_error < _CB_WINDOW:
        remaining = int(_CB_WINDOW - (time.time() - _cb_last_error))
        raise RuntimeError(
            f"Circuit breaker ouvert — {_cb_errors} erreurs consécutives. "
            f"Pause automatique, réessayez dans {remaining}s."
        )


def _cb_record_error() -> None:
    global _cb_errors, _cb_last_error
    _cb_errors    += 1
    _cb_last_error = time.time()


def _cb_reset() -> None:
    global _cb_errors
    _cb_errors = 0


OPEN_QUESTIONS_TEMPLATE = [
    "Comment fonctionne réellement le service client de l'entreprise {nom} en cas de problème ?",
    "Les clients fidèles de l'entreprise {nom} recommandent-ils vraiment ses services ?",
    "Les tarifs de l'entreprise {nom} sont-ils transparents ?",
    "Les délais annoncés par l'entreprise {nom} sont-ils respectés ?",
    "Le rapport qualité-prix de l'entreprise {nom} est-il intéressant ?",
    "L'entreprise {nom} propose-t-elle des garanties à ses clients ?",
]

GENERATION_PROMPT = """Tu es un analyste de réputation spécialisé dans les entreprises françaises, au style journalistique.

À partir des données ci-dessous, génère :
1. Un texte introductif de EXACTEMENT 3 phrases sur ce que pensent les clients de cette entreprise (avis, réputation, satisfaction globale).
2. Un texte bonus de EXACTEMENT 3 phrases sur les avantages du produit et/ou du service vendu par cette entreprise dans sa ville.
3. Les réponses aux 3 questions d'analyse (EXACTEMENT 2 phrases par réponse).

Règles strictes :
- Ne cite JAMAIS le nom exact "{nom}" dans l'intro, le bonus ni dans les réponses. Utilise "cette entreprise", "cet établissement", "ce prestataire", "cette structure", etc.
- N'utilise JAMAIS de pronom genré (il, elle, son, sa) pour désigner l'entreprise. Toujours des formulations neutres.
- Traduis le secteur d'activité en français si nécessaire (ex : "Church" → "Lieu de culte", "Painter" → "Peintre").
- Style journalistique : factuel, nuancé, appuyé sur la note et le nombre d'avis.
- EXACTEMENT 2 phrases par réponse (ni plus, ni moins).
- EXACTEMENT 3 phrases pour l'intro.

Règles de cohérence et de style pour l'intro (OBLIGATOIRES) :
- Ne commence JAMAIS par un mot de concession ou de transition comme "Cependant", "Toutefois", "Néanmoins", "Malgré", "En revanche", "Pourtant". L'intro doit commencer par un constat positif ou factuel direct.
- Quand tu emploies "la majorité", "la plupart", "beaucoup" ou tout quantificateur, précise TOUJOURS de qui il s'agit : "la majorité des clients", "la plupart des avis", "beaucoup de visiteurs". Ne laisse JAMAIS le quantificateur sans antécédent.
- N'utilise pas le mot "présentation" ni aucun titre ou label auto-descriptif dans le texte.
- Les 3 phrases doivent former un tout cohérent : constat général → nuance ou détail → conclusion ou perspective. Ne coupe pas le raisonnement au milieu.

Entreprise : {nom}
Secteur d'activité : {categorie}
Ville : {ville} ({code_postal})
Note clients : {note}

Réponds UNIQUEMENT avec ce JSON valide, sans texte avant ou après :
{{
  "intro": "Phrase 1 constat factuel (sans 'Cependant'). Phrase 2 avec quantificateur explicite (ex: 'la majorité des clients'). Phrase 3 de conclusion.",
  "bonus": "Phrase 1 sur les avantages du produit/service à {ville}. Phrase 2. Phrase 3.",
  "qa_answered": [
    {{"q": "Que pensent réellement les clients de l'entreprise {nom} ?", "r": "Phrase 1. Phrase 2."}},
    {{"q": "L'entreprise {nom} est-elle fiable ?", "r": "Phrase 1. Phrase 2."}},
    {{"q": "Qu'est-ce qui surprend le plus les clients de l'entreprise {nom} ?", "r": "Phrase 1. Phrase 2."}}
  ]
}}"""


def validate_qa(parsed: dict) -> None:
    """Vérifie que les 3 premières questions ont bien une réponse non vide."""
    qa = parsed.get("qa_answered")
    if not isinstance(qa, list) or len(qa) < 3:
        raise ValueError(
            f"qa_answered doit contenir au moins 3 questions — reçu : {len(qa) if isinstance(qa, list) else type(qa).__name__}"
        )
    for i, item in enumerate(qa[:3]):
        if not isinstance(item, dict):
            raise ValueError(f"qa_answered[{i}] n'est pas un objet — reçu : {item!r}")
        if not str(item.get("r", "")).strip():
            raise ValueError(f"qa_answered[{i}] a une réponse vide (question : {item.get('q', '?')!r})")


def build_prompt(
    title: str,
    category: Optional[str],
    city: Optional[str],
    zip_code: Optional[str],
    rating_value,
    rating_votes: Optional[int],
) -> str:
    return GENERATION_PROMPT.format(
        nom=title,
        categorie=category or "Non renseigné",
        ville=city or "Non renseignée",
        code_postal=zip_code or "",
        note=f"{rating_value or '?'}/5 ({rating_votes or 0} avis)",
    )


async def call_openai(api_key: str, model: str, timeout: float, prompt: str) -> dict:
    """
    Appel OpenAI avec :
    - circuit breaker : bloque si >= 5 erreurs en 60s (RealeseSeo ThrottlesExceptions)
    - retry exponentiel : 3 tentatives, backoff 10 / 30 / 60 s (RealeseSeo backoff=[10,30,60])
    """
    if not api_key:
        raise ValueError("Clé API OpenAI manquante. Configurez OPENAI_API_KEY dans .env")

    _cb_check()  # Vérifie le circuit breaker avant d'appeler OpenAI

    from openai import AsyncOpenAI
    client = AsyncOpenAI(api_key=api_key, timeout=timeout)

    last_exc: Exception | None = None
    for attempt in range(3):
        try:
            response = await client.chat.completions.create(
                model=model,
                messages=[{"role": "user", "content": prompt}],
                max_completion_tokens=4000,
                response_format={"type": "json_object"},
            )
            _cb_reset()  # Succès → réinitialise le compteur d'erreurs
            raw = (response.choices[0].message.content or "").strip()
            # Certains modèles enveloppent le JSON dans des blocs markdown
            raw = re.sub(r'^```(?:json)?\s*', '', raw)
            raw = re.sub(r'\s*```$', '', raw).strip()
            if not raw:
                raise ValueError(f"Réponse vide reçue du modèle ({model})")
            return {
                "text":              raw,
                "model":             response.model,
                "prompt_tokens":     response.usage.prompt_tokens,
                "completion_tokens": response.usage.completion_tokens,
            }
        except Exception as e:
            last_exc = e
            _cb_record_error()
            if attempt < 2:
                wait = _BACKOFF[attempt]
                logger.warning(
                    f"OpenAI tentative {attempt + 1}/3 échouée ({type(e).__name__}: {e}), "
                    f"retry dans {wait}s — erreurs consécutives : {_cb_errors}"
                )
                await asyncio.sleep(wait)

    raise last_exc  # type: ignore[misc]
