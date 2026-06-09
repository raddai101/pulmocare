"""
=============================================================================
config.py — Configuration globale du modèle CNN
=============================================================================
Centralise tous les hyperparamètres + la taxonomie médicale complète.
Modifiez UNIQUEMENT ce fichier pour changer le comportement du modèle.
=============================================================================
"""

import os

# ─── Chemins ──────────────────────────────────────────────────────────────────

BASE_DIR     = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DATASET_DIR  = os.path.join(BASE_DIR, 'dataset')
TRAIN_DIR    = os.path.join(DATASET_DIR, 'train')
TEST_DIR     = os.path.join(DATASET_DIR, 'test')
VAL_DIR      = os.path.join(DATASET_DIR, 'validation')
MODEL_DIR    = os.path.join(BASE_DIR, 'model')
WEIGHTS_DIR  = os.path.join(MODEL_DIR, 'weights')
LOGS_DIR     = os.path.join(BASE_DIR, 'logs')
EXPORTS_DIR  = os.path.join(BASE_DIR, 'exports')

MODEL_PATH   = os.path.join(MODEL_DIR, 'cnn_model.h5')
LABELS_PATH  = os.path.join(MODEL_DIR, 'labels.txt')

# ─── Image ────────────────────────────────────────────────────────────────────

IMG_SIZE     = (224, 224)
IMG_CHANNELS = 3
INPUT_SHAPE  = (*IMG_SIZE, IMG_CHANNELS)

# =============================================================================
# TAXONOMIE MÉDICALE COMPLÈTE
# =============================================================================
#
#  Le modèle opère sur DEUX niveaux de classification :
#
#  NIVEAU 1 — Classification primaire (dataset Roboflow)
#  ──────────────────────────────────────────────────────
#  Classes directement apprises par le CNN depuis les images CT Scan :
#    0 → normal       : poumon sain
#    1 → begnin case  : nodule bénin (non cancéreux)
#    2 → malignant case: masse maligne (cancéreuse)
#
#  NIVEAU 2 — Enrichissement diagnostique (post-traitement)
#  ─────────────────────────────────────────────────────────
#  À partir du score de confiance et de la localisation détectée,
#  le système enrichit la prédiction avec :
#    - le type histologique probable (NSCLC / SCLC)
#    - le sous-type (Adénocarcinome, Épidermoïde, Grandes cellules, CPC)
#    - la localisation (central / périphérique)
#    - le stade TNM estimé (0 → IV)
#    - le niveau de risque (faible / modéré / élevé / critique)
#
# =============================================================================

# ── Niveau 1 : Classes CNN ────────────────────────────────────────────────────

CLASS_NAMES = ['normal', 'begnin case', 'malignant case']
NUM_CLASSES = len(CLASS_NAMES)

# Index des classes
IDX_NORMAL    = 0
IDX_BEGNIN    = 1
IDX_MALIGNANT = 2

# ── Niveau 2 : Taxonomie histologique (enrichissement) ───────────────────────

# Types principaux de cancer pulmonaire
CANCER_TYPES = {
    'NSCLC': {
        'nom_complet': 'Cancer du Poumon Non à Petites Cellules',
        'frequence':   '≈ 85%',
        'sous_types': {
            'adenocarcinome': {
                'nom':         'Adénocarcinome',
                'localisation':'périphérique',
                'frequence':   'le plus fréquent',
                'tabac':       'fumeurs et non-fumeurs',
                'description': 'Situé à la périphérie du poumon, souvent découvert '
                               'à un stade précoce sur les images CT Scan.'
            },
            'epidermoide': {
                'nom':         'Carcinome Épidermoïde',
                'localisation':'central',
                'frequence':   'fréquent',
                'tabac':       'fortement lié au tabac',
                'description': 'Généralement proche des grosses bronches. '
                               'Fortement associé au tabagisme.'
            },
            'grandes_cellules': {
                'nom':         'Carcinome à Grandes Cellules',
                'localisation':'variable',
                'frequence':   'plus rare',
                'tabac':       'lié au tabac',
                'description': 'Peut apparaître dans différentes zones du poumon. '
                               'Croissance rapide, pronostic souvent défavorable.'
            }
        }
    },
    'SCLC': {
        'nom_complet': 'Cancer du Poumon à Petites Cellules',
        'frequence':   '≈ 15%',
        'sous_types': {
            'a_petites_cellules': {
                'nom':         'Carcinome à Petites Cellules',
                'localisation':'central',
                'frequence':   '≈ 15% des cancers pulmonaires',
                'tabac':       'très fortement lié au tabac',
                'description': 'Très agressif, évolue rapidement. Souvent central, '
                               'près des bronches. Fortement associé au tabagisme.'
            }
        }
    }
}

# ── Stades TNM ────────────────────────────────────────────────────────────────

STADES_TNM = {
    0: {
        'label':       'Stade 0',
        'code_tnm':    'Tis N0 M0',
        'description': 'Cellules cancéreuses uniquement en surface. Pas d\'invasion profonde.',
        'gravite':     'faible',
        'couleur_ui':  '#22c55e'   # vert
    },
    1: {
        'label':       'Stade I',
        'code_tnm':    'T1-T2a N0 M0',
        'description': 'Petite tumeur localisée. Aucun ganglion atteint.',
        'gravite':     'modere',
        'couleur_ui':  '#84cc16'   # vert-jaune
    },
    2: {
        'label':       'Stade II',
        'code_tnm':    'T1-T3 N1 M0',
        'description': 'Tumeur plus grande ou ganglions proches atteints.',
        'gravite':     'modere',
        'couleur_ui':  '#eab308'   # jaune
    },
    3: {
        'label':       'Stade III',
        'code_tnm':    'T1-T4 N2-N3 M0',
        'description': 'Extension importante : ganglions du thorax ou structures voisines.',
        'gravite':     'eleve',
        'couleur_ui':  '#f97316'   # orange
    },
    4: {
        'label':       'Stade IV',
        'code_tnm':    'Tout T, Tout N, M1',
        'description': 'Métastases à distance : cerveau, foie, os, autre poumon.',
        'gravite':     'critique',
        'couleur_ui':  '#ef4444'   # rouge
    }
}

# ── Localisations tumorales ───────────────────────────────────────────────────

LOCALISATIONS = {
    'central': {
        'label':       'Cancer Central',
        'zones':       ['bronches principales', 'médiastin', 'grosses voies respiratoires'],
        'types_assoc': ['carcinome épidermoïde', 'cancer à petites cellules']
    },
    'peripherique': {
        'label':       'Cancer Périphérique',
        'zones':       ['bords externes du poumon'],
        'types_assoc': ['adénocarcinome']
    },
    'variable': {
        'label':       'Localisation Variable',
        'zones':       ['diverses zones pulmonaires'],
        'types_assoc': ['carcinome à grandes cellules']
    }
}

# ── Niveaux de risque (seuils de confiance) ───────────────────────────────────

SEUILS_RISQUE = {
    'faible':    (0.0,  0.30),  # confidence malignant < 30%
    'modere':    (0.30, 0.60),  # confidence malignant 30-60%
    'eleve':     (0.60, 0.85),  # confidence malignant 60-85%
    'critique':  (0.85, 1.00)   # confidence malignant > 85%
}

COULEURS_RISQUE = {
    'faible':   '#22c55e',
    'modere':   '#eab308',
    'eleve':    '#f97316',
    'critique': '#ef4444'
}

# ─── Architecture CNN ─────────────────────────────────────────────────────────
#
#   conv_blocks  : tuple de (nb_filtres, taille_kernel, stride)
#   dense_layers : tuple de (nb_neurones, dropout_rate)
#
# ─────────────────────────────────────────────────────────────────────────────

CONV_BLOCKS = (
    (32,  3, 1),
    (64,  3, 1),
    (128, 3, 1),
    (256, 3, 1),
)

DENSE_LAYERS = (
    (512, 0.5),
    (256, 0.3),
)

POOLING_SIZE     = (2, 2)
USE_BATCH_NORM   = True
ACTIVATION_CONV  = 'relu'
ACTIVATION_DENSE = 'relu'
ACTIVATION_OUT   = 'softmax'

# ─── Entraînement ────────────────────────────────────────────────────────────

LEARNING_RATE = 0.001
BATCH_SIZE    = 32
N_EPOCHS      = 50
OPTIMIZER     = 'adam'
LOSS_FUNCTION = 'categorical_crossentropy'

# ─── Augmentation des données ────────────────────────────────────────────────

USE_AUGMENTATION = True
ROTATION_RANGE   = 15
ZOOM_RANGE       = 0.1
HORIZONTAL_FLIP  = True
BRIGHTNESS_RANGE = (0.8, 1.2)

# ─── Callbacks ───────────────────────────────────────────────────────────────

EARLY_STOPPING_PATIENCE = 10
REDUCE_LR_PATIENCE      = 5
REDUCE_LR_FACTOR        = 0.5
MIN_LR                  = 1e-7
CHECKPOINT_MONITOR      = 'val_accuracy'

# ─── Grad-CAM ────────────────────────────────────────────────────────────────

GRADCAM_LAYER       = 'conv_bloc_4_conv'   # dernière couche conv du modèle
GRADCAM_ALPHA       = 0.4                  # transparence de la heatmap
GRADCAM_COLORMAP    = 'jet'               # colormap OpenCV
