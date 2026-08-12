# Plan de travail pour l’application web de prédiction d’automatisation d’emplois

Afin d’aider les utilisateurs à anticiper l’impact de l’IA sur leur métier, nous allons développer une application web où l’utilisateur saisit son métier et obtient la probabilité d’automatisation d’ici 2030. Un rapport récent prévoit qu’environ 6,1 % des emplois américains pourraient être perdus d’ici 2030 en raison de l’IA et de l’automatisation【12†L231-L239】, soulignant l’importance d’outils de prédiction. Notre application utilisera un dataset synthétique *AI_Impact_on_Jobs_2030* (disponible sur Kaggle) contenant 3 000 métiers avec 18 variables caractéristiques【2†L309-L318】. Le frontend sera en HTML/CSS/JS, le backend combinera PHP (gestion dynamique de l’interface) et Python (machine learning).

## Jeu de données et documentation  
Le dataset **AI_Impact_on_Jobs_2030** (3 000 lignes, 18 colonnes) fournit des informations sur chaque métier, notamment【2†L309-L318】 :  
- **Caractéristiques métier** : `Job_Title` (intitulé du poste), `Average_Salary` (salaire moyen), `Years_Experience` (années d’expérience), `Education_Level` (niveau d’études).  
- **Indicateurs IA** : `AI_Exposure_Index` (sensibilité à l’IA), `Tech_Growth_Factor`, `Automation_Probability_2030`.  
- **Scores de compétences** : `Skill_1` à `Skill_10` (ex. compétences cognitives, manuelles, sociales, etc.).  
- **Variable cible** : `Risk_Category` (risque d’automatisation **High/Medium/Low**).  

Nous commencerons par charger et inspecter ce dataset (via Pandas), vérifier la cohérence des données et consulter la documentation fournie par la source (Kaggle/GitHub) pour comprendre la signification de chaque colonne【2†L309-L318】.

## Analyse exploratoire des données (EDA)  
L’EDA permettra de cerner la structure du jeu de données et les relations entre variables avant de modéliser. Les étapes clés incluront :  
- **Statistiques descriptives** : calculer les statistiques de base (moyenne, médiane, écart-type) pour chaque variable, et étudier la répartition des catégories cibles (`Risk_Category`) pour détecter un éventuel déséquilibre.  
- **Visualisation des distributions** : tracer des histogrammes ou boxplots des variables numériques (salaires, indices, scores de compétences) pour identifier les outliers et la forme des distributions.  
- **Analyse de corrélation** : construire une matrice de corrélation des variables numériques pour voir comment les indicateurs d’IA (e.g. `Automation_Probability_2030`) et les scores de compétences sont liés【2†L322-L330】. Par exemple, on peut s’attendre à ce que des métiers très exposés à l’IA présentent une probabilité d’automatisation plus élevée. Cette étape se fait via des bibliothèques comme Seaborn (heatmap) ou Pandas.  
- **Exploration des catégories** : visualiser le nombre de métiers dans chaque catégorie de risque (faible/moyen/élevé) afin de juger de l’équilibre des classes【2†L334-L338】.  
- **Clustering exploratoire (optionnel)** : appliquer, si pertinent, un algorithme non supervisé (ex. K-means) pour regrouper les métiers selon leurs caractéristiques. Cela peut révéler des groupes de métiers similaires sans utiliser l’étiquette de risque【2†L342-L350】, et servir ultérieurement à suggérer des métiers proches en cas d’automatisation.  

Ces analyses pourront reposer sur des graphiques (histogrammes, nuages de points, heatmaps) et sont cruciales pour formuler des hypothèses sur les variables importantes et détecter les problèmes de qualité de données.  

## Prétraitement des données  
Avant de construire le modèle prédictif, il faut préparer les données :  
- **Nettoyage** : gérer les valeurs manquantes ou aberrantes (si présentes), éliminer les doublons.  
- **Encodage des variables catégorielles** : transformer `Education_Level` et éventuellement `Job_Title` (ou d’autres catégories) en variables numériques. Par exemple, on peut utiliser un *one-hot encoding* pour les niveaux d’études et ignorer `Job_Title` dans le modèle (puisqu’il s’agit du label d’entrée de l’application).  
- **Mise à l’échelle** : normaliser ou standardiser les caractéristiques numériques (salaire, indices, scores) afin que les modèles d’ensemble ne favorisent pas une variable à cause de son amplitude (utilisation de `StandardScaler` ou `MinMaxScaler` de scikit-learn, par exemple).  
- **Construction des jeux d’entraînement et de test** : diviser aléatoirement les données (par exemple 80% apprentissage, 20% test) en conservant la proportion de chaque catégorie de risque. Cette séparation permettra d’évaluer le modèle sur des données jamais vues. On pourra aussi utiliser la validation croisée (`cross_val_score`) pour une évaluation robuste.  

L’objectif du prétraitement est d’obtenir un jeu de données propre, sans fuite d’information, prêt à être ingéré par les algorithmes de classification. Des pipelines scikit-learn peuvent être construits pour enchaîner automatiquement ces étapes et faciliter la réutilisation.  

## Modélisation par apprentissage ensembliste  
Pour prédire la catégorie de risque (`High`/`Medium`/`Low`), nous utiliserons des méthodes d’**ensemble learning**, réputées pour leur précision et robustesse【7†L127-L134】. Les étapes principales sont :  

1. **Sélection des algorithmes** : tester plusieurs modèles ensemblistes courants, tels que *Random Forest*, *Gradient Boosting Machines* (par ex. XGBoost ou `HistGradientBoostingClassifier` de scikit-learn) et *AdaBoost*. Ces algorithmes combinent plusieurs arbres de décision pour améliorer la généralisation et souvent dépassent la barre des 90% de précision en classification【7†L127-L134】. Par exemple, dans un projet similaire sur ce dataset, le *Random Forest* a atteint près de 100% d’accuracy lors des tests【2†L371-L378】.  

2. **Réglage des hyperparamètres** : utiliser les outils de recherche de grille (`GridSearchCV`) ou recherche aléatoire (`RandomizedSearchCV`) fournis par scikit-learn pour optimiser les paramètres (nombre d’arbres, profondeur maximale, taux d’apprentissage, etc.)【16†L149-L158】. Ces techniques évaluent systématiquement ou aléatoirement diverses combinaisons de paramètres en validation croisée afin de maximiser la précision du modèle.  

3. **Évaluation** : mesurer la performance sur le jeu de test en utilisant la précision, la courbe ROC, le F1-score ou la matrice de confusion. L’objectif est d’atteindre au moins 90% de précision globale. On comparera les modèles entre eux et on choisira celui qui offre le meilleur compromis précision/vitesse. D’après l’expérience documentée, le Random Forest est un bon candidat (précision ~100%)【2†L371-L378】, suivi de près par un modèle à base de Bayes naïf (~99.5%)【2†L371-L378】.  

4. **Analyse du modèle** : extraire l’importance des variables (feature importance) pour comprendre quels facteurs influencent le plus le risque (par exemple, on s’attend à ce que `Automation_Probability_2030` soit un des plus forts prédicteurs【2†L396-L402】). Cela peut guider le choix des suggestions de métiers alternatifs basées sur des compétences clés.  

5. **Optimisation du code** : paralléliser l’entraînement (`n_jobs=-1` pour RandomForest), vectoriser les opérations et utiliser des structures efficaces (e.g. `pandas`, `numpy`). Construire des *pipelines* et limiter les boucles explicites en Python permettra d’accélérer l’entraînement et la prédiction. L’optimisation vise à rendre le code rapide et maintenable tout en conservant la précision visée (>90%).  

Grâce à cette approche par ensembles et optimisation, on obtiendra un modèle robuste prêt à être déployé.

## Intégration Python/PHP et déploiement  
Après entraînement du modèle en Python, il faut l’intégrer dans l’application PHP. Deux approches sont possibles【10†L129-L137】 : 

- **API Python (Flask/FastAPI)** : on crée une API REST en Python qui charge le modèle (e.g. stocké avec *joblib*) et expose une route `/predict`. Le script PHP envoie alors une requête HTTP (via cURL) avec le nom du métier saisi, et reçoit en réponse le score ou la catégorie prédite.  
- **Exécution directe de script Python** : depuis PHP, on peut appeler un script Python via une commande système (par ex. `shell_exec("python3 predict.py 'input'")`【10†L219-L228】). Le script Python lit alors l’entrée (metier), effectue la prédiction et renvoie le résultat en JSON que PHP décode.  

Dans les deux cas, le modèle Python doit être préalablement entraîné et sérialisé (`model.pkl`). Le PHP gère le front-end dynamique (formulaire, affichage des résultats) et la logique de communication avec le backend Python. 

Enfin, si le modèle prédit un haut risque d’automatisation, l’application pourra proposer des métiers alternatifs proches. Par exemple, on peut utiliser les clusters issus de l’EDA ou mesurer la similarité des profils de compétences pour trouver des emplois partageant des caractéristiques communes. 

Chaque étape de ce plan (EDA, prétraitement, modélisation ensembliste, intégration) s’appuie sur les meilleures pratiques actuelles de la data science【7†L127-L134】【16†L149-L158】【10†L129-L137】, garantissant ainsi un système efficace et précis.  

**Sources :** Description du dataset et résultats d’exemple【2†L309-L318】【2†L371-L378】, documentation scikit-learn (ensembles, hyperparamètres)【7†L127-L134】【16†L149-L158】, méthodes d’intégration ML-PHP【10†L129-L137】, rapport d’anticipation d’emplois【12†L231-L239】.