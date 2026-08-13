# Mémoire du projet — PulmoCare IA

> Fichier tenu à jour à chaque conversation. Sert de fil conducteur entre les sessions : ce qui a été fait, ce qui reste, et les décisions prises.

## 1. Contexte général

- **Projet** : PulmoCare IA — plateforme web d'aide au diagnostic du cancer du poumon par CNN (analyse de CT Scan).
- **Auteur** : Radda101, étudiant L2 (LMD) — Université Protestante au Congo, Faculté des Sciences Informatiques.
- **Public cible** : uniquement des professionnels de santé (médecins, radiologues, oncologues). L'interface doit ressembler à un vrai outil hospitalier — jamais à une vitrine technologique. Pas de jargon CNN/architecture exposé aux médecins.

## 2. Stack technique

| Couche      | Techno |
|-------------|--------|
| Backend     | PHP 8.2+, POO stricte (`declare(strict_types=1)`), PDO, namespaces `App\...` |
| IA          | Python, Flask (microservice), TensorFlow/Keras, programmation modulaire (fonctions dédiées) |
| BDD         | MySQL |
| Frontend    | HTML/CSS/JS vanilla, Font Awesome, Chart.js |
| Dev local   | XAMPP |
| Entraînement| Google Colab (GPU T4) |
| Repo        | GitHub (intégration lecture seule + application manuelle des fichiers) |

## 3. État du modèle IA

- Dataset fusionné (Roboflow + IQ-OTH/NCCD), 3 classes : normal / benign / malignant.
- 95.83 % accuracy, 98.78 % sensibilité classe maligne, ROC AUC macro 0.974.
- Classe *benign* sous-représentée → métrique peu fiable sur cette classe. Mitigations : `class_weight`, augmentation ciblée.
- **Train-serving skew identifié** : l'entraînement n'utilise que rescale/augmentation basique, la prédiction ajoute CLAHE + débruitage gaussien. Décision de fix **toujours en attente** (Option A : ajouter CLAHE à l'entraînement / Option B : le retirer de la prédiction, sans réentraînement).

## 4. État du backend

- `auth/`, `pages/`, `backend/api/`, `backend/controllers/`, `backend/models/`, `backend/functions/modules/` : architecture POO en place (SessionManager, CSRF, Security, Validator, BaseModel + modèles dérivés).
- Authentification, upload de scan, prédiction IA, historique, export, profil : fonctionnels.
- **Grad-CAM** : le module Python (`gradcam.py`, `api_server.py`) est prêt. Le PHP appelle déjà `/predict/gradcam` (voir `ai_call_flask_api`) et `ai_save_gradcam()` sauvegarde l'image en base64 → fichier. `detection.php` et `resultats.php` affichent déjà `gradcam_path` s'il existe. **Point encore ouvert** : confirmer la migration de colonne `gradcam_path` en base et vérifier l'affichage en conditions réelles (ce point était marqué "à faire" dans une session précédente — à re-valider, il semble déjà largement implémenté dans le code actuel).
- Bugs déjà diagnostiqués (aperçu image, avatar) : corrections déjà présentes dans le code actuel (`SessionManager::setUser()` inclut `avatar`).

## 5. Frontend — identité visuelle

- Palette maison définie dans `assets/css/human-clinic.css` : fond clair `#f5f7f4`, cartes blanches, accent principal **sauge/teal `#177e73`**, accent secondaire ambre `#c78622`, texte `#1c2b2b` / `#5f6f6d`.
- Typo : Space Grotesk (titres) + Inter (corps).
- Design "humain" : bandeaux `.human-strip` avec photo + légende sur dashboard, détection, résultats, profil.

### 5.1 Page de connexion (`auth/login.php`)

- **Session précédente** : refonte en deux volets (formulaire à gauche / image héros à droite avec carrousel de workflow auto-défilant).
- **Cette session (13/08/2026)** : nouvelle refonte demandée par l'utilisateur, sur la base d'une maquette de référence (`code.html` fourni, style "WeHealth") :
  - Layout à **diagonale** (au lieu du split net 50/50) : volet droit découpé en biais (`clip-path`), portrait du médecin dans un cadre **hexagonal**, cartes flottantes ("+500 analyses ce mois", "Avis d'un confrère").
  - **Recoloration complète** : plus aucune trace du bleu/indigo de la maquette de référence → palette maison (teal `#177e73` / blanc / ambre `#c78622`) appliquée à 100 %.
  - **Formulaire réduit à 2 champs** (email + mot de passe) au lieu des 3 de la maquette de référence (nom de compte / ID utilisateur / mot de passe) — conforme au besoin réel de l'appli (pas d'ID utilisateur séparé).
  - Signe distinctif ajouté pour éviter un rendu "générique IA" : anneaux pointillés façon **cible de scanner CT** derrière le portrait (clin d'œil au sujet du produit plutôt qu'un hexagone décoratif neutre).
  - Copy humanisée, phrases courtes, pas de jargon marketing ("Content de vous revoir", "Chaque scan reste sous votre œil avant toute décision").
  - **Logique PHP backend strictement inchangée** : CSRF, `security_verify_csrf`, `auth_login`, `SessionManager::rotateCsrfToken`, flash messages — copiés tels quels depuis la version précédente.
  - Fichier livré : `auth/login.php` (remplace intégralement l'ancien contenu HTML/CSS, logique PHP identique).
  - **Reste à faire, signalé à l'utilisateur** : appliquer la même esthétique (diagonale + hexagone + palette) à `auth/register.php` et `auth/forgot-password.php` pour la cohérence du parcours d'authentification. Non fait cette session (non demandé explicitement).

## 6. Principes / apprentissages à ne jamais perdre

1. **Médecins d'abord** : aucune exposition de la mécanique technique (CNN, endpoints, etc.) dans l'UI.
2. **Ne jamais casser la logique backend en retouchant le front** : CSRF, session, auth toujours préservés lors des refontes visuelles.
3. **Déséquilibre de classes = limite structurelle du modèle**, pas un bug à "corriger" par un simple réglage.
4. **Radda101 apprend mieux visuellement** : privilégier schémas/diagrammes à l'explication texte pure quand il redemande une clarification.
5. **Livrer des fichiers complets, prêts à l'emploi**, jamais des extraits partiels — il les applique lui-même en local via XAMPP.
6. **Ne jamais reproduire un design de référence tel quel** : en garder la structure/l'idée, recolorer et adapter au besoin réel (nombre de champs, contenu, palette) — éviter tout rendu "IA générique" (formes décoratives creuses, copy vide).

## 7. Prochaines étapes possibles (backlog)

- [ ] Appliquer le nouveau style (diagonale + teal + hexagone) à `register.php` et `forgot-password.php`.
- [ ] Vérifier en conditions réelles l'affichage Grad-CAM sur `detection.php` / `resultats.php` (le code semble prêt, à tester avec XAMPP + le microservice Flask actif).
- [ ] Trancher le train-serving skew (CLAHE/débruitage) : option A ou B, puis exécuter.
- [ ] Continuer la préparation de la soutenance (compréhension théorique du CNN, capacité à l'expliquer au jury).

---
*Dernière mise à jour : session du 13/08/2026 — refonte de `auth/login.php` (diagonale, hexagone, palette teal/blanc, 2 champs, copy humanisée).*
