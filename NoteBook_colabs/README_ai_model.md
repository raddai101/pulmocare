# 🫁 Module IA — Détection du Cancer du Poumon

> CNN modulaire entraînable sur Google Colab — Architecture paramétrable, Grad-CAM intégré, API Flask pour PHP.

---

## Structure du module

```
ai_model/
│
├── scripts/
│   ├── config.py            ← Tout se configure ici (architecture, classes, hyperparamètres)
│   ├── preprocess.py        ← Pipeline de prétraitement des images CT Scan
│   ├── prepare_dataset.py   ← Fusion Roboflow + IQ-OTH/NCCD, split, class_weight
│   ├── model_builder.py     ← Construction dynamique du CNN (architecture paramétrable)
│   ├── train_model.py       ← Entraînement + callbacks + transfer learning
│   ├── predict.py           ← Prédiction 2 niveaux (CNN brut + diagnostic enrichi)
│   ├── gradcam.py           ← Visualisation Grad-CAM + localisation tumorale
│   ├── evaluate.py          ← Métriques médicales (sensibilité, AUC, confusion)
│   ├── api_server.py        ← Serveur Flask pour le backend PHP
│   └── main.py              ← Point d'entrée CLI
│
├── model/
│   ├── cnn_model.h5         ← Modèle entraîné (généré après entraînement)
│   ├── labels.txt           ← Classes + taxonomie médicale
│   └── weights/             ← Checkpoints pendant l'entraînement
│
├── dataset/
│   ├── train/               ← 70% des données (normal/ begnin case/ malignant case/)
│   ├── validation/          ← 20% des données
│   └── test/                ← 10% des données
│
├── logs/                    ← Historiques d'entraînement JSON + CSV TensorBoard
├── exports/                 ← Figures (courbes, confusion, Grad-CAM)
├── CNN_Cancer_Poumon_Colab.ipynb   ← Notebook Google Colab prêt à l'emploi
└── requirements.txt
```

---

## Ordre d'exécution

### Étape 1 — Préparer le dataset (une seule fois)

Vous avez besoin de deux datasets :

| Dataset | Source | Classes |
|---|---|---|
| **Roboflow** (existant) | `dataset/train/_annotations.csv` | NSCLC, SCLC |
| **IQ-OTH/NCCD** | [Kaggle](https://www.kaggle.com/datasets/hamdallak/the-iqothnccd-lung-cancer-dataset) | Normal, Benign, Malignant |

```bash
python scripts/prepare_dataset.py \
    --dataset_dir   dataset \
    --csv_roboflow  dataset/train/_annotations.csv \
    --dossier_iqoth /chemin/vers/IQ-OTH_NCCD \
    --scripts_dir   scripts
```

Ce script produit automatiquement :
- `dataset/validation/` rempli (20% stratifié)
- `CLASS_WEIGHTS` dans `config.py` pour compenser le déséquilibre
- `_annotations.csv` dans chaque split

### Étape 2 — Entraîner sur Google Colab (recommandé)

Ouvrez `CNN_Cancer_Poumon_Colab.ipynb` sur [colab.research.google.com](https://colab.research.google.com).

```
Exécution → Modifier le type d'exécution → GPU T4
```

Exécutez les cellules dans l'ordre. Le modèle entraîné est sauvegardé automatiquement sur Google Drive.

### Étape 2 (alternative) — Entraîner en local

```bash
pip install -r requirements.txt
python scripts/main.py --mode train
```

### Étape 3 — Évaluer le modèle

```bash
python scripts/main.py --mode evaluate
```

### Étape 4 — Prédire sur une image

```bash
# Prédiction simple
python scripts/main.py --mode predict --image scan.jpg

# Avec Grad-CAM (localisation de la tumeur)
python scripts/main.py --mode predict --image scan.jpg --gradcam --localisation central
```

### Étape 5 — Démarrer l'API pour PHP

```bash
python scripts/main.py --mode serve --port 5000
```

Le backend PHP envoie l'image en `POST /predict` et reçoit le rapport JSON complet.

---

## Architecture CNN modulaire

L'architecture est **entièrement paramétrable** depuis `config.py`, comme le notebook ANN :

```python
# Ajoutez/supprimez des tuples pour ajouter/retirer des couches
CONV_BLOCKS = (
    (32,  3, 1),   # (nb_filtres, taille_kernel, stride)
    (64,  3, 1),
    (128, 3, 1),
    (256, 3, 1),
)
DENSE_LAYERS = (
    (512, 0.5),    # (nb_neurones, dropout)
    (256, 0.3),
)
```

Ou depuis la ligne de commande :
```bash
python scripts/main.py --mode train \
    --conv_blocks  "32,3,1;64,3,1;128,3,1" \
    --dense_layers "512,0.5;256,0.3" \
    --lr 0.001 --epochs 50
```

---

## Classes détectées

| Index | Classe CNN | Description médicale |
|---|---|---|
| 0 | `normal` | Poumon sain, aucune anomalie |
| 1 | `begnin case` | Nodule ou masse bénigne (non cancéreuse) |
| 2 | `malignant case` | Masse maligne suspecte — NSCLC ou SCLC |

### Enrichissement diagnostique (Niveau 2)

Pour chaque prédiction `malignant case`, le système retourne en plus :
- **Type histologique probable** : Adénocarcinome, Épidermoïde, Grandes cellules, CPC
- **Stade TNM estimé** : Stade 0 → IV
- **Localisation** : centrale (bronches) ou périphérique
- **Niveau de risque** : faible / modéré / élevé / critique
- **Recommandations cliniques** : liste d'actions médicales adaptées

---

## API Flask — Endpoints

| Méthode | Endpoint | Description |
|---|---|---|
| `GET` | `/health` | État du serveur + modèle |
| `GET` | `/classes` | Liste des classes |
| `GET` | `/taxonomy` | Taxonomie médicale complète |
| `POST` | `/predict` | Diagnostic complet (JSON) |
| `POST` | `/predict/gradcam` | Diagnostic + heatmap Base64 |
| `POST` | `/predict/batch` | Analyse de plusieurs images |

**Exemple d'appel depuis PHP :**
```php
$ch = curl_init('http://localhost:5000/predict');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, [
    'image'        => new CURLFile($chemin_image),
    'localisation' => 'variable'
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$reponse = json_decode(curl_exec($ch), true);
```

---

## Gestion du déséquilibre des classes

Le dataset fusionné est très déséquilibré (93% malignant, 5% normal, 2% bénin).
Deux techniques sont appliquées automatiquement :

**1. class_weight** — calculé par `prepare_dataset.py`, injecté dans `model.fit()` :
```
normal       → weight =  6.34
begnin case  → weight = 21.99
malignant    → weight =  0.36
```

**2. Augmentation ciblée** — copies transformées des classes minoritaires dans `train/` uniquement :
```bash
python -c "
from prepare_dataset import augmenter_classes_minoritaires
from config import CLASS_WEIGHTS, CLASS_NAMES
augmenter_classes_minoritaires('dataset', CLASS_WEIGHTS, CLASS_NAMES)
"
```

---

## Avertissement médical

> Ce système est un **outil d'aide au diagnostic** uniquement.
> Il ne remplace pas l'avis d'un médecin spécialiste.
> Tout résultat doit être confirmé par un professionnel de santé.
