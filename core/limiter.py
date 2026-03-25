"""
Societies — Instance partagée du rate limiter slowapi
En mode test (TESTING=true), chaque requête a une clé unique → pas de limite effective.
"""
import os

from slowapi import Limiter
from slowapi.util import get_remote_address


def _key_func(request):
    if os.getenv("TESTING", "false").lower() == "true":
        return f"test-{id(request)}"  # Clé unique → jamais limitée
    return get_remote_address(request)


limiter = Limiter(key_func=_key_func, default_limits=[])
