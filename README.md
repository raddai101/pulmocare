# PulmoCare IA — Plateforme de Détection du Cancer du Poumon

> Plateforme web médicale intelligente pour la détection automatique du cancer du poumon à partir d'images CT Scan, propulsée par un modèle CNN entraîné sur +8 500 images.

---

## Sommaire

1. [Vue d'ensemble](#vue-densemble)
2. [Architecture](#architecture)
3. [Installation](#installation)
4. [Configuration](#configuration)
5. [Structure du projet](#structure-du-projet)
6. [Backend PHP — API](#backend-php--api)
7. [Modèle IA Python](#modèle-ia-python)
8. [Base de données](#base-de-données)
9. [Sécurité](#sécurité)
10. [Déploiement](#déploiement)

---

## Vue d'ensemble

PulmoCare IA est une plateforme médicale sécurisée permettant aux médecins d'analyser automatiquement des images CT Scan pulmonaires grâce à un réseau de neurones convolutif (CNN).

**Fonctionnalités :**
- Authentification sécurisée (médecins uniquement)
- Upload d'images CT Scan (JPEG, PNG, DICOM, TIFF)
- Analyse IA en temps réel avec score de confiance
- Classification : Normal / Suspect / Cancéreux + stade
- Historique complet des analyses avec filtres avancés
- Annotations cliniques et export PDF
- Tableau de bord avec statistiques et graphiques

---

## Architecture

```
Médecin → Interface Web (PHP+HTML) → Backend PHP (POO)
                                          ├── MySQL (données)
                                          └── Python CNN (IA)
                                                  ↓
                                          Résultat JSON → Affichage
```

---

## Installation

### Prérequis
- PHP >= 8.2 (extensions : pdo_mysql, fileinfo, openssl, json)
- MySQL >= 8.0
- Python >= 3.10
- Composer
- Apache/Nginx

### 1. Cloner le projet
```bash
git clone https://github.com/votre-org/pulmocare-ia.git
cd pulmocare-ia
```

### 2. Dépendances PHP
```bash
composer install --optimize-autoloader
```

### 3. Dépendances Python
```bash
cd ai_model
pip install -r requirements.txt
```

### 4. Base de données
```bash
mysql -u root -p < database/hospital.sql
# Ou via migrations :
mysql -u root -p cancer_detection < database/migrations/001_create_hospitals.sql
mysql -u root -p cancer_detection < database/migrations/002_create_users.sql
mysql -u root -p cancer_detection < database/migrations/003_create_detections.sql
mysql -u root -p cancer_detection < database/migrations/004_create_activity_logs.sql
mysql -u root -p cancer_detection < database/migrations/005_seed_data.sql
```

### 5. Configuration
```bash
cp .env.example .env
# Éditer .env avec vos paramètres
```

### 6. Permissions
```bash
chmod 755 assets/uploads/scans assets/uploads/avatars storage/logs
```

---

## Configuration

Éditer le fichier `.env` :

```env
APP_NAME="PulmoCare IA"
APP_ENV=production
APP_URL=https://votre-domaine.com
APP_SECRET=votre_cle_secrete_64_caracteres

DB_HOST=localhost
DB_NAME=cancer_detection
DB_USER=pulmocare
DB_PASS=votre_mot_de_passe

AI_METHOD=exec          # exec | api
PYTHON_BIN=python3
AI_API_URL=http://localhost:5000/predict   # Si AI_METHOD=api
```

---

## Structure du projet

```
projet-detection-cancer/
├── index.php                    # Point d'entrée
├── .env                         # Variables d'environnement
├── .htaccess                    # Config Apache
├── composer.json                # Dépendances PHP
│
├── auth/                        # Pages authentification (PHP+HTML)
│   ├── login.php
│   ├── logout.php
│   ├── dashboard.php
│   └── forgot-password.php
│
├── pages/                       # Pages applicatives (PHP+HTML)
│   ├── detection.php            # Upload + analyse CT Scan
│   ├── resultats.php            # Historique + détail
│   └── profil.php               # Profil médecin
│
├── backend/
│   ├── functions/
│   │   └── functions.php        # Bibliothèque centrale (50+ fonctions)
│   ├── config/
│   │   ├── database.php         # Singleton PDO
│   │   └── session.php          # SessionManager sécurisé
│   ├── controllers/             # Logique métier AJAX
│   ├── models/                  # Active Record (BaseModel, User, Detection)
│   ├── api/                     # Endpoints REST
│   ├── middleware/              # Auth, CSRF, Rate Limit
│   └── helpers/                 # Security, Validator
│
├── assets/
│   ├── css/                     # Style global + par page
│   ├── js/                      # main.js, auth.js, detection.js, dashboard.js
│   └── uploads/                 # Scans uploadés (hors VCS)
│
├── ai_model/
│   ├── scripts/
│   │   ├── predict.py           # Pipeline prédiction CNN
│   │   ├── preprocess.py        # Prétraitement + augmentation
│   │   ├── train_model.py       # Entraînement (scratch + transfer)
│   │   ├── evaluate.py          # Évaluation + rapport
│   │   └── flask_api.py         # API REST Flask (optionnel)
│   ├── model/
│   │   └── cnn_model.h5         # Modèle entraîné
│   └── requirements.txt
│
└── database/
    ├── hospital.sql             # Schéma complet + données
    └── migrations/              # Migrations individuelles
```

---

## Backend PHP — API

### Endpoints disponibles

| Méthode | URL | Description |
|---------|-----|-------------|
| POST | `/auth/login.php` | Connexion médecin |
| GET  | `/auth/logout.php` | Déconnexion |
| POST | `/backend/api/uploadScan.php` | Upload image CT Scan |
| POST | `/backend/api/predict.php` | Prédiction IA |
| POST | `/backend/api/loginApi.php` | Login AJAX |
| GET  | `/backend/api/export-pdf.php?id=X` | Export rapport PDF |

### Fonctions principales (`functions.php`)

```php
// Authentification
auth_login($email, $password, $remember)   // Connexion sécurisée
auth_logout()                               // Déconnexion + destroy session
auth_require('medecin')                     // Protection de page

// Analyse CT Scan
scan_upload($_FILES['scan'], $userId)       // Upload sécurisé
ai_predict($imagePath)                      // Prédiction CNN
detection_create($userId, $scan, $ai, $patient) // Sauvegarde

// Rendu HTML
html_flash()                               // Messages flash
html_result_badge($type)                   // Badge coloré résultat
html_confidence_bar($score)                // Barre de confiance
pagination_links($paginator)               // Liens pagination
```

---

## Modèle IA Python

### Prédiction simple
```bash
python ai_model/scripts/predict.py --image /path/scan.jpg
# Sortie JSON : {"result_type":"suspect","confidence":0.87,...}
```

### Mode démonstration (sans GPU)
```bash
python ai_model/scripts/predict.py --image /path/scan.jpg --demo
```

### API Flask (hautes performances)
```bash
python ai_model/scripts/flask_api.py --host 0.0.0.0 --port 5000
# Configurer AI_METHOD=api et AI_API_URL dans .env
```

### Entraînement
```bash
# Depuis zéro
python ai_model/scripts/train_model.py --dataset ai_model/dataset --epochs 50

# Transfer learning (EfficientNet)
python ai_model/scripts/train_model.py --transfer --backbone efficientnetb3
```

### Prétraitement dataset
```bash
python ai_model/scripts/preprocess.py --input ai_model/dataset/raw --split train
```

---

## Base de données

### Tables principales

| Table | Description |
|-------|-------------|
| `hospitals` | Établissements hospitaliers |
| `users` | Médecins (auth + profil) |
| `detections` | Analyses CT Scan + résultats IA |
| `activity_logs` | Audit trail complet |

---

## Sécurité

- **Sessions** : fingerprint navigateur, régénération anti-fixation, expiration inactivité
- **CSRF** : token par formulaire, rotation après chaque action sensible
- **Mots de passe** : Argon2ID (memory_cost=65536)
- **Rate limiting** : 5 tentatives / 5 min par IP+email
- **Uploads** : validation MIME réelle (finfo), hash SHA-256, isolation par user
- **Headers HTTP** : CSP, HSTS, X-Frame-Options, X-Content-Type-Options
- **SQL** : PDO avec requêtes préparées exclusivement
- **Soft delete** : aucune suppression physique des données médicales

---

## Déploiement

### Apache
```apache
<VirtualHost *:443>
    ServerName pulmocare.votre-domaine.com
    DocumentRoot /var/www/pulmocare-ia
    SSLEngine on
    SSLCertificateFile    /etc/ssl/certs/pulmocare.crt
    SSLCertificateKeyFile /etc/ssl/private/pulmocare.key
    <Directory /var/www/pulmocare-ia>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

### Systemd — Flask API (optionnel)
```ini
[Unit]
Description=PulmoCare Flask AI API
After=network.target

[Service]
User=www-data
WorkingDirectory=/var/www/pulmocare-ia/ai_model/scripts
ExecStart=/usr/bin/python3 flask_api.py --host 127.0.0.1 --port 5000
Restart=always
Environment=AI_API_KEY=votre_cle_api

[Install]
WantedBy=multi-user.target
```

---

## Avertissement médical

> Ce système est un **outil d'aide au diagnostic** uniquement. Il ne remplace pas le jugement clinique d'un médecin spécialiste. Toute décision thérapeutique doit être prise par un professionnel de santé qualifié.

---

*PulmoCare IA — Développé avec PHP 8.2, TensorFlow 2.x, Python 3.10+*
# pulmocare
