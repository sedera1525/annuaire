#!/usr/bin/env python3
"""
Applique la traduction des catégories sur societies.duckdb.
"""
import json, csv, duckdb
from pathlib import Path

MAPPING_FILE = "/tmp/categories_fr.json"
CSV_FILE     = "/tmp/cat_mapping.csv"
DB_FILE      = "/app/societies.duckdb"

mapping = json.loads(Path(MAPPING_FILE).read_text())
print(f"Mapping chargé : {len(mapping)} catégories")

# Écrire le mapping en CSV
with open(CSV_FILE, "w", newline="", encoding="utf-8") as f:
    w = csv.writer(f)
    w.writerow(["en", "fr"])
    for k, v in mapping.items():
        if k != v:
            w.writerow([k, v])

print(f"CSV écrit : {CSV_FILE}")

con = duckdb.connect(DB_FILE)
before = con.execute("SELECT COUNT(DISTINCT category) FROM companies WHERE category IS NOT NULL").fetchone()[0]
print(f"Catégories distinctes avant : {before}")

con.execute(f"""
    UPDATE companies
    SET category = m.fr
    FROM read_csv_auto('{CSV_FILE}', header=true) AS m
    WHERE companies.category = m.en
""")

updated = con.execute("SELECT changes()").fetchone()[0]
print(f"Lignes mises à jour : {updated:,}")

after = con.execute("SELECT COUNT(DISTINCT category) FROM companies WHERE category IS NOT NULL").fetchone()[0]
print(f"Catégories distinctes après : {after}")

print("\n=== Vérification ===")
rows = con.execute("SELECT DISTINCT category FROM companies WHERE category IS NOT NULL LIMIT 20").fetchall()
for r in rows:
    print(" ", r[0])

con.close()
print("\nTerminé.")
