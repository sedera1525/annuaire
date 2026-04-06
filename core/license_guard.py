"""
Societies — Protection par licence matérielle (asymétrique Ed25519)

Architecture :
  generate_license.py  → a la CLÉ PRIVÉE → signe les licences
  license_guard.py     → a la CLÉ PUBLIQUE → vérifie les signatures
  .env                 → RIEN de secret lié à la licence

La clé publique est dans ce fichier. Même visible, elle est inutile
pour générer des licences valides (impossible sans la clé privée).

Format license.key : BASE64(EXPIRY_TS|FINGERPRINT) + "." + BASE64(SIGNATURE)
"""

import base64
import hashlib
import logging
import os
import platform
import time
import uuid
from pathlib import Path

logger = logging.getLogger("societies")

LICENSE_FILE = Path(__file__).parent.parent / "license.key"

# Clé publique Ed25519 — uniquement pour vérification, inutile pour générer
_PUBLIC_KEY_B64 = "9GbKYowAOKoXiQPhRoakdnNQsAMT1jbLWs0FTRJk0h0="


def _get_fingerprint() -> str:
    """
    Fingerprint basé uniquement sur des éléments stables du serveur hôte.
    - /etc/machine-id  : identifiant unique de la machine hôte (stable, même dans Docker)
    - hostname         : nom du serveur
    Ces deux éléments ne changent pas au redémarrage du container.
    """
    parts = []

    # machine-id du serveur hôte (monté dans Docker si configuré, sinon celui du container)
    for path in ["/etc/machine-id", "/var/lib/dbus/machine-id"]:
        try:
            mid = Path(path).read_text().strip()
            if mid:
                parts.append(mid)
                break
        except Exception:
            pass

    if not parts:
        parts.append("no-machine-id")

    # Hostname (stable sur le serveur hôte)
    parts.append(platform.node() or "no-host")

    return hashlib.sha256("|".join(parts).encode()).hexdigest()[:32]


def get_fingerprint() -> str:
    return _get_fingerprint()


def verify_license() -> bool:
    env = os.getenv("SOCIETIES_ENV", "production").lower()
    if env in ("dev", "development", "local", "test"):
        logger.info("License guard : mode développement — vérification désactivée")
        return True

    if not LICENSE_FILE.exists():
        _fail("Fichier license.key introuvable. Contactez l'éditeur.")

    try:
        from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PublicKey
        from cryptography.hazmat.primitives.serialization import Encoding, PublicFormat
        from cryptography.exceptions import InvalidSignature

        content = LICENSE_FILE.read_text().strip()
        if "." not in content:
            _fail("Format de licence invalide.")

        payload_b64, sig_b64 = content.rsplit(".", 1)
        payload = base64.b64decode(payload_b64).decode()
        signature = base64.b64decode(sig_b64)

        if "|" not in payload:
            _fail("Format de licence invalide.")

        expiry_ts, licensed_fp = payload.split("|", 1)

        # Vérifier expiration
        if int(expiry_ts) != 0 and int(expiry_ts) < int(time.time()):
            _fail("Licence expirée. Contactez l'éditeur pour un renouvellement.")

        # Vérifier que la licence est pour CE serveur
        current_fp = _get_fingerprint()
        if not _compare(licensed_fp, current_fp):
            _fail("Licence invalide pour ce serveur. Ce logiciel ne peut pas être déplacé.")

        # Vérifier la signature cryptographique
        pub_bytes = base64.b64decode(_PUBLIC_KEY_B64)
        from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PublicKey
        pub_key = Ed25519PublicKey.from_public_bytes(pub_bytes)
        try:
            pub_key.verify(signature, payload_b64.encode())
        except InvalidSignature:
            _fail("Signature de licence invalide. Fichier corrompu ou falsifié.")

        if int(expiry_ts) == 0:
            logger.info("License guard : licence permanente valide ✓")
        else:
            import datetime
            exp = datetime.datetime.fromtimestamp(int(expiry_ts)).strftime("%d/%m/%Y")
            logger.info(f"License guard : licence valide jusqu'au {exp} ✓")

        return True

    except SystemExit:
        raise
    except Exception as e:
        _fail(f"Erreur lors de la vérification : {e}")


def _compare(a: str, b: str) -> bool:
    import hmac
    return hmac.compare_digest(a.encode(), b.encode())


def _fail(message: str) -> None:
    logger.critical(f"🔒 LICENCE INVALIDE — {message}")
    raise SystemExit(f"\n{'='*60}\n🔒 SOCIETIES — LICENCE INVALIDE\n{message}\n{'='*60}\n")
