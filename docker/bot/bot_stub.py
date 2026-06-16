"""
Bot-Service Stub — wählt zufällig eine erlaubte Karte aus.
Wird in V2 durch einen echten Algorithmus ersetzt.
"""

import os
import random
from flask import Flask, request, jsonify, abort

app = Flask(__name__)
API_TOKEN = os.environ.get("BOT_API_TOKEN", "")


def _token_gueltig() -> bool:
    auth = request.headers.get("Authorization", "")
    return auth == f"Bearer {API_TOKEN}"


@app.route("/health", methods=["GET"])
def health():
    return jsonify({"status": "ok", "service": "bot-stub"})


@app.route("/spielzug-anfrage", methods=["POST"])
def spielzug_anfrage():
    """
    Erwartet JSON:
    {
        "spiel_id": "...",
        "tischplatz_id": "...",
        "erlaubte_karten": ["K_A_1", "H_Z_2", ...],
        "spielzustand": { ... }
    }
    Antwortet mit der gewählten Karte.
    """
    if not _token_gueltig():
        abort(401)

    daten = request.get_json(force=True)
    erlaubte_karten = daten.get("erlaubte_karten", [])

    if not erlaubte_karten:
        abort(422)

    # Stub: zufällige Auswahl aus erlaubten Karten
    gewaehlt = random.choice(erlaubte_karten)

    return jsonify({
        "spiel_id": daten.get("spiel_id"),
        "tischplatz_id": daten.get("tischplatz_id"),
        "karte": gewaehlt,
    })


if __name__ == "__main__":
    app.run(host="0.0.0.0", port=8001)