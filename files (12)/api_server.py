"""
=============================================================================
api_server.py — Serveur Flask : interface entre PHP et le modèle IA Python
=============================================================================
Le back-end PHP envoie l'image CT Scan via HTTP POST.
Ce serveur reçoit l'image, exécute le CNN, et retourne le rapport JSON.

Endpoints :
    POST /predict          → diagnostic complet (JSON)
    POST /predict/gradcam  → diagnostic + heatmap Grad-CAM (JSON + image B64)
    GET  /health           → état du serveur + modèle
    GET  /classes          → liste des classes détectables
    GET  /taxonomy         → taxonomie médicale complète
=============================================================================
"""

import os
import io
import base64
import json
import traceback
import numpy as np
import cv2
from datetime import datetime
from flask import Flask, request, jsonify, abort
from flask_cors import CORS
from functools import wraps

from config import (
    CLASS_NAMES, NUM_CLASSES, CANCER_TYPES,
    STADES_TNM, LOCALISATIONS, MODEL_PATH, EXPORTS_DIR
)
from model_builder import charger_modele
from predict import diagnostiquer_depuis_bytes, afficher_rapport
from gradcam import gradcam_complet, superposer_heatmap, exporter_superposition


# =============================================================================
# Initialisation de l'application Flask
# =============================================================================

_model_global = None   # Instance unique du modèle (chargée une seule fois)


def charger_modele_global():
    """Charge le modèle CNN une seule fois au démarrage du serveur."""
    global _model_global
    if _model_global is None:
        print(f"[Serveur] Chargement du modèle : {MODEL_PATH}")
        _model_global = charger_modele(MODEL_PATH)
        print("[Serveur] Modèle chargé et prêt.")
    return _model_global


def creer_application() -> Flask:
    """
    Crée et configure l'application Flask.

    Retourne
    --------
    Flask : application configurée
    """
    app = Flask(__name__)
    CORS(app)  # Autorise les requêtes cross-origin (PHP → Python)

    # ── Pré-chargement du modèle ──────────────────────────────────────────────
    charger_modele_global()

    # ── Enregistrement des routes ─────────────────────────────────────────────
    enregistrer_routes(app)

    return app


# =============================================================================
# Utilitaires de réponse
# =============================================================================

def reponse_succes(donnees: dict, code: int = 200):
    """Formatte une réponse JSON de succès."""
    return jsonify({
        'statut':     'succes',
        'horodatage': datetime.now().isoformat(),
        'donnees':    donnees
    }), code


def reponse_erreur(message: str, code: int = 400, detail: str = None):
    """Formatte une réponse JSON d'erreur."""
    corps = {
        'statut':  'erreur',
        'message': message,
        'code':    code
    }
    if detail:
        corps['detail'] = detail
    return jsonify(corps), code


def valider_image(fichier) -> bytes:
    """
    Valide et lit les bytes d'une image uploadée.

    Paramètres
    ----------
    fichier : FileStorage   Fichier Werkzeug

    Retourne
    --------
    bytes : contenu binaire de l'image

    Lève
    ----
    ValueError si le fichier est invalide
    """
    if fichier is None:
        raise ValueError("Aucune image reçue.")

    extensions_autorisees = {'.jpg', '.jpeg', '.png', '.bmp', '.tiff', '.dcm'}
    nom   = fichier.filename or ''
    ext   = os.path.splitext(nom)[1].lower()

    if ext and ext not in extensions_autorisees:
        raise ValueError(f"Extension non supportée : '{ext}'. "
                         f"Formats acceptés : {', '.join(extensions_autorisees)}")

    image_bytes = fichier.read()
    if len(image_bytes) == 0:
        raise ValueError("Fichier image vide.")
    if len(image_bytes) > 50 * 1024 * 1024:  # 50 Mo max
        raise ValueError("Image trop volumineuse (max 50 Mo).")

    # Vérification OpenCV
    tableau = np.frombuffer(image_bytes, dtype=np.uint8)
    img     = cv2.imdecode(tableau, cv2.IMREAD_COLOR)
    if img is None:
        raise ValueError("Impossible de décoder l'image. Format non reconnu ou fichier corrompu.")

    return image_bytes


def image_vers_base64(image_np: np.ndarray, format_img: str = '.jpg') -> str:
    """
    Encode une image numpy en chaîne Base64.

    Paramètres
    ----------
    image_np   : np.ndarray   Image RGB uint8
    format_img : str          Extension OpenCV ('.jpg', '.png')

    Retourne
    --------
    str : image encodée Base64 UTF-8
    """
    img_bgr  = cv2.cvtColor(image_np, cv2.COLOR_RGB2BGR)
    _, buffer = cv2.imencode(format_img, img_bgr)
    return base64.b64encode(buffer).decode('utf-8')


# =============================================================================
# Enregistrement des routes
# =============================================================================

def enregistrer_routes(app: Flask):
    """Enregistre toutes les routes sur l'application Flask."""

    # ── GET /health ───────────────────────────────────────────────────────────

    @app.route('/health', methods=['GET'])
    def health():
        """Vérification de l'état du serveur et du modèle."""
        model = charger_modele_global()
        return reponse_succes({
            'serveur':    'en ligne',
            'modele':     'chargé',
            'classes':    CLASS_NAMES,
            'num_classes': NUM_CLASSES,
            'input_shape': str(model.input_shape),
            'parametres': f"{model.count_params():,}"
        })

    # ── GET /classes ──────────────────────────────────────────────────────────

    @app.route('/classes', methods=['GET'])
    def classes():
        """Retourne la liste des classes détectables avec leur indice."""
        return reponse_succes({
            'classes': [
                {'index': i, 'label': cls}
                for i, cls in enumerate(CLASS_NAMES)
            ]
        })

    # ── GET /taxonomy ─────────────────────────────────────────────────────────

    @app.route('/taxonomy', methods=['GET'])
    def taxonomy():
        """Retourne la taxonomie médicale complète."""
        return reponse_succes({
            'types_cancer': CANCER_TYPES,
            'stades_tnm':   STADES_TNM,
            'localisations': LOCALISATIONS
        })

    # ── POST /predict ─────────────────────────────────────────────────────────

    @app.route('/predict', methods=['POST'])
    def predict():
        """
        Prédiction CNN + diagnostic enrichi sur une image CT Scan.

        Corps de la requête (multipart/form-data) :
            image        : fichier image CT Scan
            localisation : str (optionnel) 'central'|'peripherique'|'variable'

        Retourne :
            JSON : rapport de diagnostic complet (Niveau 1 + Niveau 2)
        """
        try:
            # Validation de l'image
            fichier      = request.files.get('image')
            image_bytes  = valider_image(fichier)
            localisation = request.form.get('localisation', 'variable')

            if localisation not in ('central', 'peripherique', 'variable'):
                localisation = 'variable'

            # Prédiction
            model   = charger_modele_global()
            rapport = diagnostiquer_depuis_bytes(image_bytes, model, localisation)

            return reponse_succes(rapport)

        except ValueError as e:
            return reponse_erreur(str(e), code=400)
        except Exception as e:
            detail = traceback.format_exc() if app.debug else None
            return reponse_erreur("Erreur interne lors de la prédiction.", code=500, detail=detail)

    # ── POST /predict/gradcam ─────────────────────────────────────────────────

    @app.route('/predict/gradcam', methods=['POST'])
    def predict_gradcam():
        """
        Prédiction CNN + Grad-CAM + diagnostic enrichi.

        Corps de la requête (multipart/form-data) :
            image        : fichier image CT Scan
            localisation : str (optionnel)
            couche_conv  : str (optionnel) — nom de la couche conv pour Grad-CAM

        Retourne :
            JSON : rapport + images Grad-CAM encodées en Base64
        """
        try:
            fichier     = request.files.get('image')
            image_bytes = valider_image(fichier)
            localisation = request.form.get('localisation', 'variable')
            couche_conv  = request.form.get('couche_conv', None)

            model  = charger_modele_global()
            rapport = diagnostiquer_depuis_bytes(image_bytes, model, localisation)

            # Sauvegarde temporaire pour Grad-CAM
            chemin_tmp = os.path.join(EXPORTS_DIR, '_temp_gradcam.jpg')
            os.makedirs(EXPORTS_DIR, exist_ok=True)
            with open(chemin_tmp, 'wb') as f:
                f.write(image_bytes)

            # Calcul Grad-CAM
            classe_idx = rapport['niveau1_cnn']['classe_idx']
            nom_couche  = couche_conv or 'conv_bloc_4_conv'
            gc_result   = gradcam_complet(model, chemin_tmp, classe_idx, nom_couche)

            # Mise à jour de la localisation depuis Grad-CAM
            loc_auto = gc_result['localisation']['localisation']
            if localisation == 'variable' and loc_auto != 'variable':
                rapport = diagnostiquer_depuis_bytes(image_bytes, model, loc_auto)

            # Encodage des images en Base64 pour le front-end
            rapport['gradcam'] = {
                'superposition_b64':  image_vers_base64(gc_result['superposition']),
                'image_originale_b64': image_vers_base64(gc_result['image_originale']),
                'localisation':        gc_result['localisation'],
                'bounding_box':        gc_result['bounding_box']
            }

            # Nettoyage fichier temporaire
            if os.path.exists(chemin_tmp):
                os.remove(chemin_tmp)

            return reponse_succes(rapport)

        except ValueError as e:
            return reponse_erreur(str(e), code=400)
        except Exception as e:
            detail = traceback.format_exc() if app.debug else None
            return reponse_erreur(
                "Erreur lors du calcul Grad-CAM.", code=500, detail=detail
            )

    # ── POST /predict/batch ───────────────────────────────────────────────────

    @app.route('/predict/batch', methods=['POST'])
    def predict_batch():
        """
        Prédiction sur plusieurs images CT Scan en une requête.

        Corps de la requête (multipart/form-data) :
            images[]     : fichiers images (plusieurs)
            localisation : str (optionnel, appliqué à toutes)

        Retourne :
            JSON : liste de rapports
        """
        try:
            fichiers     = request.files.getlist('images[]')
            localisation = request.form.get('localisation', 'variable')

            if not fichiers:
                return reponse_erreur("Aucune image reçue.", code=400)
            if len(fichiers) > 20:
                return reponse_erreur("Maximum 20 images par lot.", code=400)

            model    = charger_modele_global()
            resultats = []

            for fichier in fichiers:
                try:
                    image_bytes = valider_image(fichier)
                    rapport     = diagnostiquer_depuis_bytes(image_bytes, model, localisation)
                    resultats.append({
                        'image':   fichier.filename,
                        'rapport': rapport,
                        'erreur':  None
                    })
                except Exception as e:
                    resultats.append({
                        'image':   fichier.filename,
                        'rapport': None,
                        'erreur':  str(e)
                    })

            return reponse_succes({
                'total':    len(fichiers),
                'analyses': len([r for r in resultats if not r['erreur']]),
                'erreurs':  len([r for r in resultats if r['erreur']]),
                'resultats': resultats
            })

        except Exception as e:
            return reponse_erreur("Erreur lors de l'analyse par lot.", code=500)

    # ── Gestion des erreurs ───────────────────────────────────────────────────

    @app.errorhandler(404)
    def not_found(e):
        return reponse_erreur("Endpoint introuvable.", code=404)

    @app.errorhandler(405)
    def method_not_allowed(e):
        return reponse_erreur("Méthode HTTP non autorisée.", code=405)

    @app.errorhandler(413)
    def request_entity_too_large(e):
        return reponse_erreur("Fichier trop volumineux (max 50 Mo).", code=413)
