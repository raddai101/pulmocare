"""
=============================================================================
prepare_dataset.py — Préparation et fusion des datasets CT Scan
=============================================================================

PROBLÈME DÉTECTÉ :
  Le dataset Roboflow (NSCLC + SCLC) ne contient QUE des cas cancéreux.
  Un CNN entraîné sans cas normaux/bénins ne peut pas généraliser.

SOLUTION IMPLÉMENTÉE : Fusion de deux datasets complémentaires
  ┌─────────────────────────────────────────────────────────────┐
  │  Dataset A — Roboflow (existant)                            │
  │    NSCLC : 5 174 images  →  malignant case                 │
  │    SCLC  : 1 647 images  →  malignant case                 │
  │                                                             │
  │  Dataset B — IQ-OTH/NCCD (à télécharger sur Kaggle)        │
  │    normal       : 416 images                                │
  │    begnin case  : 120 images                                │
  │    malignant    : 561 images  →  malignant case             │
  │                                                             │
  │  Dataset combiné final                                      │
  │    normal        :   416 images   ( 5%)                     │
  │    begnin case   :   120 images   ( 2%)                     │
  │    malignant case: 7 943 images  (93%)                      │
  │                                                             │
  │  ⚠️  Déséquilibre géré automatiquement via class_weight     │
  └─────────────────────────────────────────────────────────────┘

TÉLÉCHARGEMENT IQ-OTH/NCCD :
  https://www.kaggle.com/datasets/hamdallak/the-iqothnccd-lung-cancer-dataset

Ce script effectue :
  ✓ Lecture + validation des deux datasets
  ✓ Mapping des classes vers normal / begnin case / malignant case
  ✓ Fusion et déduplication
  ✓ Split stratifié 70 / 20 / 10
  ✓ Copie des images dans la bonne arborescence
  ✓ Génération des _annotations.csv par split
  ✓ Calcul des class_weight pour compenser le déséquilibre
  ✓ Mise à jour automatique de config.py
=============================================================================
"""

import os
import sys
import json
import shutil
import argparse
import numpy as np
import pandas as pd
from pathlib import Path
from collections import Counter
from sklearn.model_selection import train_test_split
from sklearn.utils.class_weight import compute_class_weight


# Chemins par défaut basés sur la structure du dépôt
DEFAULT_DATASET_DIR = Path(__file__).resolve().parent.parent / 'dataset'
DEFAULT_CSV_ROBOFLOW = DEFAULT_DATASET_DIR / 'Roboflow' / 'train' / '_annotations.csv'
DEFAULT_DOSSIER_IQOTH = DEFAULT_DATASET_DIR / 'The IQ-OTHNCCD lung cancer dataset'
DEFAULT_SCRIPTS_DIR = Path(__file__).resolve().parent


# =============================================================================
# MAPPING DES CLASSES
# =============================================================================

MAPPING_CLASSES = {
    # Dataset Roboflow
    'NSCLC':          'malignant case',
    'SCLC':           'malignant case',
    # Dataset IQ-OTH/NCCD (Kaggle)
    'normal':         'normal',
    'Normal':         'normal',
    'NORMAL':         'normal',
    'begnin case':    'begnin case',
    'begnin':         'begnin case',
    'benign':         'begnin case',
    'Benign':         'begnin case',
    'BENIGN':         'begnin case',
    # Cas malins génériques
    'malignant case': 'malignant case',
    'malignant':      'malignant case',
    'Malignant':      'malignant case',
    'MALIGNANT':      'malignant case',
    'cancerous':      'malignant case',
    'Cancerous':      'malignant case',
}

CLASSES_CNN  = ['normal', 'begnin case', 'malignant case']
RATIO_TRAIN  = 0.70
RATIO_VAL    = 0.20
RATIO_TEST   = 0.10


# =============================================================================
# 1. Lecture des sources de données
# =============================================================================

def lire_csv(chemin: str, label_source: str) -> pd.DataFrame:
    """
    Lit un _annotations.csv et lui ajoute une colonne source.

    Paramètres
    ----------
    chemin       : str   Chemin vers le CSV
    label_source : str   Identifiant de la source ('roboflow', 'iqoth', etc.)

    Retourne
    --------
    pd.DataFrame
    """
    if not os.path.exists(chemin):
        raise FileNotFoundError(f"CSV introuvable : {chemin}")

    df = pd.read_csv(chemin, sep=",")

    for col in ('filename', 'class'):
        if col not in df.columns:
            raise ValueError(f"Colonne '{col}' manquante dans {chemin}")

    df = df.dropna(subset=['filename', 'class'])
    df['filename'] = df['filename'].str.strip()
    df['class']    = df['class'].str.strip()
    df['source']   = label_source
    return df


def lire_dossier_iqoth(dossier_iqoth: str) -> pd.DataFrame:
    """
    Lit le dataset IQ-OTH/NCCD depuis son arborescence de dossiers.
    Le dataset Kaggle est organisé en sous-dossiers par classe :
      IQ-OTH_NCCD/
        Normal/       ← images CT normales
        Benign/       ← nodules bénins
        Malignant/    ← masses malignes

    Paramètres
    ----------
    dossier_iqoth : str   Dossier racine du dataset IQ-OTH/NCCD

    Retourne
    --------
    pd.DataFrame avec colonnes : filename, class, source, chemin_absolu
    """
    extensions = {'.jpg', '.jpeg', '.png', '.bmp', '.tiff', '.dcm'}
    lignes = []

    sous_dossiers_classes = {
        'Normal':       'normal',
        'normal':       'normal',
        'NORMAL':       'normal',
        'Normal cases': 'normal',
        'normal cases': 'normal',
        'NORMAL CASES': 'normal',
        'Benign':       'begnin case',
        'benign':       'begnin case',
        'BENIGN':       'begnin case',
        'Benign cases': 'begnin case',
        'benign cases': 'begnin case',
        'Bengin cases': 'begnin case',
        'bengin cases': 'begnin case',
        'MALIGNANT':    'malignant case',
        'Malignant':    'malignant case',
        'malignant':    'malignant case',
        'Malignant cases':'malignant case',
        'malignant cases':'malignant case',
    }

    for sous_dossier, classe_cnn in sous_dossiers_classes.items():
        chemin_dossier = os.path.join(dossier_iqoth, sous_dossier)
        if not os.path.isdir(chemin_dossier):
            continue

        for nom_fichier in os.listdir(chemin_dossier):
            ext = os.path.splitext(nom_fichier)[1].lower()
            if ext not in extensions:
                continue

            lignes.append({
                'filename':       nom_fichier,
                'class':          sous_dossier,
                'class_cnn':      classe_cnn,
                'source':         'iqoth',
                'chemin_absolu':  os.path.join(chemin_dossier, nom_fichier),
                'width':          224,
                'height':         224,
            })

    if not lignes:
        raise ValueError(
            f"Aucune image trouvée dans {dossier_iqoth}\n"
            f"Structure attendue : {dossier_iqoth}/Normal/ , /Benign/ , /Malignant/"
        )

    df = pd.DataFrame(lignes)
    print(f"  IQ-OTH/NCCD : {len(df)} images chargées depuis {dossier_iqoth}")
    return df


# =============================================================================
# 2. Mapping et fusion
# =============================================================================

def appliquer_mapping(df: pd.DataFrame) -> pd.DataFrame:
    """
    Mappe la colonne 'class' vers 'class_cnn' via MAPPING_CLASSES.
    Les classes non reconnues sont supprimées avec avertissement.
    """
    if 'class_cnn' not in df.columns:
        df = df.copy()
        df['class_cnn'] = df['class'].map(MAPPING_CLASSES)

    non_mappes = df[df['class_cnn'].isna()]['class'].unique()
    if len(non_mappes):
        print(f"  ⚠️  Classes non reconnues (ignorées) : {list(non_mappes)}")
        df = df.dropna(subset=['class_cnn'])

    return df


def fusionner_datasets(datasets: list) -> pd.DataFrame:
    """
    Fusionne plusieurs DataFrames et supprime les doublons de filename.

    Paramètres
    ----------
    datasets : list   Liste de DataFrames annotés

    Retourne
    --------
    pd.DataFrame fusionné
    """
    df_total = pd.concat(datasets, ignore_index=True)

    avant = len(df_total)
    df_total = df_total.drop_duplicates(subset='filename', keep='first')
    apres = len(df_total)

    if avant != apres:
        print(f"  {avant - apres} doublons supprimés")

    return df_total


# =============================================================================
# 3. Calcul des class_weight (gestion du déséquilibre)
# =============================================================================

def calculer_class_weights(df: pd.DataFrame, classes: list) -> dict:
    """
    Calcule les poids de classe pour compenser le déséquilibre.
    Les classes minoritaires (normal, begnin) reçoivent un poids plus élevé.
    Les poids sont sauvegardés dans config.py pour l'entraînement.

    Méthode : sklearn 'balanced'
    w_i = N_total / (N_classes × N_i)

    Paramètres
    ----------
    df      : pd.DataFrame   avec colonne 'class_cnn'
    classes : list

    Retourne
    --------
    dict : {index_classe: poids}
    """
    y         = df['class_cnn'].values
    y_indices = [classes.index(c) for c in y]

    poids = compute_class_weight(
        class_weight='balanced',
        classes=np.arange(len(classes)),
        y=y_indices
    )

    class_weight_dict = {i: round(float(p), 4) for i, p in enumerate(poids)}

    print(f"\n  Poids de classe calculés (pour compenser le déséquilibre) :")
    for i, cls in enumerate(classes):
        n   = (df['class_cnn'] == cls).sum()
        pct = n / len(df) * 100
        print(f"    [{i}] {cls:<22} {n:>5} images ({pct:5.1f}%)  →  weight = {poids[i]:.4f}")

    return class_weight_dict


# =============================================================================
# 4. Split stratifié
# =============================================================================

def split_stratifie(df: pd.DataFrame, graine: int = 42) -> tuple:
    """
    Découpe le DataFrame en 3 splits stratifiés par 'class_cnn'.

    Paramètres
    ----------
    df     : pd.DataFrame
    graine : int

    Retourne
    --------
    tuple : (df_train, df_val, df_test)
    """
    df_reste, df_test = train_test_split(
        df, test_size=RATIO_TEST,
        stratify=df['class_cnn'], random_state=graine
    )
    ratio_val_adj = RATIO_VAL / (1.0 - RATIO_TEST)
    df_train, df_val = train_test_split(
        df_reste, test_size=ratio_val_adj,
        stratify=df_reste['class_cnn'], random_state=graine
    )
    return (df_train.reset_index(drop=True),
            df_val.reset_index(drop=True),
            df_test.reset_index(drop=True))


# =============================================================================
# 5. Copie des images
# =============================================================================

def copier_split(
    df: pd.DataFrame,
    dossiers_sources: list,
    dossier_dest: str,
    nom: str
) -> pd.DataFrame:
    """
    Copie les images d'un split dans dest/<class_cnn>/<image>.
    Cherche chaque image dans plusieurs dossiers sources.
    Utilise chemin_absolu si disponible (IQ-OTH/NCCD).

    Paramètres
    ----------
    df               : pd.DataFrame
    dossiers_sources : list   Dossiers où chercher les images
    dossier_dest     : str    Dossier de destination du split
    nom              : str    Nom du split pour affichage

    Retourne
    --------
    pd.DataFrame des lignes dont l'image a été copiée
    """
    copiees      = 0
    introuvables = 0
    valides      = []

    for _, ligne in df.iterrows():
        fichier = ligne['filename']
        classe  = ligne['class_cnn']

        # Priorité : chemin absolu connu (IQ-OTH/NCCD chargé depuis dossier)
        source = None
        if 'chemin_absolu' in ligne and pd.notna(ligne.get('chemin_absolu')):
            if os.path.exists(str(ligne['chemin_absolu'])):
                source = str(ligne['chemin_absolu'])

        # Fallback : recherche dans les dossiers sources
        if source is None:
            for src in dossiers_sources:
                candidat = os.path.join(src, fichier)
                if os.path.exists(candidat):
                    source = candidat
                    break
                # Chercher aussi dans les sous-dossiers de classe
                for cls_dir in os.listdir(src) if os.path.isdir(src) else []:
                    candidat2 = os.path.join(src, cls_dir, fichier)
                    if os.path.exists(candidat2):
                        source = candidat2
                        break
                if source:
                    break

        if source is None:
            introuvables += 1
            continue

        dest = os.path.join(dossier_dest, classe, fichier)
        os.makedirs(os.path.dirname(dest), exist_ok=True)
        shutil.copy2(source, dest)
        copiees += 1
        valides.append(ligne)

    print(f"  [{nom:<12}] {copiees:>5} copiées", end='')
    if introuvables:
        print(f"  ⚠️  {introuvables} introuvables (images manquantes sur le disque)", end='')
    print()
    return pd.DataFrame(valides)


# =============================================================================
# 6. Sauvegarde CSV + mise à jour config
# =============================================================================

def sauvegarder_csv(df: pd.DataFrame, dossier: str, nom: str):
    """Exporte _annotations.csv pour un split."""
    os.makedirs(dossier, exist_ok=True)
    df_out = df.copy()
    df_out['class'] = df_out['class_cnn']
    cols = [c for c in ('filename','width','height','class','xmin','ymin','xmax','ymax')
            if c in df_out.columns]
    chemin = os.path.join(dossier, '_annotations.csv')
    df_out[cols].to_csv(chemin, index=False)
    print(f"  [{nom:<12}] {chemin}  ({len(df_out)} lignes)")


def mettre_a_jour_config(classes: list, class_weights: dict, scripts_dir: str):
    """
    Met à jour config.py avec CLASS_NAMES, NUM_CLASSES et CLASS_WEIGHTS.
    CLASS_WEIGHTS est ensuite utilisé dans train_model.py.
    """
    chemin = os.path.join(scripts_dir, 'config.py')
    if not os.path.exists(chemin):
        print(f"  ⚠️  config.py introuvable à {chemin}")
        return

    import re
    with open(chemin, 'r', encoding='utf-8') as f:
        contenu = f.read()

    # CLASS_NAMES
    contenu = re.sub(r"CLASS_NAMES\s*=\s*\[.*?\]",
                     f"CLASS_NAMES  = {classes}", contenu)
    # NUM_CLASSES
    contenu = re.sub(r"NUM_CLASSES\s*=\s*\d+",
                     f"NUM_CLASSES  = {len(classes)}", contenu)

    # CLASS_WEIGHTS — ajout ou remplacement
    poids_str = f"CLASS_WEIGHTS = {class_weights}  # calculés automatiquement"
    if 'CLASS_WEIGHTS' in contenu:
        contenu = re.sub(r"CLASS_WEIGHTS\s*=\s*\{.*?\}", poids_str, contenu)
    else:
        # Insérer après NUM_CLASSES
        contenu = re.sub(r"(NUM_CLASSES\s*=\s*\d+)",
                         r"\1\n" + poids_str, contenu)

    with open(chemin, 'w', encoding='utf-8') as f:
        f.write(contenu)

    print(f"  config.py mis à jour  →  CLASS_NAMES={classes}")
    print(f"                         →  CLASS_WEIGHTS={class_weights}")


# =============================================================================
# 7. Rapport final
# =============================================================================

def rapport(df_train, df_val, df_test, classes, class_weights):
    """Affiche le tableau de distribution et les poids."""
    total = len(df_train) + len(df_val) + len(df_test)

    print(f"\n{'═'*70}")
    print("  DISTRIBUTION FINALE DU DATASET")
    print(f"{'═'*70}")
    print(f"  {'Classe':<24} {'Train':>7} {'Valid.':>7} {'Test':>7} {'Total':>7}  {'Weight':>8}")
    print(f"{'─'*70}")

    for i, cls in enumerate(classes):
        t  = (df_train['class_cnn'] == cls).sum()
        v  = (df_val['class_cnn']   == cls).sum()
        ts = (df_test['class_cnn']  == cls).sum()
        w  = class_weights.get(i, 1.0)
        print(f"  {cls:<24} {t:>7} {v:>7} {ts:>7} {t+v+ts:>7}  {w:>8.4f}")

    print(f"{'─'*70}")
    print(f"  {'TOTAL':<24} {len(df_train):>7} {len(df_val):>7} {len(df_test):>7} {total:>7}")
    pct = lambda n: f"{n/total*100:.1f}%"
    print(f"  {'%':<24} {pct(len(df_train)):>7} {pct(len(df_val)):>7} {pct(len(df_test)):>7}")

    print(f"\n  💡 Les CLASS_WEIGHTS compensent le déséquilibre :")
    print(f"     Les classes minoritaires (normal, begnin) ont un poids élevé")
    print(f"     → le modèle leur accordera plus d'importance lors de l'entraînement")
    print(f"{'═'*70}\n")


def verifier(dataset_dir, classes):
    """Vérifie le nombre d'images dans chaque dossier."""
    ext = {'.jpg','.jpeg','.png','.bmp','.tiff','.dcm'}
    print("  Contenu des dossiers :")
    for split in ('train','validation','test'):
        details = []
        total   = 0
        for cls in classes:
            d = os.path.join(dataset_dir, split, cls)
            n = sum(1 for f in os.listdir(d)
                    if os.path.isdir(d) and os.path.splitext(f)[1].lower() in ext) \
                if os.path.isdir(d) else 0
            total += n
            details.append(f"{cls.split()[0]}: {n}")
        print(f"    {split:<14} {total:>5} images   [{',  '.join(details)}]")


# =============================================================================
# 8. Pipeline principal
# =============================================================================

def preparer(
    dataset_dir:    str,
    csv_roboflow:   str,
    dossier_iqoth:  str  = None,
    csv_iqoth:      str  = None,
    graine:         int  = 42,
    copier:         bool = True,
    scripts_dir:    str  = None
):
    """
    Pipeline complet de fusion et préparation du dataset.

    Paramètres
    ----------
    dataset_dir   : str   Dossier racine (contient train/ test/ validation/)
    csv_roboflow  : str   _annotations.csv du dataset Roboflow (train)
    dossier_iqoth : str   Dossier racine IQ-OTH/NCCD (avec Normal/ Benign/ Malignant/)
    csv_iqoth     : str   Ou directement un CSV IQ-OTH/NCCD si disponible
    graine        : int   Graine aléatoire
    copier        : bool  False = simulation sans copie
    scripts_dir   : str   Dossier scripts/ pour mettre à jour config.py
    """
    print("\n" + "═"*70)
    print("  PRÉPARATION & FUSION DU DATASET — CT Scan Cancer du Poumon")
    print("="*70)

    # ── Étape 1 : Chargement des sources ─────────────────────────────────────
    print("\n[1/7] Chargement des datasets...")

    datasets = []

    # Source A : Roboflow
    df_robo = lire_csv(csv_roboflow, 'roboflow')
    df_robo = appliquer_mapping(df_robo)
    print(f"  Roboflow : {len(df_robo)} images  "
          f"→  {dict(df_robo['class_cnn'].value_counts().to_dict())}")
    datasets.append(df_robo)

    # Source B : IQ-OTH/NCCD
    df_iqoth = None
    if dossier_iqoth and os.path.isdir(dossier_iqoth):
        df_iqoth = lire_dossier_iqoth(dossier_iqoth)
        print(f"  IQ-OTH   : {len(df_iqoth)} images  "
              f"→  {dict(df_iqoth['class_cnn'].value_counts().to_dict())}")
        datasets.append(df_iqoth)
    elif csv_iqoth and os.path.exists(csv_iqoth):
        df_iqoth = lire_csv(csv_iqoth, 'iqoth')
        df_iqoth = appliquer_mapping(df_iqoth)
        print(f"  IQ-OTH   : {len(df_iqoth)} images  "
              f"→  {dict(df_iqoth['class_cnn'].value_counts().to_dict())}")
        datasets.append(df_iqoth)
    else:
        print(f"\n  ⚠️  Dataset IQ-OTH/NCCD non fourni.")
        print(f"      → Le modèle n'aura pas de cas normaux ni bénins.")
        print(f"      → Téléchargez-le sur :")
        print(f"        https://www.kaggle.com/datasets/hamdallak/the-iqothnccd-lung-cancer-dataset")
        print(f"      → Relancez avec --dossier_iqoth /chemin/vers/IQ-OTH_NCCD/\n")

    # ── Étape 2 : Fusion ──────────────────────────────────────────────────────
    print("\n[2/7] Fusion des datasets...")
    df_total = fusionner_datasets(datasets)
    print(f"  Total après fusion : {len(df_total)} images")
    print(f"  Distribution : {dict(df_total['class_cnn'].value_counts().to_dict())}")

    # ── Étape 3 : class_weight ────────────────────────────────────────────────
    print("\n[3/7] Calcul des poids de classe...")
    class_weights = calculer_class_weights(df_total, CLASSES_CNN)

    # ── Étape 4 : Split ───────────────────────────────────────────────────────
    print("\n[4/7] Découpage stratifié 70 / 20 / 10...")
    df_train, df_val, df_test = split_stratifie(df_total, graine=graine)
    print(f"  Train      : {len(df_train):>5}  "
          f"Validation : {len(df_val):>5}  "
          f"Test       : {len(df_test):>5}")

    if not copier:
        rapport(df_train, df_val, df_test, CLASSES_CNN, class_weights)
        print("  [Simulation — aucun fichier copié]\n")
        return df_train, df_val, df_test, class_weights

    # ── Étape 5 : Arborescence ────────────────────────────────────────────────
    print("\n[5/7] Création de l'arborescence...")
    for split in ('train','validation','test'):
        for cls in CLASSES_CNN:
            os.makedirs(os.path.join(dataset_dir, split, cls), exist_ok=True)
    print(f"  {3 * len(CLASSES_CNN)} dossiers prêts")

    # ── Étape 6 : Copie ───────────────────────────────────────────────────────
    print("\n[6/7] Copie des images...")
    src_train = [os.path.join(dataset_dir, 'train')]
    src_test  = [os.path.join(dataset_dir, 'test')]
    src_tous  = src_train + src_test

    df_train = copier_split(df_train, src_tous, os.path.join(dataset_dir,'train'),      'train')
    df_val   = copier_split(df_val,   src_tous, os.path.join(dataset_dir,'validation'), 'validation')
    df_test  = copier_split(df_test,  src_tous, os.path.join(dataset_dir,'test'),       'test')

    # ── Étape 7 : CSV + config ───────────────────────────────────────────────
    print("\n[7/7] Sauvegarde des _annotations.csv...")
    sauvegarder_csv(df_train, os.path.join(dataset_dir,'train'),      'train')
    sauvegarder_csv(df_val,   os.path.join(dataset_dir,'validation'), 'validation')
    sauvegarder_csv(df_test,  os.path.join(dataset_dir,'test'),       'test')

    if scripts_dir:
        print("\n  Mise à jour de config.py...")
        mettre_a_jour_config(CLASSES_CNN, class_weights, scripts_dir)

    rapport(df_train, df_val, df_test, CLASSES_CNN, class_weights)
    verifier(dataset_dir, CLASSES_CNN)

    print("✓ Dataset prêt — vous pouvez lancer l'entraînement !\n")
    return df_train, df_val, df_test, class_weights


# =============================================================================
# 9. Point d'entrée CLI
# =============================================================================

def construire_parser():
    parser = argparse.ArgumentParser(
        description='Fusionne Roboflow + IQ-OTH/NCCD et prépare le dataset',
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
ÉTAPES RECOMMANDÉES :

  1. Télécharger IQ-OTH/NCCD sur Kaggle :
     https://www.kaggle.com/datasets/hamdallak/the-iqothnccd-lung-cancer-dataset

  2. Lancer ce script (simulation d'abord) :
     python prepare_dataset.py \\
         --dataset_dir   ai_model/dataset \\
         --csv_roboflow  ai_model/dataset/Roboflow/train/_annotations.csv \\
         --dossier_iqoth "ai_model/dataset/The IQ-OTHNCCD lung cancer dataset" \
         --scripts_dir   ai_model/scripts \
         --no_copy

  3. Si la distribution est correcte, lancer avec copie :
     python prepare_dataset.py \
         --dataset_dir   ai_model/dataset \
         --csv_roboflow  ai_model/dataset/Roboflow/train/_annotations.csv \
         --dossier_iqoth "ai_model/dataset/The IQ-OTHNCCD lung cancer dataset" \
         --scripts_dir   ai_model/scripts

  4. Ou sans arguments depuis la racine du dépôt :
     python ai_model/scripts/prepare_dataset.py
        """
    )

    parser.add_argument('--dataset_dir',   default=str(DEFAULT_DATASET_DIR),
                        help='Dossier racine du dataset (train/ test/ validation/)')
    parser.add_argument('--csv_roboflow',  default=str(DEFAULT_CSV_ROBOFLOW),
                        help='_annotations.csv du dataset Roboflow (train)')
    parser.add_argument('--dossier_iqoth', default=str(DEFAULT_DOSSIER_IQOTH),
                        help='Dossier racine IQ-OTH/NCCD (Normal cases/ Bengin cases/ Malignant cases)')
    parser.add_argument('--csv_iqoth',     default=None,
                        help='Alternative : _annotations.csv du dataset IQ-OTH/NCCD')
    parser.add_argument('--scripts_dir',   default=str(DEFAULT_SCRIPTS_DIR),
                        help='Dossier scripts/ pour mettre à jour config.py')
    parser.add_argument('--graine',        type=int, default=42,
                        help='Graine aléatoire (défaut: 42)')
    parser.add_argument('--no_copy',       action='store_true',
                        help='Simulation sans copier les fichiers')
    return parser


if __name__ == '__main__':
    parser = construire_parser()
    args   = parser.parse_args()
    preparer(
        dataset_dir   = args.dataset_dir,
        csv_roboflow  = args.csv_roboflow,
        dossier_iqoth = args.dossier_iqoth,
        csv_iqoth     = args.csv_iqoth,
        graine        = args.graine,
        copier        = not args.no_copy,
        scripts_dir   = args.scripts_dir
    )


# =============================================================================
# 10. Augmentation ciblée des classes minoritaires (Technique 2)
# =============================================================================

def augmenter_classes_minoritaires(
    dataset_dir: str,
    class_weights: dict,
    classes: list,
    facteur_max: int = 8,
    graine: int = 42
):
    """
    Crée des copies augmentées pour les classes minoritaires dans train/.
    Les images sont transformées (rotation, flip, zoom, luminosité)
    jusqu'à atteindre un ratio acceptable avec la classe dominante.

    Ne touche QUE au dossier train/ — val/ et test/ restent intacts.

    Paramètres
    ----------
    dataset_dir   : str    Dossier racine du dataset
    class_weights : dict   {index: poids} — les classes avec poids élevé sont augmentées
    classes       : list   Liste des classes CNN
    facteur_max   : int    Nombre maximum de copies augmentées par image originale
    graine        : int
    """
    import cv2
    import numpy as np
    from pathlib import Path

    np.random.seed(graine)
    ext_valides = {'.jpg', '.jpeg', '.png', '.bmp'}

    dossier_train = os.path.join(dataset_dir, 'train')
    print(f"\n  Augmentation ciblée des classes minoritaires dans {dossier_train}")

    for idx, classe in enumerate(classes):
        poids = class_weights.get(idx, 1.0)

        # Seulement augmenter les classes avec poids élevé (minoritaires)
        if poids <= 1.5:
            print(f"  [{classe}]  poids={poids:.2f} → pas d'augmentation nécessaire")
            continue

        dossier_classe = os.path.join(dossier_train, classe)
        if not os.path.isdir(dossier_classe):
            print(f"  [{classe}]  dossier introuvable → ignoré")
            continue

        images_orig = [f for f in os.listdir(dossier_classe)
                       if os.path.splitext(f)[1].lower() in ext_valides
                       and not f.startswith('aug_')]

        if not images_orig:
            print(f"  [{classe}]  aucune image originale trouvée")
            continue

        # Calcul du nombre de copies à créer
        # On vise un ratio raisonnable par rapport à la classe dominante
        nb_copies = min(int(poids), facteur_max)
        print(f"  [{classe}]  {len(images_orig)} images × {nb_copies} augmentations "
              f"(poids={poids:.2f}) → +{len(images_orig)*nb_copies} images")

        crees = 0
        for nom_fichier in images_orig:
            chemin = os.path.join(dossier_classe, nom_fichier)
            image  = cv2.imread(chemin)
            if image is None:
                continue

            base, ext = os.path.splitext(nom_fichier)

            for n in range(nb_copies):
                img_aug = _appliquer_augmentation(image, n, graine)
                nom_aug = f"aug_{n}_{base}{ext}"
                cv2.imwrite(os.path.join(dossier_classe, nom_aug), img_aug)
                crees += 1

        print(f"             → {crees} images augmentées créées")


def _appliquer_augmentation(image, variante: int, graine: int):
    """
    Applique une augmentation déterministe selon la variante.
    Chaque variante est différente pour maximiser la diversité.
    """
    import cv2
    import numpy as np

    np.random.seed(graine + variante * 7)
    img = image.copy()
    h, w = img.shape[:2]

    # Variante 0 : flip horizontal
    if variante % 7 == 0:
        img = cv2.flip(img, 1)

    # Variante 1 : rotation légère
    elif variante % 7 == 1:
        angle  = np.random.uniform(-15, 15)
        M      = cv2.getRotationMatrix2D((w/2, h/2), angle, 1.0)
        img    = cv2.warpAffine(img, M, (w, h), borderMode=cv2.BORDER_REFLECT)

    # Variante 2 : zoom in
    elif variante % 7 == 2:
        facteur = np.random.uniform(0.85, 0.95)
        x1 = int(w * (1 - facteur) / 2)
        y1 = int(h * (1 - facteur) / 2)
        crop = img[y1:h-y1, x1:w-x1]
        img  = cv2.resize(crop, (w, h))

    # Variante 3 : luminosité
    elif variante % 7 == 3:
        alpha = np.random.uniform(0.75, 1.25)
        img   = np.clip(img.astype(np.float32) * alpha, 0, 255).astype(np.uint8)

    # Variante 4 : flip vertical + rotation
    elif variante % 7 == 4:
        img   = cv2.flip(img, 0)
        angle = np.random.uniform(-10, 10)
        M     = cv2.getRotationMatrix2D((w/2, h/2), angle, 1.0)
        img   = cv2.warpAffine(img, M, (w, h), borderMode=cv2.BORDER_REFLECT)

    # Variante 5 : translation légère
    elif variante % 7 == 5:
        dx = int(np.random.uniform(-0.1, 0.1) * w)
        dy = int(np.random.uniform(-0.1, 0.1) * h)
        M  = np.float32([[1, 0, dx], [0, 1, dy]])
        img = cv2.warpAffine(img, M, (w, h), borderMode=cv2.BORDER_REFLECT)

    # Variante 6 : flip + zoom + luminosité
    else:
        img   = cv2.flip(img, 1)
        alpha = np.random.uniform(0.8, 1.2)
        img   = np.clip(img.astype(np.float32) * alpha, 0, 255).astype(np.uint8)

    return img
