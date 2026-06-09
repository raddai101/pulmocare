"""
flask_api.py — PulmoCare IA
Microservice Flask exposant le modèle CNN via une API REST.
Alternative à exec() PHP pour de meilleures performances.

Usage : python flask_api.py --port 5000 --host 0.0.0.0
"""

import os, json, time, logging, argparse
from pathlib import Path
from functools import wraps

try:
    from flask import Flask, request, jsonify, g
    from flask_cors import CORS
    FLASK_AVAILABLE = True
except ImportError:
    FLASK_AVAILABLE = False

from predict import predict, predict_demo, MODEL_VERSION

logging.basicConfig(level=logging.INFO, format='%(asctime)s [%(levelname)s] %(message)s')
logger = logging.getLogger('FlaskAPI')

app = Flask(__name__) if FLASK_AVAILABLE else None
UPLOAD_DIR   = Path(__file__).parent.parent.parent / 'assets' / 'uploads' / 'scans'
API_KEY      = os.environ.get('AI_API_KEY', '')
DEMO_MODE    = os.environ.get('AI_DEMO_MODE', '0') == '1'
MAX_CONTENT  = 20 * 1024 * 1024  # 20 Mo


# ── Auth middleware ───────────────────────────────────────────

def require_api_key(f):
    @wraps(f)
    def decorated(*args, **kwargs):
        if API_KEY:
            key = request.headers.get('X-API-Key') or request.form.get('api_key', '')
            if key != API_KEY:
                return jsonify({'success': False, 'message': 'Clé API invalide.'}), 401
        return f(*args, **kwargs)
    return decorated


# ── Routes ───────────────────────────────────────────────────

@app.route('/health', methods=['GET'])
def health():
    """Endpoint de santé — vérifie que l'API est opérationnelle."""
    return jsonify({
        'status':        'ok',
        'model_version': MODEL_VERSION,
        'demo_mode':     DEMO_MODE,
        'timestamp':     time.time(),
    })


@app.route('/predict', methods=['POST'])
@require_api_key
def predict_endpoint():
    """
    Reçoit une image CT Scan et retourne la prédiction IA.
    Accepte : multipart/form-data avec champ 'image'
    """
    start = time.time()

    # Validation fichier
    if 'image' not in request.files:
        return jsonify({'success': False, 'message': 'Champ image manquant.'}), 400

    file = request.files['image']
    if not file.filename:
        return jsonify({'success': False, 'message': 'Fichier vide.'}), 400

    # Vérifier la taille
    file.seek(0, 2)
    size = file.tell()
    file.seek(0)
    if size > MAX_CONTENT:
        return jsonify({'success': False, 'message': 'Fichier trop volumineux (max 20 Mo).'}), 413

    # Extension autorisée
    allowed = {'.jpg', '.jpeg', '.png', '.dcm', '.tiff', '.tif'}
    ext = Path(file.filename).suffix.lower()
    if ext not in allowed:
        return jsonify({'success': False, 'message': f'Format non autorisé: {ext}'}), 415

    # Sauvegarder temporairement
    UPLOAD_DIR.mkdir(parents=True, exist_ok=True)
    tmp_path = UPLOAD_DIR / f'tmp_{int(time.time()*1000)}{ext}'

    try:
        file.save(str(tmp_path))

        # Prédiction
        if DEMO_MODE:
            result = predict_demo(str(tmp_path))
        else:
            result = predict(str(tmp_path))

        result['api_latency_ms'] = int((time.time() - start) * 1000)
        return jsonify(result)

    except Exception as e:
        logger.exception(f"Erreur predict: {e}")
        return jsonify({'success': False, 'message': str(e), 'status': 'error'}), 500

    finally:
        if tmp_path.exists():
            tmp_path.unlink()


@app.route('/info', methods=['GET'])
@require_api_key
def model_info():
    """Informations sur le modèle chargé."""
    model_path = Path(__file__).parent.parent / 'model' / 'cnn_model.h5'
    return jsonify({
        'model_version': MODEL_VERSION,
        'model_exists':  model_path.exists(),
        'model_size_mb': round(model_path.stat().st_size / 1048576, 2) if model_path.exists() else None,
        'classes':       ['normal', 'suspect', 'cancereux'],
        'input_size':    [224, 224, 3],
        'demo_mode':     DEMO_MODE,
    })


# ── Error handlers ────────────────────────────────────────────

@app.errorhandler(404)
def not_found(e):
    return jsonify({'success': False, 'message': 'Endpoint introuvable.'}), 404

@app.errorhandler(405)
def method_not_allowed(e):
    return jsonify({'success': False, 'message': 'Méthode non autorisée.'}), 405

@app.errorhandler(413)
def too_large(e):
    return jsonify({'success': False, 'message': 'Fichier trop volumineux.'}), 413


# ── Logging middleware ────────────────────────────────────────

@app.before_request
def before():
    g.start = time.time()

@app.after_request
def after(response):
    dur = int((time.time() - g.start) * 1000)
    logger.info(f"{request.method} {request.path} → {response.status_code} ({dur}ms)")
    response.headers['X-Processing-Time'] = str(dur)
    return response


# ── Entry point ───────────────────────────────────────────────

if __name__ == '__main__':
    if not FLASK_AVAILABLE:
        print("Flask requis : pip install flask flask-cors")
        exit(1)

    parser = argparse.ArgumentParser(description='PulmoCare IA — Flask API')
    parser.add_argument('--host',  default='127.0.0.1')
    parser.add_argument('--port',  type=int, default=5000)
    parser.add_argument('--debug', action='store_true')
    parser.add_argument('--demo',  action='store_true', help='Mode démo (pas de TensorFlow)')
    args = parser.parse_args()

    if args.demo:
        os.environ['AI_DEMO_MODE'] = '1'
        DEMO_MODE = True

    logger.info(f"Démarrage API Flask sur http://{args.host}:{args.port}")
    logger.info(f"Mode démo : {DEMO_MODE} | Version modèle : {MODEL_VERSION}")

    app.run(host=args.host, port=args.port, debug=args.debug, threaded=True)
