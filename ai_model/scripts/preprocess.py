"""
=============================================================================
preprocess.py — Module de prétraitement des images CT Scan
=============================================================================
Toutes les fonctions de chargement, nettoyage et préparation des données.
Programmation modulaire : chaque opération est une fonction indépendante.

─────────────────────────────────────────────────────────────────────────────
AJOUT (Option A — alignement entraînement / inférence) :

  charger_donnees_depuis_dossiers() (utilisé pour ENTRAÎNER le modèle) passe
  par Keras ImageDataGenerator.flow_from_directory(), qui fait un simple
  redimensionnement par ÉTIREMENT + rescale=1/255 — SANS CLAHE, SANS
  débruitage, SANS padding préservant le ratio (letterbox).

  Or pretraiter_depuis_chemin()/pipeline_pretraitement() (utilisés à
  l'INFÉRENCE, donc par predict.py et gradcam.py) appliquaient un pipeline
  bien plus riche : letterbox + CLAHE + flou gaussien. Résultat : le modèle
  voyait à l'inférence une distribution d'images qu'il n'avait JAMAIS vue à
  l'entraînement — ce qui faussait la classification et rendait Grad-CAM
  incohérent (le réseau se raccrochait aux bandes noires artificielles du
  letterbox plutôt qu'au contenu pulmonaire).

  pretraiter_pour_inference() / pretraiter_tableau_pour_inference() ci-
  dessous reproduisaient ce que fait flow_from_directory (resize simple +
  rescale). Une fois ce correctif appliqué, la heatmap Grad-CAM restait
  concentrée en anneau sur le contour du corps plutôt que sur le poumon :
  ce n'était donc pas (seulement) un décalage de prétraitement, mais un
  vrai biais du modèle — probablement dû à la fusion de deux sources de
  dataset (Roboflow + IQ-OTH/NCCD) avec des marges noires différentes,
  que le CNN a appris à utiliser comme raccourci de classification.

─────────────────────────────────────────────────────────────────────────────
AJOUT (Option B — recadrage sur le contenu + pipeline d'entraînement unifié) :

  recadrer_sur_contenu() retire la marge noire/homogène autour du corps
  AVANT le redimensionnement final, quelle que soit la source d'origine —
  ça neutralise le raccourci "quantité de fond noir" que le modèle avait
  appris. Cette étape est désormais intégrée à
  pretraiter_tableau_pour_inference(), donc utilisée PARTOUT automatiquement
  (predict.py, gradcam.py) sans rien changer ailleurs.

  charger_donnees_tf() est un NOUVEAU pipeline d'entraînement (tf.data),
  qui utilise EXACTEMENT ce même prétraitement — recadrage + resize +
  normalisation identiques à l'inférence, plus l'augmentation de données
  (rotation/zoom/flip/luminosité) uniquement sur le train. Il remplace
  charger_donnees_depuis_dossiers() pour l'ENTRAÎNEMENT ; cette dernière
  reste inchangée et utilisée par evaluate.py (qui dépend d'attributs
  propres aux générateurs Keras — .reset(), .samples — absents de
  tf.data.Dataset).

  calculer_class_weights() calcule les poids de classe 'balanced' à partir
  du nombre réel d'images par classe dans le dossier train — réduit le
  biais dû au déséquilibre entre classes.
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
    
    return image.astype(np.float32) / 255.0


def ameliorer_contraste(image: np.ndarray) -> np.ndarray:
    
    # Reconvertir en uint8 pour OpenCV
    img_uint8 = (image * 255).astype(np.uint8)
    img_lab   = cv2.cvtColor(img_uint8, cv2.COLOR_RGB2LAB)

    # Appliquer CLAHE sur le canal L (luminosité)
    clahe = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8))
    img_lab[:, :, 0] = clahe.apply(img_lab[:, :, 0])

    img_rgb = cv2.cvtColor(img_lab, cv2.COLOR_LAB2RGB)
    return img_rgb.astype(np.float32) / 255.0


def supprimer_bruit(image: np.ndarray, force: int = 5) -> np.ndarray:
    
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
# 3. Pipeline complet de prétraitement (riche — CLAHE + débruitage + letterbox)
#    Conservé pour usage futur (Option B / ré-entraînement enrichi).
# =============================================================================

def pipeline_pretraitement(
    image: np.ndarray,
    appliquer_contraste: bool = True,
    appliquer_debruitage: bool = True,
    force_debruitage: int = 3
) -> np.ndarray:
    
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
# 3bis. Prétraitement d'INFÉRENCE aligné sur l'entraînement (Option A)
# =============================================================================
#
#   Keras ImageDataGenerator.flow_from_directory(target_size=IMG_SIZE) fait,
#   pour CHAQUE image d'entraînement :
#     1. Chargement (PIL) en RGB
#     2. Redimensionnement DIRECT à target_size (étirement, sans préserver
#        le ratio, sans padding)
#     3. rescale=1/255 (simple division, aucune autre transformation)
#
#   Les deux fonctions ci-dessous reproduisent EXACTEMENT ces trois étapes,
#   avec OpenCV, pour que l'image envoyée au modèle à l'inférence suive la
#   même distribution que celle vue à l'entraînement.
# =============================================================================

def pretraiter_pour_inference(chemin: str, taille: tuple = IMG_SIZE) -> np.ndarray:
    """
    Charge et prétraite une image CT Scan pour l'inférence, en reproduisant
    exactement le comportement de Keras flow_from_directory (entraînement) :
    redimensionnement par étirement + rescale, sans CLAHE ni débruitage ni
    padding.

    Paramètres
    ----------
    chemin : str    Chemin vers l'image
    taille : tuple  (hauteur, largeur) cible

    Retourne
    --------
    np.ndarray : shape (1, H, W, C), float32 dans [0, 1]
    """
    image_brute = cv2.imread(chemin)
    if image_brute is None:
        raise FileNotFoundError(f"Image introuvable : {chemin}")

    image_rgb = cv2.cvtColor(image_brute, cv2.COLOR_BGR2RGB)
    image_prep = pretraiter_tableau_pour_inference(image_rgb, taille)
    return np.expand_dims(image_prep, axis=0)  # (1, H, W, C)


def pretraiter_tableau_pour_inference(image_rgb: np.ndarray, taille: tuple = IMG_SIZE) -> np.ndarray:
    """
    Version "tableau déjà en mémoire" de pretraiter_pour_inference() —
    utilisée pour les images reçues en bytes depuis l'API PHP (upload).

    CORRECTIF (Option B) : recadrage sur le contenu réel AVANT le resize,
    pour retirer la marge noire variable qui servait de raccourci au modèle.

    Paramètres
    ----------
    image_rgb : np.ndarray   Image RGB uint8, taille quelconque
    taille    : tuple        (hauteur, largeur) cible

    Retourne
    --------
    np.ndarray : shape (H, W, C), float32 dans [0, 1]
    """
    image_recadree = recadrer_sur_contenu(image_rgb)

    h_cible, w_cible = taille
    # cv2.resize attend (largeur, hauteur) — étirement direct, comme PIL
    # load_img(target_size=...), sans préserver le ratio d'aspect.
    image_redim = cv2.resize(image_recadree, (w_cible, h_cible), interpolation=cv2.INTER_LINEAR)
    return normaliser(image_redim)


def recadrer_sur_contenu(image: np.ndarray, seuil: int = 10, marge: float = 0.03) -> np.ndarray:
    """
    Recadre l'image sur la zone de contenu réel (le corps du patient),
    en retirant la marge noire/homogène qui l'entoure.

    Pourquoi : si deux sources de dataset (ex: Roboflow vs IQ-OTH/NCCD)
    n'ont pas le même champ de vue d'origine, la QUANTITÉ de marge noire
    après redimensionnement diffère systématiquement selon la source — et
    donc, indirectement, selon la classe si les sources ne sont pas
    réparties également entre classes. Le CNN peut alors apprendre "plus
    de noir en bordure = telle classe" au lieu d'apprendre les vraies
    textures pulmonaires (c'est ce que montrait la heatmap Grad-CAM
    concentrée en anneau sur le contour du corps). Ce recadrage neutralise
    ce raccourci en ramenant toutes les images à leur seul contenu réel
    avant le redimensionnement final.

    Paramètres
    ----------
    image : np.ndarray   Image RGB (ou niveaux de gris), uint8
    seuil : int          Intensité en dessous de laquelle un pixel est
                          considéré comme "fond" (0-255)
    marge : float        Marge de sécurité ajoutée autour du contenu
                          détecté (proportion de sa taille)

    Retourne
    --------
    np.ndarray : image recadrée sur le contenu (ou l'image d'origine si
                 aucun contenu n'a pu être détecté — image entièrement
                 uniforme, cas limite).
    """
    gris = cv2.cvtColor(image, cv2.COLOR_RGB2GRAY) if image.ndim == 3 else image
    masque = gris > seuil

    if not masque.any():
        return image  # image entièrement noire/uniforme : rien à recadrer

    coords = np.argwhere(masque)
    y0, x0 = coords.min(axis=0)
    y1, x1 = coords.max(axis=0) + 1

    h, w = gris.shape[:2]
    marge_y = int((y1 - y0) * marge)
    marge_x = int((x1 - x0) * marge)

    y0 = max(0, y0 - marge_y)
    x0 = max(0, x0 - marge_x)
    y1 = min(h, y1 + marge_y)
    x1 = min(w, x1 + marge_x)

    return image[y0:y1, x0:x1]


# =============================================================================
# 4bis. Pipeline d'ENTRAÎNEMENT tf.data — aligné sur l'inférence (Option B)
# =============================================================================
#
#   NE remplace PAS charger_donnees_depuis_dossiers() ci-dessous : cette
#   dernière reste utilisée par evaluate.py, qui dépend d'attributs propres
#   aux générateurs Keras (.reset(), .samples) absents de tf.data.Dataset.
#
#   charger_donnees_tf() est le nouveau pipeline recommandé pour
#   L'ENTRAÎNEMENT (main.py --mode train, train_model.py) : il utilise
#   EXACTEMENT pretraiter_tableau_pour_inference() (recadrage + resize +
#   normalisation), identique à ce que voit le modèle en production.
# =============================================================================

_EXTENSIONS_IMAGE = ('.png', '.jpg', '.jpeg', '.bmp', '.tif', '.tiff')


def compter_images_par_classe(dossier: str) -> dict:
    """
    Compte le nombre d'images par classe dans un dossier (train/val/test).

    Paramètres
    ----------
    dossier : str   Chemin du dossier (ex: TRAIN_DIR)

    Retourne
    --------
    dict : {nom_classe: nombre_images}
    """
    comptes = {}
    for classe in CLASS_NAMES:
        chemin_classe = os.path.join(dossier, classe)
        if os.path.isdir(chemin_classe):
            comptes[classe] = len([
                f for f in os.listdir(chemin_classe)
                if f.lower().endswith(_EXTENSIONS_IMAGE)
            ])
        else:
            comptes[classe] = 0
    return comptes


def calculer_class_weights(dossier_train: str = TRAIN_DIR) -> dict:
    """
    Calcule les poids de classe 'balanced' à partir du nombre RÉEL d'images
    par classe dans le dossier d'entraînement — réduit le biais dû au
    déséquilibre entre classes (ex: 'malignant case' sur-représenté par
    rapport à 'begnin case').

    Paramètres
    ----------
    dossier_train : str   Dossier d'entraînement (défaut : TRAIN_DIR)

    Retourne
    --------
    dict : {index_classe: poids} — directement utilisable en
           model.fit(..., class_weight=...), ou None si aucune image
           trouvée.
    """
    from sklearn.utils.class_weight import compute_class_weight

    comptes = compter_images_par_classe(dossier_train)
    print(f"[Class weights] Répartition dans {dossier_train} : {comptes}")

    y = []
    for idx, classe in enumerate(CLASS_NAMES):
        y += [idx] * comptes[classe]

    if not y:
        print("[Class weights] Aucune image trouvée — pas de pondération appliquée.")
        return None

    poids = compute_class_weight(
        class_weight='balanced',
        classes=np.arange(NUM_CLASSES),
        y=np.array(y)
    )
    class_weight = {i: float(w) for i, w in enumerate(poids)}
    print(f"[Class weights] Poids calculés : {class_weight}")
    return class_weight


def _lister_fichiers_classes(dossier: str) -> tuple:
    """Liste tous les fichiers image d'un dossier split, avec leur label entier."""
    chemins, labels = [], []
    for idx, classe in enumerate(CLASS_NAMES):
        chemin_classe = os.path.join(dossier, classe)
        if not os.path.isdir(chemin_classe):
            continue
        for f in os.listdir(chemin_classe):
            if f.lower().endswith(_EXTENSIONS_IMAGE):
                chemins.append(os.path.join(chemin_classe, f))
                labels.append(idx)
    return chemins, labels


def _charger_image_pour_tf(chemin_bytes, taille: tuple) -> np.ndarray:
    """Callback numpy appelé depuis tf.py_function — charge + prétraite UNE image."""
    chemin = chemin_bytes.numpy().decode('utf-8')
    image_bgr = cv2.imread(chemin)
    if image_bgr is None:
        # Image illisible : on renvoie un tableau noir plutôt que de casser
        # tout le pipeline d'entraînement pour un seul fichier corrompu.
        print(f"[tf.data] Avertissement : image illisible ignorée → {chemin}")
        return np.zeros((*taille, IMG_CHANNELS), dtype=np.float32)
    image_rgb = cv2.cvtColor(image_bgr, cv2.COLOR_BGR2RGB)
    return pretraiter_tableau_pour_inference(image_rgb, taille)


def _mapper_chemin_vers_tenseur(chemin_tensor, label_tensor, taille: tuple):
    image = tf.py_function(
        func=lambda c: _charger_image_pour_tf(c, taille),
        inp=[chemin_tensor],
        Tout=tf.float32
    )
    image.set_shape((*taille, IMG_CHANNELS))
    label = tf.one_hot(label_tensor, NUM_CLASSES)
    return image, label


def _construire_augmentation() -> tf.keras.Sequential:
    """
    Reconstruit les mêmes augmentations que creer_generateur_augmentation()
    (rotation, zoom, flip horizontal, luminosité) mais via des couches
    Keras applicables dans un pipeline tf.data — uniquement pour le train.
    """
    couches = []
    if USE_AUGMENTATION:
        couches.append(tf.keras.layers.RandomRotation(ROTATION_RANGE / 360.0, fill_mode='nearest'))
        couches.append(tf.keras.layers.RandomZoom(ZOOM_RANGE, fill_mode='nearest'))
        if HORIZONTAL_FLIP:
            couches.append(tf.keras.layers.RandomFlip('horizontal'))
        couches.append(tf.keras.layers.RandomBrightness(
            factor=(BRIGHTNESS_RANGE[0] - 1.0, BRIGHTNESS_RANGE[1] - 1.0),
            value_range=(0.0, 1.0)  # nos images sont déjà normalisées [0,1]
        ))
    return tf.keras.Sequential(couches, name='augmentation_entrainement')


def charger_donnees_tf(
    batch_size: int = BATCH_SIZE,
    taille: tuple = IMG_SIZE
) -> tuple:
    """
    Pipeline tf.data pour l'ENTRAÎNEMENT — remplace
    charger_donnees_depuis_dossiers() pour main.py --mode train.

    Utilise EXACTEMENT pretraiter_tableau_pour_inference() (recadrage sur
    le contenu + resize + normalisation) pour train/val/test, garantissant
    que le modèle apprend sur la MÊME distribution que celle vue en
    production. L'augmentation (rotation/zoom/flip/luminosité) n'est
    appliquée qu'au flux d'entraînement.

    Paramètres
    ----------
    batch_size : int
    taille     : tuple   (hauteur, largeur) cible

    Retourne
    --------
    tuple : (ds_train, ds_val, ds_test) — tf.data.Dataset, chacun
            produisant des lots (images, labels_one_hot)
    """
    augmentation = _construire_augmentation()

    def _construire_dataset(dossier: str, entrainement: bool) -> tf.data.Dataset:
        chemins, labels = _lister_fichiers_classes(dossier)
        if not chemins:
            raise FileNotFoundError(f"Aucune image trouvée dans : {dossier}")

        ds = tf.data.Dataset.from_tensor_slices((chemins, labels))
        if entrainement:
            ds = ds.shuffle(buffer_size=len(chemins), reshuffle_each_iteration=True)

        ds = ds.map(
            lambda c, l: _mapper_chemin_vers_tenseur(c, l, taille),
            num_parallel_calls=tf.data.AUTOTUNE
        )
        ds = ds.batch(batch_size)

        if entrainement:
            ds = ds.map(
                lambda x, y: (augmentation(x, training=True), y),
                num_parallel_calls=tf.data.AUTOTUNE
            )

        return ds.prefetch(tf.data.AUTOTUNE)

    print(f"[tf.data] Train      : {compter_images_par_classe(TRAIN_DIR)}")
    print(f"[tf.data] Validation : {compter_images_par_classe(VAL_DIR)}")
    print(f"[tf.data] Test       : {compter_images_par_classe(TEST_DIR)}")

    ds_train = _construire_dataset(TRAIN_DIR, entrainement=True)
    ds_val   = _construire_dataset(VAL_DIR,   entrainement=False)
    ds_test  = _construire_dataset(TEST_DIR,  entrainement=False)

    return ds_train, ds_val, ds_test


# =============================================================================
# 4. Générateurs de données Keras (train / val / test)
#    Conservés tels quels — utilisés par evaluate.py qui dépend
#    d'attributs propres aux générateurs Keras (.reset(), .samples).
# =============================================================================

def creer_generateur_augmentation() -> ImageDataGenerator:
    
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
    
    return ImageDataGenerator(rescale=1.0 / 255)


def charger_donnees_depuis_dossiers(
    batch_size: int = BATCH_SIZE,
    taille: tuple = IMG_SIZE
) -> tuple:
    
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


def main():
    import argparse

    parser = argparse.ArgumentParser(
        description='Prétraite une image CT Scan et affiche les informations résultantes.'
    )
    parser.add_argument(
        '--image',
        type=str,
        help='Chemin vers une image CT Scan à prétraiter.'
    )
    parser.add_argument(
        '--no-contrast',
        action='store_true',
        help='Désactive l amélioration du contraste CLAHE.'
    )
    parser.add_argument(
        '--no-denoise',
        action='store_true',
        help='Désactive le filtrage de réduction de bruit.'
    )
    parser.add_argument(
        '--output',
        type=str,
        help='Chemin de sortie pour enregistrer l image prétraitée.'
    )
    parser.add_argument(
        '--show',
        action='store_true',
        help='Affiche l image prétraitée dans une fenêtre OpenCV.'
    )

    args = parser.parse_args()

    if args.image is None:
        parser.print_help()
        return

    if not os.path.exists(args.image):
        raise FileNotFoundError(f"Image introuvable : {args.image}")

    image_prep = pretraiter_depuis_chemin(
        args.image,
        appliquer_contraste=not args.no_contrast,
        appliquer_debruitage=not args.no_denoise
    )

    print(f"Image prétraitée : {args.image}")
    print(f"Forme : {image_prep.shape}")
    print(f"Min/max : {float(image_prep.min()):.4f} / {float(image_prep.max()):.4f}")

    if args.output:
        sortie = args.output
        cv2.imwrite(sortie, (image_prep[0] * 255).astype(np.uint8)[:, :, ::-1])
        print(f"Image enregistrée : {sortie}")

    if args.show:
        cv2.imshow('Image prétraitée', (image_prep[0] * 255).astype(np.uint8)[:, :, ::-1])
        cv2.waitKey(0)
        cv2.destroyAllWindows()


if __name__ == '__main__':
    main()
