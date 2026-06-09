"""
=============================================================================
preprocess.py — Module de prétraitement des images CT Scan
=============================================================================
Toutes les fonctions de chargement, nettoyage et préparation des données.
Programmation modulaire : chaque opération est une fonction indépendante.
=============================================================================
"""

import os
import cv2
import numpy as np
from pathlib import Path

import tensorflow as tf
from tensorflow.keras.preprocessing.image import ImageDataGenerator
from tensorflow.keras.utils import to_categorical

from config import (
    IMG_SIZE, IMG_CHANNELS, INPUT_SHAPE,
    CLASS_NAMES, NUM_CLASSES, BATCH_SIZE,
    TRAIN_DIR, VAL_DIR, TEST_DIR,
    USE_AUGMENTATION, ROTATION_RANGE, ZOOM_RANGE,
    HORIZONTAL_FLIP, BRIGHTNESS_RANGE
)


# =============================================================================
# 1. Chargement et décodage d'une image
# =============================================================================

def charger_image(chemin: str, taille: tuple = IMG_SIZE) -> np.ndarray:
    """
    Charge une image CT Scan depuis un chemin et la prépare.

    Paramètres
    ----------
    chemin : str    Chemin vers l'image
    taille : tuple  (hauteur, largeur) cible, défaut depuis config

    Retourne
    --------
    np.ndarray : image de forme (hauteur, largeur, canaux), float32 dans [0,1]
    """
    image = cv2.imread(chemin)
    if image is None:
        raise FileNotFoundError(f"Impossible de charger l'image : {chemin}")
    image = cv2.cvtColor(image, cv2.COLOR_BGR2RGB)
    image = redimensionner(image, taille)
    image = normaliser(image)
    return image


# =============================================================================
# 2. Opérations unitaires de prétraitement
# =============================================================================

def redimensionner(image: np.ndarray, taille: tuple = IMG_SIZE) -> np.ndarray:
    """
    Redimensionne une image en conservant les proportions (padding noir).

    Paramètres
    ----------
    image : np.ndarray   Image source
    taille : tuple       (hauteur, largeur) cible

    Retourne
    --------
    np.ndarray : image redimensionnée
    """
    h_cible, w_cible = taille
    h, w = image.shape[:2]

    # Ratio de mise à l'échelle
    ratio = min(h_cible / h, w_cible / w)
    h_new = int(h * ratio)
    w_new = int(w * ratio)

    image_redim = cv2.resize(image, (w_new, h_new), interpolation=cv2.INTER_AREA)

    # Padding pour atteindre la taille cible
    delta_h = h_cible - h_new
    delta_w = w_cible - w_new
    top, bottom = delta_h // 2, delta_h - delta_h // 2
    left, right  = delta_w // 2, delta_w - delta_w // 2

    image_paddee = cv2.copyMakeBorder(
        image_redim, top, bottom, left, right,
        cv2.BORDER_CONSTANT, value=[0, 0, 0]
    )
    return image_paddee


def normaliser(image: np.ndarray) -> np.ndarray:
    """
    Normalise les pixels de [0, 255] vers [0.0, 1.0].

    Paramètres
    ----------
    image : np.ndarray

    Retourne
    --------
    np.ndarray : float32
    """
    return image.astype(np.float32) / 255.0


def ameliorer_contraste(image: np.ndarray) -> np.ndarray:
    """
    Améliore le contraste via CLAHE (Contrast Limited Adaptive Histogram
    Equalization), bien adapté aux images médicales CT Scan.

    Paramètres
    ----------
    image : np.ndarray   Image RGB normalisée [0, 1]

    Retourne
    --------
    np.ndarray : image avec contraste amélioré
    """
    # Reconvertir en uint8 pour OpenCV
    img_uint8 = (image * 255).astype(np.uint8)
    img_lab   = cv2.cvtColor(img_uint8, cv2.COLOR_RGB2LAB)

    # Appliquer CLAHE sur le canal L (luminosité)
    clahe = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8))
    img_lab[:, :, 0] = clahe.apply(img_lab[:, :, 0])

    img_rgb = cv2.cvtColor(img_lab, cv2.COLOR_LAB2RGB)
    return img_rgb.astype(np.float32) / 255.0


def supprimer_bruit(image: np.ndarray, force: int = 5) -> np.ndarray:
    """
    Supprime le bruit via filtre gaussien.

    Paramètres
    ----------
    image : np.ndarray   Image normalisée [0, 1]
    force : int          Taille du kernel (impair), ex: 3, 5, 7

    Retourne
    --------
    np.ndarray : image débruitée
    """
    img_uint8  = (image * 255).astype(np.uint8)
    img_filtre = cv2.GaussianBlur(img_uint8, (force, force), 0)
    return img_filtre.astype(np.float32) / 255.0


def convertir_grayscale(image: np.ndarray) -> np.ndarray:
    """
    Convertit une image RGB en niveaux de gris (3 canaux dupliqués).
    Utile pour certains modèles CT Scan en nuances de gris.

    Paramètres
    ----------
    image : np.ndarray   Image RGB [H, W, 3]

    Retourne
    --------
    np.ndarray : image [H, W, 3] en nuances de gris
    """
    img_uint8 = (image * 255).astype(np.uint8)
    gray      = cv2.cvtColor(img_uint8, cv2.COLOR_RGB2GRAY)
    gray_3ch  = np.stack([gray, gray, gray], axis=-1)
    return gray_3ch.astype(np.float32) / 255.0


# =============================================================================
# 3. Pipeline complet de prétraitement
# =============================================================================

def pipeline_pretraitement(
    image: np.ndarray,
    appliquer_contraste: bool = True,
    appliquer_debruitage: bool = True,
    force_debruitage: int = 3
) -> np.ndarray:
    """
    Applique la chaîne complète de prétraitement sur une image.
    Inspiré du notebook ANN : les étapes s'enchaînent de manière
    modulaire et chaque étape est activable par paramètre.

    Paramètres
    ----------
    image              : np.ndarray   Image brute RGB uint8 ou float32
    appliquer_contraste: bool         Active CLAHE (défaut: True)
    appliquer_debruitage: bool        Active le filtre gaussien (défaut: True)
    force_debruitage   : int          Kernel du filtre gaussien

    Retourne
    --------
    np.ndarray : image prête pour le CNN, shape (224, 224, 3), float32 [0,1]
    """
    # Étape 1 : Redimensionnement
    image = redimensionner(image, IMG_SIZE)

    # Étape 2 : Amélioration du contraste
    if appliquer_contraste:
        image = normaliser(image)  # besoin de [0,1] pour CLAHE
        image = ameliorer_contraste(image)
    else:
        image = normaliser(image)

    # Étape 3 : Suppression du bruit
    if appliquer_debruitage:
        image = supprimer_bruit(image, force=force_debruitage)

    return image


def pretraiter_depuis_chemin(
    chemin: str,
    appliquer_contraste: bool = True,
    appliquer_debruitage: bool = True
) -> np.ndarray:
    """
    Charge et prétraite une image depuis son chemin disque.
    Point d'entrée unique pour la prédiction en production.

    Paramètres
    ----------
    chemin              : str    Chemin vers l'image CT Scan
    appliquer_contraste : bool
    appliquer_debruitage: bool

    Retourne
    --------
    np.ndarray : image prête, shape (1, 224, 224, 3) — batch de 1
    """
    image_brute = cv2.imread(chemin)
    if image_brute is None:
        raise FileNotFoundError(f"Image introuvable : {chemin}")

    image_rgb = cv2.cvtColor(image_brute, cv2.COLOR_BGR2RGB)
    image_prep = pipeline_pretraitement(
        image_rgb,
        appliquer_contraste=appliquer_contraste,
        appliquer_debruitage=appliquer_debruitage
    )
    return np.expand_dims(image_prep, axis=0)  # (1, H, W, C)


# =============================================================================
# 4. Générateurs de données (train / val / test)
# =============================================================================

def creer_generateur_augmentation() -> ImageDataGenerator:
    """
    Crée un générateur Keras avec augmentation pour l'entraînement.
    Les paramètres proviennent de config.py.

    Retourne
    --------
    ImageDataGenerator configuré pour l'entraînement
    """
    return ImageDataGenerator(
        rescale=1.0 / 255,
        rotation_range=ROTATION_RANGE if USE_AUGMENTATION else 0,
        zoom_range=ZOOM_RANGE if USE_AUGMENTATION else 0,
        horizontal_flip=HORIZONTAL_FLIP if USE_AUGMENTATION else False,
        brightness_range=BRIGHTNESS_RANGE if USE_AUGMENTATION else None,
        width_shift_range=0.1 if USE_AUGMENTATION else 0,
        height_shift_range=0.1 if USE_AUGMENTATION else 0,
        fill_mode='nearest'
    )


def creer_generateur_evaluation() -> ImageDataGenerator:
    """
    Crée un générateur Keras simple (rescale uniquement) pour val/test.

    Retourne
    --------
    ImageDataGenerator sans augmentation
    """
    return ImageDataGenerator(rescale=1.0 / 255)


def charger_donnees_depuis_dossiers(
    batch_size: int = BATCH_SIZE,
    taille: tuple = IMG_SIZE
) -> tuple:
    """
    Charge les données depuis les dossiers train/val/test via Keras.
    Structure attendue :
        dataset/
            train/    normal/ begnin_case/ malignant_case/
            validation/  ...
            test/        ...

    Paramètres
    ----------
    batch_size : int     Taille des batchs
    taille     : tuple   (hauteur, largeur) des images

    Retourne
    --------
    tuple : (generateur_train, generateur_val, generateur_test)
    """
    gen_train = creer_generateur_augmentation()
    gen_eval  = creer_generateur_evaluation()

    flux_train = gen_train.flow_from_directory(
        TRAIN_DIR,
        target_size=taille,
        batch_size=batch_size,
        class_mode='categorical',
        shuffle=True,
        classes=CLASS_NAMES
    )

    flux_val = gen_eval.flow_from_directory(
        VAL_DIR,
        target_size=taille,
        batch_size=batch_size,
        class_mode='categorical',
        shuffle=False,
        classes=CLASS_NAMES
    )

    flux_test = gen_eval.flow_from_directory(
        TEST_DIR,
        target_size=taille,
        batch_size=batch_size,
        class_mode='categorical',
        shuffle=False,
        classes=CLASS_NAMES
    )

    return flux_train, flux_val, flux_test


def charger_donnees_depuis_csv(
    annotations_csv: str,
    images_dir: str,
    batch_size: int = BATCH_SIZE
):
    """
    Charge les données depuis un fichier CSV d'annotations (format Roboflow).
    Compatible avec _annotations.csv du dataset CT Scan.

    Paramètres
    ----------
    annotations_csv : str   Chemin vers _annotations.csv
    images_dir      : str   Dossier contenant les images
    batch_size      : int

    Retourne
    --------
    tuple : (X, y) numpy arrays prêts pour l'entraînement
    """
    import pandas as pd

    df = pd.read_csv(annotations_csv)
    classes_uniques = sorted(df['class'].unique())

    X_liste = []
    y_liste = []

    for _, ligne in df.iterrows():
        chemin_image = os.path.join(images_dir, ligne['filename'])
        if not os.path.exists(chemin_image):
            continue

        image = cv2.imread(chemin_image)
        if image is None:
            continue

        image_rgb  = cv2.cvtColor(image, cv2.COLOR_BGR2RGB)
        image_prep = pipeline_pretraitement(image_rgb)

        X_liste.append(image_prep)

        label_idx = classes_uniques.index(ligne['class'])
        y_liste.append(label_idx)

    X = np.array(X_liste, dtype=np.float32)
    y = to_categorical(np.array(y_liste), num_classes=len(classes_uniques))

    return X, y, classes_uniques


# =============================================================================
# 5. Utilitaires de visualisation du prétraitement
# =============================================================================

def afficher_etapes_pretraitement(chemin_image: str):
    """
    Affiche côte à côte les étapes du pipeline de prétraitement.
    Utile pour le débogage et la vérification.

    Paramètres
    ----------
    chemin_image : str   Chemin vers une image CT Scan
    """
    import matplotlib.pyplot as plt

    image_brute = cv2.imread(chemin_image)
    image_rgb   = cv2.cvtColor(image_brute, cv2.COLOR_BGR2RGB)

    etapes = [
        ("Original",          image_rgb),
        ("Redimensionné",     redimensionner(image_rgb)),
        ("Normalisé",         normaliser(redimensionner(image_rgb))),
        ("Contraste (CLAHE)", ameliorer_contraste(normaliser(redimensionner(image_rgb)))),
        ("Sans bruit",        supprimer_bruit(ameliorer_contraste(normaliser(redimensionner(image_rgb))))),
    ]

    fig, axes = plt.subplots(1, len(etapes), figsize=(20, 4))
    fig.suptitle("Pipeline de prétraitement CT Scan", fontsize=14, fontweight='bold')

    for ax, (titre, img) in zip(axes, etapes):
        img_affichage = np.clip(img, 0, 1) if img.dtype == np.float32 else img
        ax.imshow(img_affichage)
        ax.set_title(titre, fontsize=10)
        ax.axis('off')

    plt.tight_layout()
    plt.show()
