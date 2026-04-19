#!/usr/bin/env python3
"""
Fusionne societies_fr.duckdb (Google Maps) avec SIRÈNE via DuckDB SQL.
"""
import duckdb
from pathlib import Path

ETABLISSEMENT = "docs/StockEtablissement.parquet"
UNITE_LEGALE  = "docs/StockUniteLegale.parquet"
CURRENT_DB    = "docs/societies_fr.duckdb"
OUTPUT_DB     = "docs/societies_merged.duckdb"
NEW_PARQUET   = "/tmp/sirene_new.parquet"

NAF_FR = {
    "01.11Z":"Culture de céréales","01.13Z":"Culture de légumes","01.19Z":"Autres cultures",
    "01.21Z":"Viticulture","01.25Z":"Arboriculture","01.41Z":"Élevage bovin laitier",
    "01.42Z":"Élevage bovin","01.46Z":"Élevage porcin","01.47Z":"Aviculture",
    "02.10Z":"Sylviculture","03.11Z":"Pêche en mer","03.22Z":"Aquaculture",
    "10.11Z":"Transformation de viande","10.13A":"Charcuterie","10.51A":"Laiterie",
    "10.71A":"Boulangerie","10.71B":"Boulangerie-pâtisserie","10.72Z":"Biscuiterie",
    "10.82Z":"Chocolaterie","11.01Z":"Distillerie","11.02A":"Viticulture AOC",
    "11.05Z":"Brasserie","11.07A":"Eau minérale","11.07B":"Boissons",
    "18.11Z":"Imprimerie","18.12Z":"Imprimerie de labeur","21.10Z":"Pharmacie industrielle",
    "25.11Z":"Structures métalliques","25.12Z":"Menuiserie métallique","25.73Z":"Outillage",
    "26.20Z":"Informatique","26.30Z":"Équipements de communication","27.40Z":"Éclairage",
    "27.51Z":"Électroménager","28.30Z":"Machines agricoles","30.11Z":"Construction navale",
    "30.30Z":"Aéronautique","30.92Z":"Cycles","31.02Z":"Meubles cuisine",
    "31.09A":"Sièges","31.09B":"Meubles","32.11Z":"Bijouterie","32.40Z":"Jeux et jouets",
    "32.50A":"Matériel médical","32.50B":"Lunetterie","32.91Z":"Articles de sport",
    "33.12Z":"Réparation machines","33.20A":"Installation machines",
    "35.11Z":"Production électricité","35.13Z":"Distribution électricité",
    "35.22Z":"Distribution gaz","36.00Z":"Distribution eau","37.00Z":"Assainissement",
    "38.11Z":"Collecte déchets","38.21Z":"Traitement déchets","39.00Z":"Dépollution",
    "41.10A":"Promotion immobilière","41.10B":"Promotion immobilière bureaux",
    "41.10C":"Promotion immobilière","41.10D":"Promotion immobilière",
    "41.20A":"Construction maisons","41.20B":"Construction bâtiments",
    "42.11Z":"Construction routes","42.21Z":"Réseaux de fluides",
    "42.22Z":"Réseaux électriques","42.91Z":"Génie hydraulique","42.99Z":"Travaux publics",
    "43.11Z":"Démolition","43.12A":"Terrassement grands travaux","43.12B":"Terrassement",
    "43.13Z":"Forage","43.21A":"Électricité bâtiment","43.21B":"Câblage",
    "43.22A":"Plomberie","43.22B":"Chauffage climatisation","43.29A":"Isolation",
    "43.29B":"Menuiserie extérieure","43.31Z":"Plâtrerie","43.32A":"Menuiserie intérieure",
    "43.32B":"Agencement","43.33Z":"Revêtement sols et murs","43.34Z":"Peinture",
    "43.39Z":"Travaux de finition","43.91A":"Charpente","43.91B":"Couverture",
    "43.99A":"Étanchéité","43.99B":"Échafaudage","43.99C":"Voirie et réseaux divers",
    "43.99D":"Travaux spécialisés",
    "45.11Z":"Concessionnaire automobile","45.19Z":"Commerce véhicules",
    "45.20A":"Entretien automobile","45.20B":"Réparation automobile",
    "45.31Z":"Commerce pièces auto","45.32Z":"Commerce pneus","45.40Z":"Concessionnaire moto",
    "47.11A":"Alimentation générale","47.11B":"Supermarché","47.11C":"Hypermarché",
    "47.11D":"Supérette","47.11E":"Épicerie","47.11F":"Épicerie de nuit",
    "47.19A":"Grand magasin","47.19B":"Commerce non spécialisé","47.21Z":"Primeur",
    "47.22Z":"Boucherie","47.23Z":"Poissonnerie","47.24Z":"Boulangerie",
    "47.25Z":"Cave à vins","47.26Z":"Tabac presse","47.29Z":"Commerce alimentaire spécialisé",
    "47.30Z":"Station-service","47.41Z":"Commerce informatique","47.42Z":"Commerce téléphonie",
    "47.43Z":"Commerce audiovisuel","47.51Z":"Commerce textiles","47.52A":"Quincaillerie",
    "47.52B":"Bricolage","47.53Z":"Commerce revêtements","47.54Z":"Commerce électroménager",
    "47.59A":"Magasin de meubles","47.59B":"Commerce équipement maison","47.61Z":"Librairie",
    "47.62Z":"Presse","47.64Z":"Magasin de sport","47.65Z":"Jouets",
    "47.71Z":"Prêt-à-porter","47.72A":"Chaussures","47.72B":"Maroquinerie",
    "47.73Z":"Pharmacie","47.74Z":"Matériel médical","47.75Z":"Parfumerie",
    "47.76Z":"Fleuriste","47.77Z":"Bijouterie horlogerie","47.78A":"Opticien",
    "47.78B":"Commerce artisanat","47.79Z":"Commerce d'occasion",
    "47.91A":"Vente en ligne","47.91B":"Vente en ligne","47.99B":"Vente directe",
    "49.31Z":"Transport urbain","49.32Z":"Taxi","49.39A":"Transport voyageurs",
    "49.39B":"Autocar","49.41A":"Transport routier","49.41B":"Transport frigorifique",
    "49.41C":"Location de camions","49.42Z":"Déménagement",
    "50.10Z":"Transport maritime","51.10Z":"Transport aérien",
    "52.10B":"Entreposage","52.21Z":"Services transport",
    "52.29A":"Messagerie","52.29B":"Transitaire","53.10Z":"La Poste","53.20Z":"Courrier",
    "55.10Z":"Hôtel","55.20Z":"Hébergement touristique","55.30Z":"Camping","55.90Z":"Hébergement",
    "56.10A":"Restaurant","56.10B":"Restauration rapide","56.21Z":"Traiteur",
    "56.29A":"Restauration collective","56.29B":"Cafétéria","56.30Z":"Bar café",
    "58.11Z":"Édition","58.13Z":"Presse","58.21Z":"Jeux vidéo","58.29A":"Éditeur de logiciels",
    "59.11A":"Production cinéma","59.11B":"Production audiovisuelle","59.14Z":"Cinéma",
    "59.20Z":"Studio d'enregistrement","60.10Z":"Radio","60.20A":"Télévision",
    "61.10Z":"Opérateur télécom","61.20Z":"Opérateur mobile",
    "62.01Z":"Développement de logiciels","62.02A":"Conseil informatique",
    "62.02B":"Maintenance applicative","62.03Z":"Infrastructure informatique",
    "62.09Z":"Services informatiques","63.11Z":"Hébergement web","63.12Z":"Portail internet",
    "63.91Z":"Agence de presse","64.19Z":"Banque","64.20Z":"Holdings",
    "64.91Z":"Crédit-bail","64.92Z":"Organisme de crédit",
    "65.11Z":"Assurance vie","65.12Z":"Assurance",
    "66.19B":"Courtier en assurance","66.22Z":"Agent d'assurance",
    "68.10Z":"Marchand de biens","68.20A":"Location appartements","68.20B":"Location terrains",
    "68.31Z":"Agence immobilière","68.32A":"Syndic de copropriété","68.32B":"Gestion immobilière",
    "69.10Z":"Avocat","69.20Z":"Expert-comptable","70.10Z":"Direction d'entreprise",
    "70.21Z":"Relations publiques","70.22Z":"Conseil en gestion",
    "71.11Z":"Architecte","71.12B":"Bureau d'ingénierie","71.12C":"Bureau d'études",
    "71.20A":"Contrôle technique auto","71.20B":"Laboratoire de contrôle",
    "72.19Z":"Recherche et développement","73.11Z":"Agence de publicité",
    "73.12Z":"Régie publicitaire","73.20Z":"Études de marché",
    "74.10Z":"Design","74.20Z":"Photographe","74.30Z":"Traducteur","74.90B":"Consultant",
    "75.00Z":"Vétérinaire","77.11A":"Location de voitures","77.11B":"Location longue durée",
    "77.21Z":"Location matériel sport","77.29Z":"Location divers",
    "77.32Z":"Location engins BTP","78.10Z":"Recrutement","78.20Z":"Travail temporaire",
    "78.30Z":"Portage salarial","79.11Z":"Agence de voyages","79.12Z":"Tour-opérateur",
    "80.10Z":"Sécurité privée","80.20Z":"Télésurveillance",
    "81.10Z":"Facility management","81.21Z":"Nettoyage","81.22Z":"Nettoyage industriel",
    "81.29B":"Services de nettoyage","81.30Z":"Paysagiste",
    "82.11Z":"Services administratifs","82.20Z":"Centre d'appels",
    "82.30Z":"Organisation d'événements","82.92Z":"Conditionnement",
    "82.99Z":"Services aux entreprises",
    "85.10Z":"École maternelle","85.20Z":"École primaire","85.31Z":"Lycée collège",
    "85.32Z":"Enseignement technique","85.41Z":"BTS IUT","85.42Z":"Enseignement supérieur",
    "85.51Z":"École de sport","85.52Z":"École d'art","85.53Z":"Auto-école",
    "85.59A":"Formation professionnelle","85.59B":"Cours particuliers","85.60Z":"Soutien scolaire",
    "86.10Z":"Hôpital clinique","86.21Z":"Médecin généraliste","86.22A":"Médecin spécialiste",
    "86.22B":"Chirurgien","86.22C":"Psychiatre","86.23Z":"Dentiste",
    "86.90A":"Ambulance","86.90B":"Laboratoire d'analyses","86.90C":"Centre de santé",
    "86.90D":"Infirmier","86.90E":"Podologue","86.90F":"Kinésithérapeute",
    "87.10A":"Maison de retraite médicalisée","87.10B":"EHPAD",
    "87.30A":"Maison de retraite","87.30B":"Résidence seniors",
    "88.10A":"Aide à domicile","88.10B":"Accueil de jour",
    "88.91A":"Crèche","88.91B":"Halte-garderie","88.99B":"Action sociale",
    "90.01Z":"Spectacle vivant","90.02Z":"Services culturels",
    "90.03A":"Artiste plasticien","90.03B":"Artiste","90.04Z":"Salle de spectacle",
    "91.01Z":"Bibliothèque","91.02Z":"Musée","91.03Z":"Patrimoine historique",
    "92.00Z":"Jeux et paris","93.11Z":"Équipement sportif","93.12Z":"Club sportif",
    "93.13Z":"Salle de sport","93.14Z":"Centre équestre","93.19Z":"Activité sportive",
    "93.21Z":"Parc d'attractions","93.29Z":"Loisirs",
    "94.11Z":"Organisation patronale","94.12Z":"Organisation professionnelle",
    "94.20Z":"Syndicat","94.91Z":"Église association cultuelle","94.99Z":"Association",
    "95.11Z":"Réparation ordinateurs","95.12Z":"Réparation téléphones",
    "95.21Z":"Réparation électronique","95.22Z":"Réparation électroménager",
    "95.23Z":"Cordonnier","95.24Z":"Menuiserie réparation","95.25Z":"Horloger bijoutier",
    "95.29Z":"Réparation divers","96.01A":"Blanchisserie","96.01B":"Pressing",
    "96.02A":"Coiffeur","96.02B":"Institut de beauté","96.03Z":"Pompes funèbres",
    "96.04Z":"Spa bien-être","96.09A":"Esthéticien","96.09B":"Services personnels",
}

def main():
    print("=== Fusion Google Maps + SIRÈNE (via DuckDB SQL) ===\n")

    con = duckdb.connect()

    # 1. Charger la DB existante en lecture seule
    print("1/4 — Attacher la base existante...")
    con.execute(f"ATTACH '{CURRENT_DB}' AS cur (READ_ONLY)")
    existing_count = con.execute("SELECT COUNT(*) FROM cur.companies").fetchone()[0]
    print(f"     {existing_count:,} entreprises existantes")

    # 2. Construire la table de mapping NAF
    print("2/4 — Mapping NAF...")
    con.execute("CREATE TEMP TABLE naf_map (code VARCHAR, label VARCHAR)")
    con.executemany("INSERT INTO naf_map VALUES (?,?)", list(NAF_FR.items()))
    print(f"     {len(NAF_FR)} codes NAF")

    # 3. Requête SQL complète : join UL + Etab + anti-join existant → Parquet
    print("3/4 — Extraction SIRÈNE (SQL)...")
    con.execute(f"""
        COPY (
            SELECT
                TRIM(COALESCE(
                    NULLIF(e.enseigne1Etablissement, ''),
                    NULLIF(e.denominationUsuelleEtablissement, ''),
                    CASE
                        WHEN ul.denominationUniteLegale IS NOT NULL AND ul.denominationUniteLegale != ''
                        THEN ul.denominationUniteLegale
                        WHEN ul.nomUniteLegale IS NOT NULL AND ul.nomUniteLegale != ''
                        THEN TRIM(COALESCE(ul.prenomUsuelUniteLegale, '') || ' ' || ul.nomUniteLegale)
                        ELSE NULL
                    END
                )) AS title,
                ''            AS description,
                COALESCE(naf.label,
                    CASE
                        WHEN LENGTH(e.activitePrincipaleEtablissement) = 5
                        THEN SUBSTR(e.activitePrincipaleEtablissement,1,2)||'.'||SUBSTR(e.activitePrincipaleEtablissement,3)
                        ELSE e.activitePrincipaleEtablissement
                    END
                )             AS category,
                ''            AS phone,
                ''            AS url,
                ''            AS domain,
                NULL::DOUBLE  AS latitude,
                NULL::DOUBLE  AS longitude,
                false         AS is_claimed,
                '[]'          AS contacts,
                TRIM(COALESCE(e.numeroVoieEtablissement,'') || ' ' ||
                     COALESCE(e.typeVoieEtablissement,'') || ' ' ||
                     COALESCE(e.libelleVoieEtablissement,''))  AS addr_street,
                e.libelleCommuneEtablissement                  AS city,
                e.codePostalEtablissement                      AS zip_code,
                ''            AS region,
                'FR'          AS country_code,
                NULL::FLOAT   AS rating_value,
                NULL::INTEGER AS rating_votes,
                ''            AS snippet,
                ''            AS logo,
                ''            AS main_image,
                TRIM(COALESCE(e.numeroVoieEtablissement,'') || ' ' ||
                     COALESCE(e.typeVoieEtablissement,'') || ' ' ||
                     COALESCE(e.libelleVoieEtablissement,'') || ' ' ||
                     COALESCE(e.codePostalEtablissement,'') || ' ' ||
                     COALESCE(e.libelleCommuneEtablissement,'')) AS address_full,
                NULL::INTEGER AS total_photos
            FROM read_parquet('{ETABLISSEMENT}') e
            LEFT JOIN read_parquet('{UNITE_LEGALE}') ul ON e.siren = ul.siren
            LEFT JOIN naf_map naf ON (
                CASE WHEN LENGTH(e.activitePrincipaleEtablissement) = 5
                THEN SUBSTR(e.activitePrincipaleEtablissement,1,2)||'.'||SUBSTR(e.activitePrincipaleEtablissement,3)
                ELSE e.activitePrincipaleEtablissement END
            ) = naf.code
            WHERE e.etatAdministratifEtablissement = 'A'
              AND e.codePostalEtablissement IS NOT NULL
              AND e.codePostalEtablissement != '[ND]'
              AND COALESCE(
                    NULLIF(e.enseigne1Etablissement,''),
                    NULLIF(e.denominationUsuelleEtablissement,''),
                    ul.denominationUniteLegale,
                    ul.nomUniteLegale
                  ) IS NOT NULL
              AND LOWER(TRIM(COALESCE(
                    NULLIF(e.enseigne1Etablissement,''),
                    NULLIF(e.denominationUsuelleEtablissement,''),
                    ul.denominationUniteLegale,
                    ul.nomUniteLegale
                  ))) NOT IN (SELECT LOWER(TRIM(title)) FROM cur.companies WHERE title IS NOT NULL)
        ) TO '{NEW_PARQUET}' (FORMAT PARQUET)
    """)
    count_new = con.execute(f"SELECT COUNT(*) FROM read_parquet('{NEW_PARQUET}')").fetchone()[0]
    print(f"     {count_new:,} nouvelles entreprises extraites")

    # 4. Créer la DB fusionnée
    print("4/4 — Création DB fusionnée...")
    import shutil
    shutil.copy(CURRENT_DB, OUTPUT_DB)
    dst = duckdb.connect(OUTPUT_DB)
    dst.execute(f"INSERT INTO companies SELECT * FROM read_parquet('{NEW_PARQUET}')")
    total = dst.execute("SELECT COUNT(*) FROM companies").fetchone()[0]
    print(f"     Total : {total:,} entreprises")

    print("\nAperçu nouvelles entreprises :")
    rows = dst.execute(f"""
        SELECT title, category, city, zip_code
        FROM read_parquet('{NEW_PARQUET}') LIMIT 10
    """).fetchall()
    for r in rows:
        print(f"  [{r[3]}] {r[0]} — {r[1]} — {r[2]}")

    dst.close()
    con.close()
    print(f"\nDone → {OUTPUT_DB}")
    print(f"Ajout net : +{count_new:,} entreprises")

if __name__ == "__main__":
    main()
