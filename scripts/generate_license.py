"""
Societies — Générateur de licences matérielles (Ed25519)
=========================================================
⚠️  CE FICHIER NE DOIT JAMAIS ÊTRE SUR LE SERVEUR ⚠️
     Gardez-le en local, en lieu sûr.

USAGE :
  python3 scripts/generate_license.py --fingerprint <FP> [--days <N>]

  --fingerprint  Empreinte du serveur (via GET /api/license/fingerprint)
  --days         Durée en jours (0 = illimitée, défaut=0)
  --output       Fichier de sortie (défaut=license.key)

EXEMPLE :
  python3 scripts/generate_license.py --fingerprint 8038b674faa78d46eeb24b467f0f99f1
  python3 scripts/generate_license.py --fingerprint 8038b674faa78d46eeb24b467f0f99f1 --days 365
"""

import argparse
import base64
import time

# ============================================================
# CLÉ PRIVÉE Ed25519 — NE JAMAIS DÉPLOYER CE FICHIER
# Seule cette clé peut générer des licences valides.
# ============================================================
_PRIVATE_KEY_B64 = "0X56r+hZKRqStd0sstg6Cna+SVf5KTIlyn65LAaN+tQ="


def generate(fingerprint: str, days: int = 0) -> str:
    from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
    from cryptography.hazmat.primitives.serialization import Encoding, PrivateFormat, NoEncryption

    expiry_ts = 0 if days == 0 else int(time.time()) + days * 86400
    payload = f"{expiry_ts}|{fingerprint}"
    payload_b64 = base64.b64encode(payload.encode()).decode()

    priv_bytes = base64.b64decode(_PRIVATE_KEY_B64)
    priv_key = Ed25519PrivateKey.from_private_bytes(priv_bytes)
    signature = priv_key.sign(payload_b64.encode())
    sig_b64 = base64.b64encode(signature).decode()

    return f"{payload_b64}.{sig_b64}"


def main():
    parser = argparse.ArgumentParser(description="Générateur de licences Societies")
    parser.add_argument("--fingerprint", required=True)
    parser.add_argument("--days", type=int, default=0)
    parser.add_argument("--output", default="license.key")
    args = parser.parse_args()

    key = generate(args.fingerprint, args.days)

    with open(args.output, "w") as f:
        f.write(key)

    print(f"✅ Licence générée → {args.output}")
    print(f"   Fingerprint : {args.fingerprint}")
    print(f"   Expiration  : {'Illimitée' if args.days == 0 else f'{args.days} jours'}")
    print()
    print("📋 À faire :")
    print(f"   1. Copiez {args.output} à la racine du projet sur le serveur")
    print(f"   2. Redémarrez le backend (docker compose up -d --build)")
    print()
    print("⚠️  Aucune clé secrète à mettre dans .env — c'est tout !")


if __name__ == "__main__":
    main()
