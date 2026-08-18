"""
=============================================================================
predict.py — Module de prédiction et de diagnostic enrichi
=============================================================================
Deux niveaux de prédiction :
  Niveau 1 : prédiction CNN brute  → normal / bénin / malin
  Niveau 2 : enrichissement médical → type histologique, localisation,
             stade TNM estimé, niveau de risque, recommandations

Programmation modulaire : chaque fonction a UNE responsabilité.
=============================================================================
"""

import os
import json
import numpy as np
import cv2
from datetime import datetime

from config import (
    CLASS_NAMES, NUM_CLASSES, MODEL_PATH, LABELS_PATH,
    IDX_NORMAL, IDX_BEGNIN, IDX_MALIGNANT,
    CANCER_TYPES, STADES_TNM, LOCALISATIONS,
    SEUILS_RISQUE, COULEURS_RISQUE, IMG_SIZE
)
from preprocess import (
    pretraiter_depuis_chemin, pipeline_pretraitement,
    pretraiter_pour_inference, pretraiter_tableau_pour_inference
)
from model_builder import charger_modele


# =============================================================================
# 1. Prédiction CNN brute  (Niveau 1)
# =============================================================================

def predire_depuis_tableau(
    image_prep: np.ndarray,
    model
) -> dict:
    """
    Effectue la prédiction CNN sur une image déjà prétraitée.
    Équivalent de la fonction `predict()` du notebook ANN.

    Paramètres
    ----------
    image_prep : np.ndarray   Shape (1, H, W, C) ou (H, W, C)
    model      : Keras Model  Modèle chargé

    Retourne
    --------
    dict : {
        'classe_idx'     : int,
        'classe_label'   : str,
        'confiances'     : dict {classe: float},
        'confiance_max'  : float
    }
    """
    # S'assurer que le batch a la bonne forme
    if image_prep.ndim == 3:
        image_prep = np.expand_dims(image_prep, axis=0)

    # Forward pass — équivalent du forward_propagation() du notebook
    predictions = model.predict(image_prep, verbose=0)
    scores      = predictions[0]                       # shape (NUM_CLASSES,)

    classe_idx   = int(np.argmax(scores))
    classe_label = CLASS_NAMES[classe_idx]
    confiance    = float(scores[classe_idx])

    return {
        'classe_idx':    classe_idx,
        'classe_label':  classe_label,
        'confiances':    {CLASS_NAMES[i]: float(scores[i]) for i in range(NUM_CLASSES)},
        'confiance_max': confiance
    }


def predire_depuis_chemin(
    chemin_image: str,
    model,
    appliquer_contraste: bool = False,
    appliquer_debruitage: bool = False
) -> dict:
    """
    Charge, prétraite et prédit depuis un chemin d'image.
    Point d'entrée principal pour la prédiction en production.

    CORRECTIF (Option A) : appliquer_contraste/appliquer_debruitage valent
    désormais False par défaut. Le CNN a été entraîné via Keras
    flow_from_directory (simple resize + rescale, sans CLAHE ni débruitage
    ni padding) — lui donner à l'inférence une image transformée avec un
    pipeline qu'il n'a jamais vu à l'entraînement faussait la prédiction et
    rendait Grad-CAM incohérent. Le prétraitement par défaut reproduit donc
    exactement ce que voit le modèle à l'entraînement. Passer True à l'un
    des deux flags réactive l'ancien pipeline enrichi (utile pour comparer,
    ou si le modèle est un jour ré-entraîné avec ce même pipeline riche).

    Paramètres
    ----------
    chemin_image         : str
    model                : Keras Model
    appliquer_contraste  : bool
    appliquer_debruitage : bool

    Retourne
    --------
    dict : résultat brut de prédiction Niveau 1
    """
    if appliquer_contraste or appliquer_debruitage:
        image_batch = pretraiter_depuis_chemin(
            chemin_image,
            appliquer_contraste=appliquer_contraste,
            appliquer_debruitage=appliquer_debruitage
        )
    else:
        image_batch = pretraiter_pour_inference(chemin_image)

    return predire_depuis_tableau(image_batch, model)


def predire_depuis_bytes(
    image_bytes: bytes,
    model,
    appliquer_contraste: bool = False,
    appliquer_debruitage: bool = False
) -> dict:
    """
    Prédit depuis des bytes d'image (upload PHP via l'API).
    Compatible avec le backend PHP qui envoie l'image en base64 décodée.

    CORRECTIF (Option A) : voir predire_depuis_chemin() ci-dessus — mêmes
    valeurs par défaut, même raisonnement.

    Paramètres
    ----------
    image_bytes          : bytes   Contenu binaire de l'image
    model                : Keras Model
    appliquer_contraste  : bool
    appliquer_debruitage : bool

    Retourne
    --------
    dict : résultat brut de prédiction Niveau 1
    """
    tableau_np  = np.frombuffer(image_bytes, dtype=np.uint8)
    image_brute = cv2.imdecode(tableau_np, cv2.IMREAD_COLOR)
    if image_brute is None:
        raise ValueError("Impossible de décoder l'image depuis les bytes fournis.")

    image_rgb  = cv2.cvtColor(image_brute, cv2.COLOR_BGR2RGB)

    if appliquer_contraste or appliquer_debruitage:
        image_prep = pipeline_pretraitement(
            image_rgb,
            appliquer_contraste=appliquer_contraste,
            appliquer_debruitage=appliquer_debruitage
        )
    else:
        image_prep = pretraiter_tableau_pour_inference(image_rgb)

    return predire_depuis_tableau(np.expand_dims(image_prep, 0), model)


# =============================================================================
# 2. Enrichissement diagnostique  (Niveau 2)
# =============================================================================

def determiner_niveau_risque(confiance_malignant: float) -> str:
    """
    Détermine le niveau de risque selon la confiance pour la classe maligne.

    Paramètres
    ----------
    confiance_malignant : float   Score [0, 1] pour 'malignant case'

    Retourne
    --------
    str : 'faible' | 'modere' | 'eleve' | 'critique'
    """
    for niveau, (seuil_bas, seuil_haut) in SEUILS_RISQUE.items():
        if seuil_bas <= confiance_malignant < seuil_haut:
            return niveau
    return 'critique'


def estimer_stade_tnm(
    confiance_malignant: float,
    localisation_detectee: str = 'variable'
) -> dict:
    """
    Estime le stade TNM probable en fonction de la confiance CNN.
    IMPORTANT : estimation algorithmique uniquement — pas un diagnostic médical.

    Règle d'estimation :
    ─────────────────────────────────────────────────────
    confiance maligne  │ Stade estimé
    ───────────────────┼────────────────────────────────
    0.00 – 0.30        │ Stade 0 (cellules en surface)
    0.30 – 0.50        │ Stade I (tumeur localisée)
    0.50 – 0.65        │ Stade II (extension locale)
    0.65 – 0.80        │ Stade III (extension régionale)
    0.80 – 1.00        │ Stade IV (métastases possibles)
    ─────────────────────────────────────────────────────

    Paramètres
    ----------
    confiance_malignant   : float
    localisation_detectee : str    'central' | 'peripherique' | 'variable'

    Retourne
    --------
    dict : informations complètes du stade TNM
    """
    seuils_stades = [
        (0.00, 0.30, 0),
        (0.30, 0.50, 1),
        (0.50, 0.65, 2),
        (0.65, 0.80, 3),
        (0.80, 1.00, 4),
    ]

    numero_stade = 0
    for bas, haut, stade in seuils_stades:
        if bas <= confiance_malignant < haut:
            numero_stade = stade
            break

    info_stade = STADES_TNM[numero_stade].copy()
    info_stade['numero']              = numero_stade
    info_stade['confiance_associee']  = round(confiance_malignant * 100, 1)
    info_stade['localisation']        = localisation_detectee
    info_stade['avertissement']       = (
        "ESTIMATION ALGORITHMIQUE — Cette information est fournie à titre indicatif "
        "uniquement. Seul un médecin spécialiste peut établir un diagnostic et "
        "déterminer le stade réel par examens complémentaires (biopsie, TEP-scan, IRM)."
    )
    return info_stade


def identifier_type_histologique(
    classe_idx: int,
    confiance_malignant: float,
    localisation: str = 'variable'
) -> dict:
    """
    Identifie le type histologique probable selon la localisation et la confiance.

    Logique :
    - Cancer central (bronches)  → Épidermoïde (NSCLC) ou CPC (SCLC)
    - Cancer périphérique        → Adénocarcinome (NSCLC)
    - Variable / haute confiance → Grandes cellules (NSCLC)
    - Non malin                  → Aucun type histologique

    Paramètres
    ----------
    classe_idx          : int    0=normal, 1=bénin, 2=malin
    confiance_malignant : float
    localisation        : str

    Retourne
    --------
    dict : type histologique avec probabilités relatives
    """
    if classe_idx != IDX_MALIGNANT:
        return {'type_principal': None, 'sous_type': None, 'details': None}

    # Probabilités relatives selon la localisation
    if localisation == 'central':
        if confiance_malignant > 0.75:
            # Haute confiance + central → CPC très probable
            type_key  = 'SCLC'
            sous_type = 'a_petites_cellules'
            prob_rel  = {
                'SCLC – Petites Cellules':    round(confiance_malignant * 60, 1),
                'NSCLC – Épidermoïde':        round(confiance_malignant * 35, 1),
                'NSCLC – Grandes Cellules':   round(confiance_malignant * 5,  1),
            }
        else:
            # Confiance modérée + central → Épidermoïde probable
            type_key  = 'NSCLC'
            sous_type = 'epidermoide'
            prob_rel  = {
                'NSCLC – Épidermoïde':        round(confiance_malignant * 55, 1),
                'SCLC – Petites Cellules':    round(confiance_malignant * 30, 1),
                'NSCLC – Grandes Cellules':   round(confiance_malignant * 15, 1),
            }
    elif localisation == 'peripherique':
        type_key  = 'NSCLC'
        sous_type = 'adenocarcinome'
        prob_rel  = {
            'NSCLC – Adénocarcinome':     round(confiance_malignant * 70, 1),
            'NSCLC – Épidermoïde':        round(confiance_malignant * 20, 1),
            'NSCLC – Grandes Cellules':   round(confiance_malignant * 10, 1),
        }
    else:
        type_key  = 'NSCLC'
        sous_type = 'grandes_cellules'
        prob_rel  = {
            'NSCLC – Adénocarcinome':     round(confiance_malignant * 40, 1),
            'NSCLC – Épidermoïde':        round(confiance_malignant * 35, 1),
            'NSCLC – Grandes Cellules':   round(confiance_malignant * 15, 1),
            'SCLC – Petites Cellules':    round(confiance_malignant * 10, 1),
        }

    type_info   = CANCER_TYPES[type_key]
    sous_details = type_info['sous_types'][sous_type]

    return {
        'type_principal':  type_key,
        'nom_type':        type_info['nom_complet'],
        'frequence_type':  type_info['frequence'],
        'sous_type':       sous_type,
        'nom_sous_type':   sous_details['nom'],
        'localisation_typ':sous_details['localisation'],
        'lien_tabac':      sous_details['tabac'],
        'description':     sous_details['description'],
        'probabilites':    prob_rel,
        'avertissement':   (
            "Probabilités indicatives basées sur l'épidémiologie. "
            "La confirmation histologique nécessite une biopsie."
        )
    }


def generer_recommandations(
    classe_idx: int,
    niveau_risque: str,
    stade_estime: int
) -> list:
    """
    Génère des recommandations cliniques selon le résultat.

    Paramètres
    ----------
    classe_idx    : int
    niveau_risque : str
    stade_estime  : int

    Retourne
    --------
    list : liste de recommandations textuelles
    """
    recommandations = []

    if classe_idx == IDX_NORMAL:
        recommandations = [
            "Aucune anomalie détectée sur cette image CT Scan.",
            "Continuer le suivi régulier selon le protocole médical en vigueur.",
            "En cas de symptômes persistants, consulter un pneumologue.",
            "Un bilan annuel est recommandé pour les patients à risque (tabagisme, antécédents)."
        ]

    elif classe_idx == IDX_BEGNIN:
        recommandations = [
            "Nodule ou masse d'apparence bénigne détecté(e).",
            "Un suivi rapproché par CT Scan (3 à 6 mois) est recommandé.",
            "Consulter un pneumologue pour évaluation complémentaire.",
            "Ne pas négliger : certaines lésions bénignes peuvent évoluer.",
            "Un bilan sanguin et une consultation oncologique préventive sont conseillés."
        ]

    elif classe_idx == IDX_MALIGNANT:
        base = [
            "⚠️  Anomalie suspecte de malignité détectée — Orientation médicale urgente.",
            "Consulter immédiatement un oncologue ou pneumologue spécialisé.",
            "Une biopsie pulmonaire est nécessaire pour confirmer le diagnostic.",
        ]

        par_risque = {
            'modere': [
                "Un PET-scan (TEP) peut aider à préciser l'extension tumorale.",
                "Bilan d'extension recommandé : scanner thoraco-abdomino-pelvien."
            ],
            'eleve': [
                "Bilan d'extension complet urgent : scanner TAP, IRM cérébrale.",
                "Scintigraphie osseuse ou TEP-scanner pour détection de métastases.",
                "Discussion en Réunion de Concertation Pluridisciplinaire (RCP) oncologique.",
                "Evaluation de l'état général pour déterminer les options thérapeutiques."
            ],
            'critique': [
                "⚠️  Masse à forte suspicion de malignité avancée — Prise en charge urgente.",
                "IRM cérébrale de principe (recherche métastases cérébrales).",
                "Bilan biologique complet : NFS, bilan hépatique, marqueurs tumoraux.",
                "Consultation urgente en oncologie thoracique.",
                "Évaluation de la résécabilité chirurgicale ou eligibilité à la chimiothérapie.",
                "Discussion RCP dans les meilleurs délais."
            ]
        }

        recommandations = base + par_risque.get(niveau_risque, [])
        recommandations.append(
            "⚠️  Ce résultat est un outil d'aide au diagnostic — "
            "Il ne remplace pas l'avis d'un médecin spécialiste."
        )

    return recommandations


# =============================================================================
# 3. Diagnostic complet — orchestre les deux niveaux
# =============================================================================

def diagnostiquer(
    chemin_image: str,
    model,
    localisation_hint: str = 'variable',
    appliquer_contraste: bool = True,
    appliquer_debruitage: bool = True
) -> dict:
    """
    Génère un rapport de diagnostic complet à deux niveaux.

    Équivalent du pipeline complet du notebook ANN :
    ─────────────────────────────────────────────────────────────────────
    Notebook :  forward → prédiction → résultat simple
    Ici :       CNN → Niveau 1 (brut) → Niveau 2 (enrichi) → rapport JSON
    ─────────────────────────────────────────────────────────────────────

    Paramètres
    ----------
    chemin_image         : str    Chemin de l'image CT Scan
    model                : Keras Model
    localisation_hint    : str    'central' | 'peripherique' | 'variable'
                                  (peut être fourni par le médecin ou Grad-CAM)
    appliquer_contraste  : bool
    appliquer_debruitage : bool

    Retourne
    --------
    dict : rapport de diagnostic complet (sérialisable en JSON)
    """

    # ── Niveau 1 : Prédiction CNN ─────────────────────────────────────────────
    niveau1 = predire_depuis_chemin(
        chemin_image, model,
        appliquer_contraste=appliquer_contraste,
        appliquer_debruitage=appliquer_debruitage
    )

    classe_idx          = niveau1['classe_idx']
    confiance_malignant = niveau1['confiances'].get('malignant case', 0.0)

    # ── Niveau 2 : Enrichissement ─────────────────────────────────────────────
    niveau_risque   = determiner_niveau_risque(confiance_malignant)
    stade_info      = estimer_stade_tnm(confiance_malignant, localisation_hint)
    type_histo      = identifier_type_histologique(
                          classe_idx, confiance_malignant, localisation_hint)
    recommandations = generer_recommandations(
                          classe_idx, niveau_risque, stade_info['numero'])

    # ── Assemblage du rapport ─────────────────────────────────────────────────
    rapport = {
        'meta': {
            'image':      os.path.basename(chemin_image),
            'horodatage': datetime.now().isoformat(),
            'version_modele': '1.0',
            'disclaimer': (
                "Ce rapport est généré automatiquement par un système d'IA. "
                "Il est destiné à assister les médecins et ne remplace en aucun "
                "cas un diagnostic médical établi par un professionnel de santé."
            )
        },
        'niveau1_cnn': {
            'classe_predite':  niveau1['classe_label'],
            'classe_idx':      classe_idx,
            'confiance':       round(niveau1['confiance_max'] * 100, 2),
            'confiances_detail': {
                k: round(v * 100, 2)
                for k, v in niveau1['confiances'].items()
            }
        },
        'niveau2_diagnostic': {
            'niveau_risque':     niveau_risque,
            'couleur_risque':    COULEURS_RISQUE[niveau_risque],
            'localisation':      LOCALISATIONS.get(localisation_hint, LOCALISATIONS['variable']),
            'stade_tnm':         stade_info,
            'type_histologique': type_histo,
            'recommandations':   recommandations
        }
    }

    return rapport


def diagnostiquer_depuis_bytes(
    image_bytes: bytes,
    model,
    localisation_hint: str = 'variable'
) -> dict:
    """
    Version bytes pour l'API PHP — même structure de retour que diagnostiquer().

    Paramètres
    ----------
    image_bytes      : bytes
    model            : Keras Model
    localisation_hint: str

    Retourne
    --------
    dict : rapport de diagnostic complet
    """
    niveau1 = predire_depuis_bytes(image_bytes, model)

    classe_idx          = niveau1['classe_idx']
    confiance_malignant = niveau1['confiances'].get('malignant case', 0.0)

    niveau_risque   = determiner_niveau_risque(confiance_malignant)
    stade_info      = estimer_stade_tnm(confiance_malignant, localisation_hint)
    type_histo      = identifier_type_histologique(
                          classe_idx, confiance_malignant, localisation_hint)
    recommandations = generer_recommandations(
                          classe_idx, niveau_risque, stade_info['numero'])

    return {
        'meta': {
            'image':      'upload',
            'horodatage': datetime.now().isoformat(),
            'version_modele': '1.0',
            'disclaimer': (
                "Ce rapport est généré automatiquement par un système d'IA. "
                "Il ne remplace pas un diagnostic médical professionnel."
            )
        },
        'niveau1_cnn': {
            'classe_predite':   niveau1['classe_label'],
            'classe_idx':       classe_idx,
            'confiance':        round(niveau1['confiance_max'] * 100, 2),
            'confiances_detail': {
                k: round(v * 100, 2)
                for k, v in niveau1['confiances'].items()
            }
        },
        'niveau2_diagnostic': {
            'niveau_risque':     niveau_risque,
            'couleur_risque':    COULEURS_RISQUE[niveau_risque],
            'localisation':      LOCALISATIONS.get(localisation_hint, LOCALISATIONS['variable']),
            'stade_tnm':         stade_info,
            'type_histologique': type_histo,
            'recommandations':   recommandations
        }
    }


# =============================================================================
# 4. Export du rapport
# =============================================================================

def exporter_rapport_json(rapport: dict, chemin_sortie: str):
    """
    Exporte un rapport de diagnostic en fichier JSON.

    Paramètres
    ----------
    rapport       : dict
    chemin_sortie : str
    """
    os.makedirs(os.path.dirname(chemin_sortie), exist_ok=True)
    with open(chemin_sortie, 'w', encoding='utf-8') as f:
        json.dump(rapport, f, indent=2, ensure_ascii=False)
    print(f"[✓] Rapport exporté : {chemin_sortie}")


def afficher_rapport(rapport: dict):
    """
    Affiche un rapport de diagnostic dans le terminal de façon lisible.

    Paramètres
    ----------
    rapport : dictF
    """
    sep = "═" * 70
    print(f"\n{sep}")
    print("  RAPPORT DE DIAGNOSTIC — Détection Cancer du Poumon")
    print(sep)
    print(f"  Image      : {rapport['meta']['image']}")
    print(f"  Date       : {rapport['meta']['horodatage']}")

    cnn = rapport['niveau1_cnn']
    print(f"\n{'─'*70}")
    print("  [NIVEAU 1] Prédiction CNN")
    print(f"{'─'*70}")
    print(f"  Résultat      : {cnn['classe_predite'].upper()}")
    print(f"  Confiance     : {cnn['confiance']}%")
    print("  Distribution des classes :")
    for classe, conf in cnn['confiances_detail'].items():
        barre = '█' * int(conf / 5)
        print(f"    {classe:<20} {conf:5.1f}%  {barre}")

    diag = rapport['niveau2_diagnostic']
    print(f"\n{'─'*70}")
    print("  [NIVEAU 2] Analyse Diagnostique Enrichie")
    print(f"{'─'*70}")
    print(f"  Niveau de risque  : {diag['niveau_risque'].upper()}")

    stade = diag['stade_tnm']
    print(f"  Stade TNM estimé  : {stade['label']} ({stade['code_tnm']})")
    print(f"  Description       : {stade['description']}")

    hist = diag['type_histologique']
    if hist['type_principal']:
        print(f"\n  Type histologique probable :")
        print(f"    → {hist['nom_type']} ({hist['type_principal']})")
        print(f"    → Sous-type : {hist['nom_sous_type']}")
        print(f"    → Localisation typique : {hist['localisation_typ']}")

    print(f"\n  Recommandations :")
    for i, rec in enumerate(diag['recommandations'], 1):
        print(f"    {i}. {rec}")

    print(f"\n{'─'*70}")
    print(f"  ⚠️   {rapport['meta']['disclaimer']}")
    print(f"{sep}\n")


# =============================================================================
# 5. Prédiction par lot (batch)
# =============================================================================

def predire_lot(
    chemins_images: list,
    model,
    localisation_hint: str = 'variable'
) -> list:
    """
    Effectue la prédiction sur un lot d'images CT Scan.
    Utile pour l'analyse de séries d'examens d'un même patient.

    Paramètres
    ----------
    chemins_images   : list   Liste de chemins d'images
    model            : Keras Model
    localisation_hint: str

    Retourne
    --------
    list : liste de rapports de diagnostic
    """
    rapports = []
    total    = len(chemins_images)

    print(f"\n[Batch] Analyse de {total} image(s)...")

    for i, chemin in enumerate(chemins_images, 1):
        print(f"  [{i}/{total}] {os.path.basename(chemin)}", end=' ')
        try:
            rapport = diagnostiquer(chemin, model, localisation_hint)
            classe  = rapport['niveau1_cnn']['classe_predite']
            conf    = rapport['niveau1_cnn']['confiance']
            print(f"→ {classe} ({conf}%)")
            rapports.append({'chemin': chemin, 'rapport': rapport, 'erreur': None})
        except Exception as e:
            print(f"→ ERREUR : {e}")
            rapports.append({'chemin': chemin, 'rapport': None, 'erreur': str(e)})

    print(f"\n[Batch] Terminé : {len([r for r in rapports if not r['erreur']])}/"
          f"{total} images analysées avec succès.")
    return rapports


# =============================================================================
# 6. Point d'entrée pour test direct
# =============================================================================

if __name__ == '__main__':
    """
    Mode test : exécute une prédiction sur une image exemple.
    """
    import sys
    from pathlib import Path
    
    # Configuration pour le test
    MODEL_PATH_TEST = "models/cnn_lung_cancer.keras*"
    
    print("=" * 70)
    print("  MODE TEST - predict.py")
    print("=" * 70)
    
    # Chercher le modèle
    projet_root = Path(__file__).parent.parent.parent
    model_path = projet_root / "ai_model" / "model" / "cnn_model.h5"
    
    if not model_path.exists():
        print(f"❌ Modèle introuvable : {model_path}")
        print("   Vérifie le chemin du modèle dans config.py")
        sys.exit(1)
    
    # Vérifier si un chemin d'image est fourni en argument
    if len(sys.argv) > 1:
        chemin_image = sys.argv[1]
    else:
        # Chercher une image de test dans assets/uploads
        test_images = list(projet_root.glob("assets/uploads/scans/*.jpg")) + \
                      list(projet_root.glob("assets/uploads/scans/*.png"))
        
        if test_images:
            chemin_image = str(test_images[0])
            print(f"Image trouvée automatiquement : {chemin_image}")
        else:
            print("❌ Aucune image fournie et aucune image trouvée dans assets/uploads/scans/")
            print("Utilisation : python predict.py <chemin_image>")
            sys.exit(1)
    
    # Vérifier que le fichier existe
    if not os.path.exists(chemin_image):
        print(f"❌ Image introuvable : {chemin_image}")
        sys.exit(1)
    
    # Charger le modèle
    print(f"\n[1] Chargement du modèle : {model_path}")
    try:
        modele = charger_modele(str(model_path))
        print("    ✓ Modèle chargé")
    except Exception as e:
        print(f"    ❌ Erreur : {e}")
        sys.exit(1)
    
    # Effectuer le diagnostic
    print(f"\n[2] Diagnostic de : {chemin_image}")
    try:
        rapport = diagnostiquer(chemin_image, modele)
        afficher_rapport(rapport)
    except Exception as e:
        print(f"❌ Erreur lors du diagnostic : {e}")
        import traceback
        traceback.print_exc()


# -----------------------------------------------------------------------------
# Compatibilité API (wrappers simples pour flask_api copy.py)
# -----------------------------------------------------------------------------
MODEL_VERSION = '1.0'

_MODEL_SINGLETON = None

def _get_model_singleton():
    global _MODEL_SINGLETON
    if _MODEL_SINGLETON is None:
        _MODEL_SINGLETON = charger_modele(MODEL_PATH)
    return _MODEL_SINGLETON


def predict(chemin_image: str, localisation: str = 'variable') -> dict:
    """Wrapper simple : charge le modèle (si nécessaire) et retourne
    un objet JSON-compatible avec la clé `donnees` comme attendu
    par le reste de l'application PHP/Flask plus ancien.
    """
    modele = _get_model_singleton()
    rapport = diagnostiquer(chemin_image, modele, localisation)
    return {
        'statut': 'succes',
        'horodatage': datetime.now().isoformat(),
        'donnees': rapport,
    }


def predict_demo(chemin_image: str) -> dict:
    """Retourne une réponse de démonstration (sans TensorFlow)."""
    # Générer un rapport factice minimal
    rapport = {
        'meta': {'image': os.path.basename(chemin_image), 'horodatage': datetime.now().isoformat(), 'version_modele': MODEL_VERSION},
        'niveau1_cnn': {'classe_predite': 'normal', 'classe_idx': 0, 'confiance': 98.7, 'confiances_detail': {'normal': 98.7, 'begnin case': 0.8, 'malignant case': 0.5}},
        'niveau2_diagnostic': {'niveau_risque': 'faible', 'couleur_risque': COULEURS_RISQUE['faible'], 'localisation': LOCALISATIONS['variable'], 'stade_tnm': STADES_TNM[0], 'type_histologique': {}, 'recommandations': ['Aucune anomalie détectée.']}
    }
    return {'statut': 'succes', 'horodatage': datetime.now().isoformat(), 'donnees': rapport}




