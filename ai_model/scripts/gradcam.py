"""
=============================================================================
gradcam.py — Visualisation Grad-CAM (Gradient-weighted Class Activation Map)
=============================================================================
Grad-CAM produit une carte thermique (heatmap) qui met en évidence
les zones de l'image CT Scan ayant le plus influencé la décision du CNN.
Indispensable en médecine : le médecin doit comprendre POURQUOI le modèle
a pris sa décision.

Architecture modulaire : chaque opération est une fonction pure.
=============================================================================
"""

import os
import numpy as np
import cv2
import tensorflow as tf
import matplotlib.pyplot as plt
import matplotlib.patches as mpatches
from matplotlib.colors import LinearSegmentedColormap

from config import (
    IMG_SIZE, CLASS_NAMES, IDX_MALIGNANT,
    GRADCAM_LAYER, GRADCAM_ALPHA, GRADCAM_COLORMAP,
    EXPORTS_DIR, MODEL_PATH, WEIGHTS_DIR
)
from preprocess import pretraiter_depuis_chemin, pipeline_pretraitement


# =============================================================================
# 1. Calcul du gradient Grad-CAM
# =============================================================================

def calculer_gradcam(
    model: tf.keras.Model,
    image_prep: np.ndarray,
    classe_idx: int,
    nom_couche_conv: str = GRADCAM_LAYER
) -> np.ndarray:
    """
    Calcule la carte d'activation Grad-CAM pour une classe donnée.

    Principe :
    ─────────────────────────────────────────────────────────────────
    1. Forward pass jusqu'à la dernière couche convolutive
    2. Calcul du gradient de la classe cible par rapport aux
       activations de cette couche (backprop partiel)
    3. Moyenne des gradients par canal (pooling global)
    4. Combinaison linéaire : poids × cartes d'activation
    5. ReLU + normalisation → heatmap [0, 1]
    ─────────────────────────────────────────────────────────────────

    Paramètres
    ----------
    model           : tf.keras.Model   Modèle CNN chargé
    image_prep      : np.ndarray       Shape (1, H, W, C), float32
    classe_idx      : int              Classe pour laquelle calculer Grad-CAM
    nom_couche_conv : str              Nom de la dernière couche convolutive

    Retourne
    --------
    np.ndarray : heatmap 2D normalisée [0, 1], shape (h_conv, w_conv)
    """
    # Récupérer la couche cible
    try:
        couche_cible = model.get_layer(nom_couche_conv)
    except ValueError:
        # Fallback : chercher la dernière couche Conv2D
        couches_conv = [c for c in model.layers if isinstance(c, tf.keras.layers.Conv2D)]
        if not couches_conv:
            raise RuntimeError("Aucune couche Conv2D trouvée dans le modèle.")
        couche_cible = couches_conv[-1]
        print(f"[Grad-CAM] Couche '{nom_couche_conv}' introuvable → fallback : {couche_cible.name}")

    # Modèle intermédiaire : entrée → (activations_conv, logits_sortie)
    grad_model = tf.keras.Model(
        inputs=model.inputs,
        outputs=[couche_cible.output, model.output]
    )

    # Calcul du gradient avec GradientTape
    with tf.GradientTape() as tape:
        inputs            = tf.cast(image_prep, tf.float32)
        activations_conv, predictions = grad_model(inputs)
        score_classe      = predictions[:, classe_idx]

    # Gradients de la classe cible par rapport aux feature maps
    gradients = tape.gradient(score_classe, activations_conv)

    # Pooling global des gradients : poids par canal
    poids = tf.reduce_mean(gradients, axis=(0, 1, 2))  # shape (nb_filtres,)

    # Combinaison linéaire pondérée des cartes d'activation
    activations_np = activations_conv[0].numpy()       # shape (h, w, nb_filtres)
    poids_np       = poids.numpy()

    heatmap = np.zeros(activations_np.shape[:2], dtype=np.float32)
    for i, p in enumerate(poids_np):
        heatmap += p * activations_np[:, :, i]

    # ReLU : on ne garde que les contributions positives
    heatmap = np.maximum(heatmap, 0)

    # Normalisation [0, 1]
    if heatmap.max() > 0:
        heatmap /= heatmap.max()

    return heatmap


# =============================================================================
# 2. Application de la heatmap sur l'image originale
# =============================================================================

def superposer_heatmap(
    image_originale: np.ndarray,
    heatmap: np.ndarray,
    alpha: float = GRADCAM_ALPHA,
    colormap: int = cv2.COLORMAP_JET
) -> np.ndarray:
    """
    Superpose la heatmap Grad-CAM sur l'image CT Scan originale.

    Paramètres
    ----------
    image_originale : np.ndarray   Image RGB uint8 ou float32 [0,1]
    heatmap         : np.ndarray   Heatmap 2D [0, 1]
    alpha           : float        Transparence de la heatmap [0, 1]
    colormap        : int          Code colormap OpenCV (ex: cv2.COLORMAP_JET)

    Retourne
    --------
    np.ndarray : image fusionnée RGB uint8
    """
    # Normaliser l'image source en uint8
    if image_originale.dtype == np.float32 or image_originale.max() <= 1.0:
        img_uint8 = (image_originale * 255).astype(np.uint8)
    else:
        img_uint8 = image_originale.astype(np.uint8)

    h_img, w_img = img_uint8.shape[:2]

    # Redimensionner la heatmap à la taille de l'image
    heatmap_redim = cv2.resize(heatmap, (w_img, h_img))

    # Convertir en uint8 et appliquer la colormap
    heatmap_uint8   = (heatmap_redim * 255).astype(np.uint8)
    heatmap_couleur = cv2.applyColorMap(heatmap_uint8, colormap)
    heatmap_rgb     = cv2.cvtColor(heatmap_couleur, cv2.COLOR_BGR2RGB)

    # Fusion : image originale + heatmap
    img_rgb_float      = img_uint8.astype(np.float32)
    heatmap_rgb_float  = heatmap_rgb.astype(np.float32)

    superposition = (1 - alpha) * img_rgb_float + alpha * heatmap_rgb_float
    superposition  = np.clip(superposition, 0, 255).astype(np.uint8)

    return superposition


def detecter_localisation_depuis_heatmap(
    heatmap: np.ndarray,
    seuil: float = 0.5
) -> dict:
    """
    Détecte la localisation probable de la tumeur (centrale ou périphérique)
    à partir de la heatmap Grad-CAM.

    Méthode :
    - Centre de masse de la heatmap thresholdée
    - Si le centre de masse est dans le tiers central → 'central'
    - Sinon → 'peripherique'

    Paramètres
    ----------
    heatmap : np.ndarray   Heatmap 2D [0, 1]
    seuil   : float        Seuil de binarisation

    Retourne
    --------
    dict : {
        'localisation' : str,   'central' | 'peripherique' | 'variable'
        'centre_masse' : tuple, (x_rel, y_rel) coordonnées relatives [0,1]
        'aire_activation': float,  proportion de l'image activée
        'confiance_loc'  : float
    }
    """
    h, w = heatmap.shape

    # Binarisation
    heatmap_bin = (heatmap >= seuil).astype(np.uint8)
    aire_totale = h * w
    aire_active = int(np.sum(heatmap_bin))

    if aire_active == 0:
        return {
            'localisation':    'variable',
            'centre_masse':    (0.5, 0.5),
            'aire_activation': 0.0,
            'confiance_loc':   0.0
        }

    # Centre de masse
    coords_y, coords_x = np.where(heatmap_bin == 1)
    cx_abs = float(np.mean(coords_x))
    cy_abs = float(np.mean(coords_y))
    cx_rel = cx_abs / w
    cy_rel = cy_abs / h

    # Zone centrale : [1/3, 2/3] × [1/3, 2/3]
    dans_centre = (1/3 <= cx_rel <= 2/3) and (1/3 <= cy_rel <= 2/3)

    if dans_centre:
        localisation   = 'central'
        confiance_loc  = round(1.0 - abs(cx_rel - 0.5) * 2, 2)
    else:
        localisation   = 'peripherique'
        confiance_loc  = round(max(abs(cx_rel - 0.5), abs(cy_rel - 0.5)) * 2, 2)

    return {
        'localisation':    localisation,
        'centre_masse':    (round(cx_rel, 3), round(cy_rel, 3)),
        'aire_activation': round(aire_active / aire_totale, 3),
        'confiance_loc':   confiance_loc
    }


# =============================================================================
# 3. Bounding box depuis Grad-CAM
# =============================================================================

def extraire_bounding_box(
    heatmap: np.ndarray,
    image_shape: tuple,
    seuil: float = 0.4
) -> dict:
    """
    Extrait une boîte englobante approximative de la zone suspecte
    à partir de la heatmap Grad-CAM.

    Paramètres
    ----------
    heatmap     : np.ndarray   Heatmap 2D [0, 1]
    image_shape : tuple        (H, W) de l'image finale
    seuil       : float        Seuil de binarisation

    Retourne
    --------
    dict : {xmin, ymin, xmax, ymax, cx, cy, largeur, hauteur}
           toutes les valeurs en pixels de l'image finale
    """
    h_img, w_img = image_shape[:2]
    heatmap_redim = cv2.resize(heatmap, (w_img, h_img))
    heatmap_bin   = (heatmap_redim >= seuil).astype(np.uint8) * 255

    contours, _ = cv2.findContours(heatmap_bin, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)

    if not contours:
        return {'xmin': 0, 'ymin': 0, 'xmax': w_img, 'ymax': h_img,
                'cx': w_img//2, 'cy': h_img//2,
                'largeur': w_img, 'hauteur': h_img}

    # Contour avec la plus grande aire
    contour_max = max(contours, key=cv2.contourArea)
    xmin, ymin, largeur, hauteur = cv2.boundingRect(contour_max)
    xmax = xmin + largeur
    ymax = ymin + hauteur

    return {
        'xmin':    int(xmin),
        'ymin':    int(ymin),
        'xmax':    int(xmax),
        'ymax':    int(ymax),
        'cx':      int(xmin + largeur // 2),
        'cy':      int(ymin + hauteur // 2),
        'largeur': int(largeur),
        'hauteur': int(hauteur)
    }


# =============================================================================
# 4. Pipeline Grad-CAM complet
# =============================================================================

def gradcam_complet(
    model: tf.keras.Model,
    chemin_image: str,
    classe_idx: int,
    nom_couche_conv: str = GRADCAM_LAYER,
    alpha: float = GRADCAM_ALPHA
) -> dict:
    """
    Pipeline Grad-CAM complet depuis un chemin d'image.

    Paramètres
    ----------
    model           : tf.keras.Model
    chemin_image    : str
    classe_idx      : int            Classe à expliquer
    nom_couche_conv : str
    alpha           : float          Transparence heatmap

    Retourne
    --------
    dict : {
        'heatmap'         : np.ndarray 2D,
        'superposition'   : np.ndarray RGB uint8,
        'localisation'    : dict,
        'bounding_box'    : dict,
        'image_originale' : np.ndarray
    }
    """
    # Chargement et prétraitement
    image_batch = pretraiter_depuis_chemin(chemin_image)
    image_prep  = image_batch[0]                          # (H, W, C) float32

    # Calcul Grad-CAM
    heatmap = calculer_gradcam(model, image_batch, classe_idx, nom_couche_conv)

    # Superposition
    superposition = superposer_heatmap(image_prep, heatmap, alpha=alpha)

    # Analyse de localisation
    loc_info = detecter_localisation_depuis_heatmap(heatmap)

    # Bounding box
    bbox = extraire_bounding_box(heatmap, image_prep.shape)

    return {
        'heatmap':          heatmap,
        'superposition':    superposition,
        'localisation':     loc_info,
        'bounding_box':     bbox,
        'image_originale':  (image_prep * 255).astype(np.uint8)
    }


# =============================================================================
# 5. Visualisation et export
# =============================================================================

def visualiser_gradcam(
    gradcam_result: dict,
    rapport_diagnostic: dict,
    sauvegarder: bool = True,
    chemin_sortie: str = None
):
    """
    Génère une figure médicale complète Grad-CAM avec :
    - Image originale
    - Heatmap seule
    - Image + heatmap superposée
    - Image + bounding box
    - Résumé du diagnostic

    Paramètres
    ----------
    gradcam_result     : dict   Résultat de gradcam_complet()
    rapport_diagnostic : dict   Rapport de predict.diagnostiquer()
    sauvegarder        : bool   Sauvegarder la figure en PNG
    chemin_sortie      : str    Chemin de sauvegarde (auto si None)
    """
    fig = plt.figure(figsize=(22, 12), facecolor='#0d1117')
    fig.suptitle(
        'RAPPORT GRAD-CAM — Analyse CT Scan Pulmonaire',
        fontsize=16, fontweight='bold', color='white', y=0.98
    )

    # Palette couleur médicale sombre
    coul_texte  = 'white'
    coul_fond   = '#161b22'
    coul_accent = '#58a6ff'

    img_orig    = gradcam_result['image_originale']
    heatmap     = gradcam_result['heatmap']
    superpos    = gradcam_result['superposition']
    bbox        = gradcam_result['bounding_box']
    loc_info    = gradcam_result['localisation']
    cnn         = rapport_diagnostic['niveau1_cnn']
    diag        = rapport_diagnostic['niveau2_diagnostic']

    # ── Axes ─────────────────────────────────────────────────────────────────
    gs = fig.add_gridspec(2, 4, hspace=0.35, wspace=0.3,
                          left=0.05, right=0.95, top=0.92, bottom=0.08)

    # 1. Image originale
    ax1 = fig.add_subplot(gs[0, 0])
    ax1.imshow(img_orig)
    ax1.set_title('CT Scan Original', color=coul_texte, fontsize=11, pad=8)
    ax1.axis('off')
    _style_ax(ax1, coul_fond)

    # 2. Heatmap Grad-CAM seule
    ax2 = fig.add_subplot(gs[0, 1])
    im2 = ax2.imshow(heatmap, cmap='jet', vmin=0, vmax=1)
    ax2.set_title('Carte d\'Activation (Grad-CAM)', color=coul_texte, fontsize=11, pad=8)
    ax2.axis('off')
    _style_ax(ax2, coul_fond)
    plt.colorbar(im2, ax=ax2, fraction=0.046, pad=0.04, label='Activation')

    # 3. Superposition
    ax3 = fig.add_subplot(gs[0, 2])
    ax3.imshow(superpos)
    ax3.set_title('CT Scan + Grad-CAM', color=coul_texte, fontsize=11, pad=8)
    ax3.axis('off')
    _style_ax(ax3, coul_fond)

    # 4. Image + Bounding Box
    ax4 = fig.add_subplot(gs[0, 3])
    ax4.imshow(superpos)
    rect = plt.Rectangle(
        (bbox['xmin'], bbox['ymin']),
        bbox['largeur'], bbox['hauteur'],
        linewidth=2.5, edgecolor='#ff6b6b', facecolor='none',
        linestyle='--'
    )
    ax4.add_patch(rect)
    ax4.plot(bbox['cx'], bbox['cy'], 'r+', markersize=12, markeredgewidth=2)
    ax4.set_title('Zone Suspecte Délimitée', color=coul_texte, fontsize=11, pad=8)
    ax4.axis('off')
    _style_ax(ax4, coul_fond)

    # 5. Panneau de diagnostic (bas, pleine largeur)
    ax5 = fig.add_subplot(gs[1, :])
    ax5.set_facecolor(coul_fond)
    ax5.axis('off')
    _style_ax(ax5, coul_fond)

    # Contenu textuel du diagnostic
    classe   = cnn['classe_predite'].upper()
    conf     = cnn['confiance']
    risque   = diag['niveau_risque'].upper()
    stade    = diag['stade_tnm']['label']
    loc_lab  = loc_info['localisation'].capitalize()
    coul_ris = diag['couleur_risque']

    hist = diag['type_histologique']
    type_lab = hist.get('nom_sous_type', 'Non déterminé') if hist['type_principal'] else 'N/A'

    lignes = [
        f"Classe CNN       : {classe}   ({conf}% de confiance)",
        f"Niveau de Risque : {risque}",
        f"Stade TNM estimé : {stade}",
        f"Localisation     : {loc_lab}  (confiance localisation : {loc_info['confiance_loc']*100:.0f}%)",
        f"Type histologique probable : {type_lab}",
        "",
        "Recommandation principale :",
        diag['recommandations'][0] if diag['recommandations'] else ''
    ]

    y_pos = 0.92
    for ligne in lignes:
        couleur = '#ff6b6b' if '⚠️' in ligne else coul_accent if ':' in ligne else coul_texte
        ax5.text(0.02, y_pos, ligne, transform=ax5.transAxes,
                 fontsize=10, color=couleur, verticalalignment='top',
                 fontfamily='monospace')
        y_pos -= 0.12

    # Légende colorbar
    legende = mpatches.Patch(color='#ff6b6b', label='Zone suspecte délimitée')
    ax5.legend(handles=[legende], loc='upper right',
               facecolor=coul_fond, edgecolor='white',
               labelcolor='white', fontsize=9)

    plt.tight_layout()

    if sauvegarder:
        if chemin_sortie is None:
            os.makedirs(EXPORTS_DIR, exist_ok=True)
            nom_img     = rapport_diagnostic['meta']['image'].replace('.', '_')
            chemin_sortie = os.path.join(EXPORTS_DIR, f'gradcam_{nom_img}.png')
        plt.savefig(chemin_sortie, dpi=150, bbox_inches='tight',
                    facecolor='#0d1117')
        print(f"[✓] Figure Grad-CAM sauvegardée : {chemin_sortie}")

    plt.show()
    return chemin_sortie


def exporter_superposition(
    gradcam_result: dict,
    chemin_sortie: str
):
    """
    Exporte uniquement l'image superposée (CT + heatmap) en PNG.
    Utilisée par le backend PHP pour l'affichage dans l'interface.

    Paramètres
    ----------
    gradcam_result : dict
    chemin_sortie  : str   Chemin de sortie .png
    """
    superposition = gradcam_result['superposition']
    img_bgr       = cv2.cvtColor(superposition, cv2.COLOR_RGB2BGR)
    cv2.imwrite(chemin_sortie, img_bgr)
    print(f"[✓] Superposition exportée : {chemin_sortie}")


# =============================================================================
# 6. Utilitaire interne
# =============================================================================

def _style_ax(ax, couleur_fond: str):
    """Applique le style sombre médical sur un axe matplotlib."""
    ax.set_facecolor(couleur_fond)
    for spine in ax.spines.values():
        spine.set_edgecolor('#30363d')


# =============================================================================
# 7. Point d'entrée principal
# =============================================================================

if __name__ == "__main__":
    """
    Exécution directe du fichier gradcam.py
    Permet de tester Grad-CAM sur une image CT Scan avec le modèle entraîné.
    """
    print("=" * 70)
    print("  GRAD-CAM — Visualisation des Activations CNN")
    print("  CT Scan Pulmonaire - Analyse de Localisation")
    print("=" * 70)
    
    # --- Étape 1 : Charger le modèle ---
    from model_builder import charger_modele
    import os
    
    # Utiliser le chemin centralisé défini dans config.py
    MODELE_PATH = MODEL_PATH
    
    model = None

    if os.path.exists(MODELE_PATH):
        print(f"\n[1] Chargement du modèle complet : {MODELE_PATH}")
        try:
            model = charger_modele(MODELE_PATH)
            print("    ✓ Modèle chargé avec succès")
        except Exception as e:
            print(f"    ✗ Erreur lors du chargement du modèle complet : {e}")
            model = None

    if model is None:
        # Fallback : si aucun modèle .h5 complet, essayer de charger les derniers poids
        print(f"\n[1] Modèle complet introuvable, recherche de poids dans : {WEIGHTS_DIR}")
        if os.path.exists(WEIGHTS_DIR):
            poids_files = [os.path.join(WEIGHTS_DIR, f) for f in os.listdir(WEIGHTS_DIR) if f.endswith('.weights.h5')]
            poids_files.sort(key=lambda p: os.path.getmtime(p), reverse=True)
            if poids_files:
                dernier_poids = poids_files[0]
                print(f"    → Poids trouvés : {os.path.basename(dernier_poids)}")
                try:
                    # Construire le modèle et charger les poids
                    from model_builder import construire_cnn, compiler_modele, charger_poids
                    model = construire_cnn()
                    model = compiler_modele(model)
                    # charger_poids attend le nom sans suffixe '.weights.h5'
                    nom_poids = os.path.basename(dernier_poids).replace('.weights.h5', '')
                    charger_poids(model, nom=nom_poids)
                    print("    ✓ Modèle construit et poids chargés avec succès")
                except Exception as e:
                    print(f"    ✗ Erreur lors du chargement des poids : {e}")
                    model = None
            else:
                print("    ✗ Aucun fichier de poids trouvé dans WEIGHTS_DIR")
        else:
            print("    ✗ Répertoire WEIGHTS_DIR introuvable")

    if model is None:
        print(f"\n[ERREUR] Aucun modèle utilisable trouvé (ni MODEL_PATH ni poids).")
        print("Veuillez entraîner le modèle (générer cnn_model.h5) ou déposer des poids dans model/weights/")
        exit(1)
    
    # --- Étape 2 : Choisir une image de test ---
    from config import DATASET_DIR, TRAIN_DIR, VAL_DIR, TEST_DIR
    
    # Rechercher des images CT dans le dataset
    image_test = None
    # première tentative : dossier TRAIN_DIR / 'malignant'
    image_dir = os.path.join(TRAIN_DIR, "malignant")

    if os.path.exists(image_dir):
        images = [f for f in os.listdir(image_dir) if f.lower().endswith(('.png', '.jpg', '.jpeg'))]
        if images:
            image_test = os.path.join(image_dir, images[0])
            print(f"\n[2] Image de test sélectionnée : {os.path.basename(image_test)}")
    
    if not image_test:
        # Fallback : chercher dans TRAIN/VAL/TEST et les classes communes
        for base in (TRAIN_DIR, VAL_DIR, TEST_DIR, DATASET_DIR):
            if not base or not os.path.exists(base):
                continue
            for split in ('train', 'validation', 'val', 'test'):
                for cls in ('malignant', 'malignant case', 'malignant_case', 'benign', 'benign case', 'benign_case'):
                    path = os.path.join(base, split, cls)
                    if not os.path.exists(path):
                        # certain dataset structures use lowercase class names without spaces
                        path = os.path.join(base, split, cls.split()[0])
                    if os.path.exists(path):
                        images = [f for f in os.listdir(path) if f.lower().endswith(('.png', '.jpg', '.jpeg'))]
                        if images:
                            image_test = os.path.join(path, images[0])
                            print(f"\n[2] Image de test sélectionnée : {os.path.basename(image_test)}")
                            break
                if image_test:
                    break
            if image_test:
                break
    
    if not image_test:
        print("\n[ERREUR] Aucune image CT trouvée dans DATA_DIR")
        print("Veuillez placer des images CT dans le dossier data/")
        exit(1)
    
    # --- Étape 3 : Exécuter la prédiction et Grad-CAM ---
    print("\n[3] Analyse de l'image par le modèle...")
    
    # Importer les fonctions de prédiction (noms définis dans predict.py)
    from predict import (
        diagnostiquer,
        predire_depuis_chemin
    )

    # Prédiction et diagnostic complet
    # predire_depuis_chemin retourne le résultat Niveau 1, mais nous utilisons
    # directement la fonction d'orchestration `diagnostiquer` qui effectue
    # Niveau 1 + Niveau 2 et retourne le rapport complet.
    rapport = diagnostiquer(image_test, model)
    
    # Afficher le résumé
    print("\n" + "=" * 70)
    print("  RÉSULTATS DU DIAGNOSTIC")
    print("=" * 70)
    print(f"Image            : {rapport['meta']['image']}")
    print(f"Classe prédite   : {rapport['niveau1_cnn']['classe_predite'].upper()}")
    print(f"Confiance        : {rapport['niveau1_cnn']['confiance']}%")
    print(f"Niveau de risque : {rapport['niveau2_diagnostic']['niveau_risque'].upper()}")
    print(f"Stade TNM        : {rapport['niveau2_diagnostic']['stade_tnm']['label']}")
    print("=" * 70)
    
    # --- Étape 4 : Générer Grad-CAM ---
    print("\n[4] Génération de la visualisation Grad-CAM...")
    
    # Déterminer la classe cible (maligne ou bénigne)
    # déterminer l'index cible à partir du label retourné (tolérance aux variantes)
    classe_label = rapport['niveau1_cnn']['classe_predite'].lower()
    classe_idx = IDX_MALIGNANT if 'malignant' in classe_label or 'maligne' in classe_label else 0
    
    # Calculer Grad-CAM
    gradcam_result = gradcam_complet(
        model=model,
        chemin_image=image_test,
        classe_idx=classe_idx,
        alpha=0.5
    )
    
    print("    ✓ Grad-CAM calculé")
    print(f"    ✓ Localisation : {gradcam_result['localisation']['localisation']}")
    print(f"    ✓ Confiance localisation : {gradcam_result['localisation']['confiance_loc']*100:.0f}%")
    
    # --- Étape 5 : Visualiser et exporter ---
    print("\n[5] Génération de la figure...")
    
    # Créer le dossier d'export
    os.makedirs(EXPORTS_DIR, exist_ok=True)
    
    # Visualisation complète
    chemin_fig = visualiser_gradcam(
        gradcam_result=gradcam_result,
        rapport_diagnostic=rapport,
        sauvegarder=True
    )
    
    # Exporter la superposition seule (pour l'interface)
    chemin_superposition = os.path.join(EXPORTS_DIR, 'superposition_latest.png')
    exporter_superposition(gradcam_result, chemin_superposition)
    
    print("\n" + "=" * 70)
    print("  ANALYSE GRAD-CAM TERMINÉE")
    print("=" * 70)
    print(f"Figure complète : {chemin_fig}")
    print(f"Superposition   : {chemin_superposition}")
    print("\nLes visualisations sont prêtes à être intégrées dans l'interface.")