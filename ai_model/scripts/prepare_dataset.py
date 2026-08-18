# -*- coding: utf-8 -*-
"""
split_dataset.py
================

Script pour diviser le dataset fusionné en ensembles :
- Train : 70%
- Test : 15%
- Validation : 15%

La division respecte strictement les pourcentages demandés.
"""

from __future__ import annotations

import os
import shutil
import random
import argparse
from pathlib import Path
from collections import defaultdict
from typing import Dict, List, Tuple

# Configuration
CLASSES = ["Benign", "Malignant", "Normal"]
EXTENSIONS = {".png", ".jpg", ".jpeg", ".tif", ".tiff", ".bmp"}
SEED = 42


def collect_images_by_class(dataset_path: Path) -> Dict[str, List[Path]]:
    """Collecte toutes les images par classe."""
    images_by_class = defaultdict(list)
    
    if not dataset_path.exists():
        raise FileNotFoundError(f"Dataset path not found: {dataset_path}")
    
    for classe in CLASSES:
        class_dir = dataset_path / classe
        if class_dir.exists():
            for img_path in class_dir.iterdir():
                if img_path.is_file() and img_path.suffix.lower() in EXTENSIONS:
                    images_by_class[classe].append(img_path)
    
    return dict(images_by_class)


def split_images_stratified(
    images: List[Path],
    train_ratio: float = 0.7,
    test_ratio: float = 0.15,
    val_ratio: float = 0.15,
    seed: int = 42
) -> Tuple[List[Path], List[Path], List[Path]]:
    """
    Divise les images en train/test/val en respectant STRICTEMENT les ratios.
    """
    random.seed(seed)
    
    # Mélanger les images
    shuffled = images.copy()
    random.shuffle(shuffled)
    
    total = len(shuffled)
    
    # Calculer les indices de séparation
    train_end = int(total * train_ratio)
    test_end = train_end + int(total * test_ratio)
    
    # S'assurer que validation prend le reste
    train = shuffled[:train_end]
    test = shuffled[train_end:test_end]
    val = shuffled[test_end:]
    
    # Ajuster pour éviter les erreurs d'arrondi
    # Si validation est vide ou trop petit, rééquilibrer
    if len(val) == 0:
        # Prendre quelques images du test pour la validation
        take_from_test = min(5, len(test))
        val = test[-take_from_test:]
        test = test[:-take_from_test] if take_from_test > 0 else test
    
    # Si test est vide, ajuster
    if len(test) == 0 and len(train) > 0:
        take_from_train = min(5, len(train))
        test = train[-take_from_train:]
        train = train[:-take_from_train]
    
    return train, test, val


def copy_images_to_split(
    images: List[Path],
    dest_dir: Path,
    split_name: str,
    classes: List[str] = CLASSES
) -> None:
    """Copie les images vers le dossier de split correspondant."""
    split_dir = dest_dir / split_name
    split_dir.mkdir(parents=True, exist_ok=True)
    
    for classe in classes:
        (split_dir / classe).mkdir(parents=True, exist_ok=True)
    
    for img_path in images:
        classe = img_path.parent.name
        if classe not in classes:
            continue
        
        dest_path = split_dir / classe / img_path.name
        
        # Gérer les conflits de noms
        if dest_path.exists():
            stem = img_path.stem
            suffix = img_path.suffix
            counter = 1
            new_name = f"{stem}_{counter}{suffix}"
            while (split_dir / classe / new_name).exists():
                counter += 1
                new_name = f"{stem}_{counter}{suffix}"
            dest_path = split_dir / classe / new_name
        
        shutil.copy2(img_path, dest_path)


def generate_split_report(
    splits: Dict[str, List[Path]],
    dest_dir: Path
) -> None:
    """Génère un rapport détaillé de la répartition."""
    print("\n" + "="*60)
    print("📊 RAPPORT DE DIVISION DU DATASET")
    print("="*60)
    
    print(f"\nRépartition par classe :")
    print("-"*60)
    print(f"{'Classe':<15} {'Train':>10} {'Test':>10} {'Val':>10} {'Total':>10}")
    print("-"*60)
    
    totals = {}
    for classe in CLASSES:
        counts = {}
        total = 0
        for split_name, images in splits.items():
            count = sum(1 for img in images if img.parent.name == classe)
            counts[split_name] = count
            total += count
        totals[classe] = total
        print(f"{classe:<15} {counts.get('train', 0):>10} {counts.get('test', 0):>10} {counts.get('val', 0):>10} {total:>10}")
    
    print("-"*60)
    train_total = len(splits.get('train', []))
    test_total = len(splits.get('test', []))
    val_total = len(splits.get('val', []))
    total_total = train_total + test_total + val_total
    
    print(f"{'TOTAL':<15} {train_total:>10} {test_total:>10} {val_total:>10} {total_total:>10}")
    print("-"*60)
    
    if total_total > 0:
        print(f"\n📊 Pourcentages :")
        print(f"  Train     : {train_total/total_total*100:.1f}%  ({train_total} images)")
        print(f"  Test      : {test_total/total_total*100:.1f}%  ({test_total} images)")
        print(f"  Val       : {val_total/total_total*100:.1f}%  ({val_total} images)")
        print(f"  Total     : {total_total} images")
    
    # Sauvegarder le rapport
    report_path = dest_dir / "split_report.txt"
    with open(report_path, 'w', encoding='utf-8') as f:
        f.write("RAPPORT DE DIVISION DU DATASET\n")
        f.write("="*60 + "\n\n")
        for split_name, images in splits.items():
            f.write(f"{split_name.upper()}:\n")
            for classe in CLASSES:
                count = sum(1 for img in images if img.parent.name == classe)
                f.write(f"  {classe}: {count}\n")
            f.write(f"  Total: {len(images)}\n\n")
        
        f.write(f"Total général: {total_total} images\n")
        f.write(f"Train: {train_total/total_total*100:.1f}%\n")
        f.write(f"Test: {test_total/total_total*100:.1f}%\n")
        f.write(f"Validation: {val_total/total_total*100:.1f}%\n")
    
    print(f"\n📝 Rapport sauvegardé : {report_path}")


def verify_split_integrity(
    splits: Dict[str, List[Path]],
    original_images: Dict[str, List[Path]]
) -> bool:
    """Vérifie l'intégrité des splits."""
    train_set = set(splits.get('train', []))
    test_set = set(splits.get('test', []))
    val_set = set(splits.get('val', []))
    
    if train_set & test_set:
        print("⚠️  Des images sont à la fois dans train et test !")
        return False
    
    if train_set & val_set:
        print("⚠️  Des images sont à la fois dans train et validation !")
        return False
    
    if test_set & val_set:
        print("⚠️  Des images sont à la fois dans test et validation !")
        return False
    
    total_original = sum(len(imgs) for imgs in original_images.values())
    total_splits = len(train_set) + len(test_set) + len(val_set)
    
    if total_original != total_splits:
        print(f"⚠️  Incohérence de comptage !")
        print(f"   Original: {total_original} images")
        print(f"   Splits  : {total_splits} images")
        return False
    
    print("✅ Vérification de l'intégrité : OK")
    return True


def main():
    parser = argparse.ArgumentParser(
        description="Divise le dataset en train/test/validation (ratios strictes)"
    )
    parser.add_argument(
        "--source",
        type=Path,
        default=Path("C:/xampp/htdocs/pulmocare/ai_model/LungCT_Dataset"),
        help="Chemin du dataset source"
    )
    parser.add_argument(
        "--dest",
        type=Path,
        default=Path("C:/xampp/htdocs/pulmocare/ai_model/LungCT_Split"),
        help="Chemin de destination"
    )
    parser.add_argument(
        "--train-ratio",
        type=float,
        default=0.70,
        help="Ratio pour l'entraînement (défaut: 0.70)"
    )
    parser.add_argument(
        "--test-ratio",
        type=float,
        default=0.15,
        help="Ratio pour le test (défaut: 0.15)"
    )
    parser.add_argument(
        "--val-ratio",
        type=float,
        default=0.15,
        help="Ratio pour la validation (défaut: 0.15)"
    )
    parser.add_argument(
        "--seed",
        type=int,
        default=42,
        help="Graine aléatoire pour reproductibilité"
    )

    args = parser.parse_args()
    
    print("="*60)
    print("🔄 DIVISION STRICTEMENT ÉQUILIBRÉE")
    print("="*60)
    print(f"\nConfiguration :")
    print(f"  Source        : {args.source}")
    print(f"  Destination   : {args.dest}")
    print(f"  Train         : {args.train_ratio*100:.0f}%")
    print(f"  Test          : {args.test_ratio*100:.0f}%")
    print(f"  Validation    : {args.val_ratio*100:.0f}%")
    
    # Collecter les images
    print("\n📂 Collecte des images...")
    images_by_class = collect_images_by_class(args.source)
    
    print("\nImages trouvées par classe :")
    total_images = 0
    for classe, images in images_by_class.items():
        print(f"  {classe}: {len(images)} images")
        total_images += len(images)
    print(f"  Total: {total_images} images")
    
    if total_images == 0:
        print("\n❌ Aucune image trouvée !")
        return 1
    
    # Diviser chaque classe avec les ratios EXACTS
    splits = {
        'train': [],
        'test': [],
        'val': []
    }
    
    print("\n🔀 Division des données...")
    print(f"  Ratios demandés: Train={args.train_ratio*100:.0f}%, Test={args.test_ratio*100:.0f}%, Val={args.val_ratio*100:.0f}%")
    print()
    
    for classe, images in images_by_class.items():
        train, test, val = split_images_stratified(
            images,
            train_ratio=args.train_ratio,
            test_ratio=args.test_ratio,
            val_ratio=args.val_ratio,
            seed=args.seed
        )
        
        splits['train'].extend(train)
        splits['test'].extend(test)
        splits['val'].extend(val)
        
        print(f"  {classe}: {len(train)} train ({len(train)/len(images)*100:.1f}%), {len(test)} test ({len(test)/len(images)*100:.1f}%), {len(val)} val ({len(val)/len(images)*100:.1f}%)")
    
    # Vérifier l'intégrité
    print("\n🔍 Vérification de l'intégrité...")
    if not verify_split_integrity(splits, images_by_class):
        return 1
    
    # Supprimer l'ancien dossier si demandé
    if args.dest.exists():
        print(f"\n⚠️  Le dossier {args.dest} existe déjà.")
        response = input("  Voulez-vous le supprimer ? (o/N) : ")
        if response.lower() in ['o', 'oui', 'y', 'yes']:
            shutil.rmtree(args.dest)
            print("  ✅ Dossier supprimé")
        else:
            print("  ⏭️  Utilisation du dossier existant")
    
    # Copier les fichiers
    print("\n📁 Copie des fichiers...")
    
    for split_name, images in splits.items():
        print(f"  Copie de {split_name} ({len(images)} images)...")
        copy_images_to_split(images, args.dest, split_name)
    
    # Générer le rapport
    generate_split_report(splits, args.dest)
    
    print("\n✅ Division terminée avec succès !")
    print(f"\n📂 Structure finale :")
    print(f"  {args.dest}/")
    for split_name in ['train', 'test', 'validation']:
        print(f"  ├── {split_name}/")
        for classe in CLASSES:
            count = len(list((args.dest / split_name / classe).glob("*")))
            print(f"  │   ├── {classe}/ ({count} images)")
    
    print(f"\n💡 Pour utiliser le dataset :")
    print(f"  train_dir = '{args.dest}/train'")
    print(f"  test_dir = '{args.dest}/test'")
    print(f"  val_dir = '{args.dest}/validation'")
    
    return 0


if __name__ == "__main__":
    exit(main())