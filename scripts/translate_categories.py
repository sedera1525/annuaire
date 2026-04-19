#!/usr/bin/env python3
"""
Traduit les catégories anglaises en français via l'API OpenAI.
Génère docs/categories_fr.json (mapping en → fr).
"""
import json, os, time, re
from pathlib import Path
from openai import OpenAI

INPUT  = Path("docs/categories.txt")
OUTPUT = Path("docs/categories_fr.json")
BATCH  = 40

def load_env():
    for line in Path(".env").read_text().splitlines():
        if '=' in line and not line.startswith('#'):
            k, v = line.split('=', 1)
            os.environ.setdefault(k.strip(), v.strip())

load_env()
client = OpenAI(api_key=os.environ["OPENAI_API_KEY"])


def translate_batch(categories: list[str]) -> dict[str, str]:
    prompt = (
        "Tu es un traducteur professionnel. Traduis ces catégories d'entreprises de l'anglais vers le français.\n"
        "Règles :\n"
        "- Traduction courte et naturelle (comme sur les Pages Jaunes)\n"
        "- Si déjà en français, retourne-la telle quelle\n"
        "- Réponds UNIQUEMENT avec un objet JSON valide {\"original\": \"traduction\"}\n\n"
        "Catégories :\n"
        + "\n".join(f"- {c}" for c in categories)
    )
    resp = client.chat.completions.create(
        model="gpt-4o-mini",
        messages=[{"role": "user", "content": prompt}],
        response_format={"type": "json_object"},
        temperature=0.1,
        max_tokens=4096,
    )
    return json.loads(resp.choices[0].message.content)


def main():
    categories = [l.strip() for l in INPUT.read_text().splitlines() if l.strip()]
    print(f"{len(categories)} catégories à traiter")

    mapping: dict[str, str] = {}
    if OUTPUT.exists():
        mapping = json.loads(OUTPUT.read_text())
        print(f"  → {len(mapping)} déjà traduits, reprise...")

    todo = [c for c in categories if c not in mapping]
    print(f"  → {len(todo)} restants")

    total_batches = (len(todo) + BATCH - 1) // BATCH
    for i in range(0, len(todo), BATCH):
        batch = todo[i:i+BATCH]
        print(f"Batch {i//BATCH+1}/{total_batches} ({len(batch)} catégories)...", end=" ", flush=True)
        try:
            result = translate_batch(batch)
            mapping.update(result)
            OUTPUT.write_text(json.dumps(mapping, ensure_ascii=False, indent=2))
            print(f"OK")
        except Exception as e:
            print(f"ERREUR: {e}")
        time.sleep(0.3)

    print(f"\nTerminé. {len(mapping)} catégories dans {OUTPUT}")
    print("\n=== Aperçu ===")
    for en, fr in list(mapping.items())[:15]:
        print(f"  {en!r:40} → {fr!r}")


if __name__ == "__main__":
    main()
