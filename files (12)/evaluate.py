"""
=============================================================================
evaluate.py — Module d'évaluation complète du modèle CNN
=============================================================================
Évalue le modèle sur les données de test avec toutes les métriques
pertinentes pour un contexte médical :
- Accuracy, Precision, Recall, F1
- Matrice de confusion
- Courbes ROC + AUC par classe
- Rapport de classification complet
- Analyse des erreurs critiques (faux négatifs sur cancer)

Programmation modulaire : chaque évaluation est une fonction indépendante.
=============================================================================
"""

import os
import json
import numpy as np
import matplotlib.pyplot as plt
import matplotlib.gridspec as gridspec
import seaborn as sns

from sklearn.metrics import (
    accuracy_score, precision_score, recall_score, f1_score,
    confusion_matrix, classification_report,
    roc_curve, auc, roc_auc_score
)
from sklearn.preprocessing import label_binarize

from config import (
    CLASS_NAMES, NUM_CLASSES, IDX_MALIGNANT,
    LOGS_DIR, EXPORTS_DIR
)
from preprocess import charger_donnees_depuis_dossiers


# =============================================================================
# 1. Collecte des prédictions sur le jeu de test
# =============================================================================

def collecter_predictions(
    model,
    flux_test,
    verbose: bool = True
) -> tuple:
    """
    Collecte les prédictions du modèle sur le flux de test complet.

    Paramètres
    ----------
    model    : tf.keras.Model
    flux_test: générateur Keras (test)
    verbose  : bool

    Retourne
    --------
    tuple : (y_vrai, y_pred_idx, y_scores)
        y_vrai    : np.ndarray 1D  labels réels (int)
        y_pred_idx: np.ndarray 1D  labels prédits (int)
        y_scores  : np.ndarray 2D  scores de confiance (float), shape (N, C)
    """
    if verbose:
        print("[Évaluation] Collecte des prédictions...")

    flux_test.reset()
    y_scores_liste = []
    y_vrai_liste   = []

    for i in range(len(flux_test)):
        X_batch, y_batch = flux_test[i]
        preds = model.predict(X_batch, verbose=0)
        y_scores_liste.append(preds)
        y_vrai_liste.append(y_batch)

    y_scores  = np.concatenate(y_scores_liste, axis=0)
    y_vrai_oh = np.concatenate(y_vrai_liste,   axis=0)  # one-hot

    y_vrai     = np.argmax(y_vrai_oh, axis=1)
    y_pred_idx = np.argmax(y_scores,  axis=1)

    if verbose:
        print(f"  → {len(y_vrai)} images analysées")

    return y_vrai, y_pred_idx, y_scores


# =============================================================================
# 2. Métriques scalaires
# =============================================================================

def calculer_metriques(
    y_vrai: np.ndarray,
    y_pred: np.ndarray,
    y_scores: np.ndarray
) -> dict:
    """
    Calcule toutes les métriques de classification pertinentes.

    Paramètres
    ----------
    y_vrai  : np.ndarray 1D   Labels réels
    y_pred  : np.ndarray 1D   Labels prédits
    y_scores: np.ndarray 2D   Scores de confiance

    Retourne
    --------
    dict : {
        'accuracy', 'precision_macro', 'recall_macro', 'f1_macro',
        'precision_par_classe', 'recall_par_classe', 'f1_par_classe',
        'auc_par_classe', 'auc_macro',
        'faux_negatifs_malignant', 'faux_positifs_malignant',
        'sensibilite_malignant', 'specificite_malignant'
    }
    """
    metriques = {}

    # Métriques globales
    metriques['accuracy']         = float(accuracy_score(y_vrai, y_pred))
    metriques['precision_macro']  = float(precision_score(y_vrai, y_pred, average='macro', zero_division=0))
    metriques['recall_macro']     = float(recall_score(y_vrai, y_pred, average='macro', zero_division=0))
    metriques['f1_macro']         = float(f1_score(y_vrai, y_pred, average='macro', zero_division=0))

    # Métriques par classe
    prec_cl = precision_score(y_vrai, y_pred, average=None, zero_division=0)
    rec_cl  = recall_score(y_vrai, y_pred, average=None, zero_division=0)
    f1_cl   = f1_score(y_vrai, y_pred, average=None, zero_division=0)

    metriques['precision_par_classe'] = {CLASS_NAMES[i]: float(prec_cl[i]) for i in range(NUM_CLASSES)}
    metriques['recall_par_classe']    = {CLASS_NAMES[i]: float(rec_cl[i])  for i in range(NUM_CLASSES)}
    metriques['f1_par_classe']        = {CLASS_NAMES[i]: float(f1_cl[i])   for i in range(NUM_CLASSES)}

    # AUC par classe (One-vs-Rest)
    y_bin = label_binarize(y_vrai, classes=list(range(NUM_CLASSES)))
    auc_par_classe = {}
    for i in range(NUM_CLASSES):
        fpr, tpr, _ = roc_curve(y_bin[:, i], y_scores[:, i])
        auc_par_classe[CLASS_NAMES[i]] = float(auc(fpr, tpr))

    metriques['auc_par_classe'] = auc_par_classe
    metriques['auc_macro']      = float(np.mean(list(auc_par_classe.values())))

    # ── Métriques critiques pour le cancer (malignant) ──────────────────────
    # En médecine : un faux négatif (cancer raté) est catastrophique.
    # La sensibilité (recall sur malignant) est la métrique la plus critique.

    idx_m = IDX_MALIGNANT
    mat   = confusion_matrix(y_vrai, y_pred, labels=list(range(NUM_CLASSES)))

    vp = mat[idx_m, idx_m]                         # Vrais Positifs malignant
    fn = mat[idx_m, :].sum() - vp                  # Faux Négatifs (cancer raté !)
    fp = mat[:, idx_m].sum() - vp                  # Faux Positifs
    vn = mat.sum() - vp - fn - fp                  # Vrais Négatifs

    metriques['faux_negatifs_malignant']  = int(fn)
    metriques['faux_positifs_malignant']  = int(fp)
    metriques['vrais_positifs_malignant'] = int(vp)
    metriques['vrais_negatifs_malignant'] = int(vn)

    metriques['sensibilite_malignant'] = float(vp / (vp + fn)) if (vp + fn) > 0 else 0.0
    metriques['specificite_malignant'] = float(vn / (vn + fp)) if (vn + fp) > 0 else 0.0
    metriques['valeur_pred_positive']  = float(vp / (vp + fp)) if (vp + fp) > 0 else 0.0
    metriques['valeur_pred_negative']  = float(vn / (vn + fn)) if (vn + fn) > 0 else 0.0

    return metriques


# =============================================================================
# 3. Matrice de confusion
# =============================================================================

def tracer_matrice_confusion(
    y_vrai: np.ndarray,
    y_pred: np.ndarray,
    normaliser: bool = True,
    sauvegarder: bool = True,
    nom_fichier: str = 'matrice_confusion.png'
) -> np.ndarray:
    """
    Trace et sauvegarde la matrice de confusion.

    Paramètres
    ----------
    y_vrai      : np.ndarray 1D
    y_pred      : np.ndarray 1D
    normaliser  : bool    Afficher les proportions plutôt que les comptes
    sauvegarder : bool
    nom_fichier : str

    Retourne
    --------
    np.ndarray : matrice de confusion brute
    """
    mat = confusion_matrix(y_vrai, y_pred, labels=list(range(NUM_CLASSES)))

    fig, axes = plt.subplots(1, 2, figsize=(18, 7), facecolor='#0d1117')
    fig.suptitle('Matrice de Confusion — Détection Cancer du Poumon',
                 fontsize=14, fontweight='bold', color='white')

    for ax, normalise, titre in zip(
        axes,
        [False, True],
        ['Comptes absolus', 'Proportions (normalisée)']
    ):
        mat_display = mat.astype(float)
        if normalise:
            row_sums   = mat_display.sum(axis=1, keepdims=True)
            row_sums[row_sums == 0] = 1
            mat_display /= row_sums
            fmt = '.2f'
        else:
            fmt = 'd'
            mat_display = mat

        sns.heatmap(
            mat_display,
            annot=True,
            fmt=fmt,
            cmap='Blues',
            xticklabels=CLASS_NAMES,
            yticklabels=CLASS_NAMES,
            ax=ax,
            linewidths=0.5,
            linecolor='#30363d',
            cbar_kws={'shrink': 0.8}
        )

        ax.set_xlabel('Classe Prédite', color='white', fontsize=11)
        ax.set_ylabel('Classe Réelle',  color='white', fontsize=11)
        ax.set_title(titre, color='white', fontsize=12, pad=12)
        ax.tick_params(colors='white')
        ax.set_facecolor('#161b22')

        for spine in ax.spines.values():
            spine.set_edgecolor('#30363d')

    plt.tight_layout()

    if sauvegarder:
        os.makedirs(EXPORTS_DIR, exist_ok=True)
        chemin = os.path.join(EXPORTS_DIR, nom_fichier)
        plt.savefig(chemin, dpi=150, bbox_inches='tight', facecolor='#0d1117')
        print(f"[✓] Matrice de confusion sauvegardée : {chemin}")

    plt.show()
    return mat


# =============================================================================
# 4. Courbes ROC
# =============================================================================

def tracer_courbes_roc(
    y_vrai: np.ndarray,
    y_scores: np.ndarray,
    sauvegarder: bool = True,
    nom_fichier: str = 'courbes_roc.png'
):
    """
    Trace les courbes ROC (One-vs-Rest) pour chaque classe.

    Paramètres
    ----------
    y_vrai      : np.ndarray 1D
    y_scores    : np.ndarray 2D
    sauvegarder : bool
    nom_fichier : str
    """
    y_bin = label_binarize(y_vrai, classes=list(range(NUM_CLASSES)))

    couleurs_classes = ['#22c55e', '#eab308', '#ef4444']

    fig, ax = plt.subplots(figsize=(9, 7), facecolor='#0d1117')
    ax.set_facecolor('#161b22')
    ax.set_title('Courbes ROC — One-vs-Rest par classe',
                 color='white', fontsize=13, fontweight='bold', pad=12)

    for i, (nom, couleur) in enumerate(zip(CLASS_NAMES, couleurs_classes)):
        fpr, tpr, _ = roc_curve(y_bin[:, i], y_scores[:, i])
        score_auc   = auc(fpr, tpr)
        ax.plot(fpr, tpr, color=couleur, lw=2.5,
                label=f'{nom}  (AUC = {score_auc:.3f})')
        ax.fill_between(fpr, tpr, alpha=0.06, color=couleur)

    ax.plot([0, 1], [0, 1], 'w--', lw=1.2, label='Aléatoire (AUC = 0.500)')
    ax.set_xlabel('Taux de Faux Positifs (1 - Spécificité)', color='white', fontsize=11)
    ax.set_ylabel('Taux de Vrais Positifs (Sensibilité)',    color='white', fontsize=11)
    ax.legend(loc='lower right', facecolor='#161b22',
              edgecolor='#30363d', labelcolor='white', fontsize=10)
    ax.tick_params(colors='white')
    ax.set_xlim([0, 1])
    ax.set_ylim([0, 1.02])
    ax.grid(True, color='#30363d', linewidth=0.5, linestyle='--')

    for spine in ax.spines.values():
        spine.set_edgecolor('#30363d')

    plt.tight_layout()

    if sauvegarder:
        os.makedirs(EXPORTS_DIR, exist_ok=True)
        chemin = os.path.join(EXPORTS_DIR, nom_fichier)
        plt.savefig(chemin, dpi=150, bbox_inches='tight', facecolor='#0d1117')
        print(f"[✓] Courbes ROC sauvegardées : {chemin}")

    plt.show()


# =============================================================================
# 5. Courbes d'apprentissage
# =============================================================================

def tracer_courbes_apprentissage(
    chemin_history_json: str,
    sauvegarder: bool = True,
    nom_fichier: str = 'courbes_apprentissage.png'
):
    """
    Trace les courbes loss et accuracy depuis l'historique d'entraînement JSON.
    Équivalent du tracé final dans deep_neural_network() du notebook ANN.

    Paramètres
    ----------
    chemin_history_json : str   Chemin vers le JSON généré par train_model.py
    sauvegarder         : bool
    nom_fichier         : str
    """
    with open(chemin_history_json, 'r') as f:
        history = json.load(f)

    epochs = range(1, len(history['loss']) + 1)

    fig, axes = plt.subplots(1, 2, figsize=(16, 6), facecolor='#0d1117')
    fig.suptitle('Courbes d\'Apprentissage CNN',
                 fontsize=14, fontweight='bold', color='white', y=1.01)

    styles = [
        ('loss',     'val_loss',     'Loss (Perte)',       '#58a6ff', '#f97316'),
        ('accuracy', 'val_accuracy', 'Accuracy (Précision)', '#22c55e', '#a78bfa'),
    ]

    for ax, (cle_train, cle_val, titre, coul_tr, coul_val) in zip(axes, styles):
        ax.set_facecolor('#161b22')

        ax.plot(epochs, history[cle_train], color=coul_tr,  lw=2.5, label='Entraînement')
        ax.plot(epochs, history[cle_val],   color=coul_val, lw=2.5, linestyle='--', label='Validation')

        # Meilleure epoch
        if 'acc' in cle_val or 'accuracy' in cle_val:
            meilleure_epoch = int(np.argmax(history[cle_val])) + 1
            meilleure_val   = max(history[cle_val])
            ax.axvline(meilleure_epoch, color='white', linestyle=':', lw=1.2, alpha=0.6)
            ax.annotate(f'Best: {meilleure_val:.3f}\n(epoch {meilleure_epoch})',
                        xy=(meilleure_epoch, meilleure_val),
                        xytext=(meilleure_epoch + 2, meilleure_val - 0.05),
                        fontsize=9, color='white',
                        arrowprops={'arrowstyle': '->', 'color': 'white', 'lw': 1.2})

        ax.set_title(titre,   color='white', fontsize=12, pad=10)
        ax.set_xlabel('Epoch', color='white', fontsize=10)
        ax.set_ylabel(titre,   color='white', fontsize=10)
        ax.legend(facecolor='#161b22', edgecolor='#30363d',
                  labelcolor='white', fontsize=10)
        ax.tick_params(colors='white')
        ax.grid(True, color='#30363d', linewidth=0.5, linestyle='--')

        for spine in ax.spines.values():
            spine.set_edgecolor('#30363d')

    plt.tight_layout()

    if sauvegarder:
        os.makedirs(EXPORTS_DIR, exist_ok=True)
        chemin = os.path.join(EXPORTS_DIR, nom_fichier)
        plt.savefig(chemin, dpi=150, bbox_inches='tight', facecolor='#0d1117')
        print(f"[✓] Courbes d'apprentissage sauvegardées : {chemin}")

    plt.show()


# =============================================================================
# 6. Rapport d'évaluation complet
# =============================================================================

def rapport_evaluation_complet(
    model,
    flux_test,
    nom_experience: str = 'evaluation',
    sauvegarder: bool = True,
    chemin_history_json: str = None
) -> dict:
    """
    Orchestre l'évaluation complète du modèle.
    Génère toutes les figures et sauvegarde le rapport JSON.

    Paramètres
    ----------
    model               : tf.keras.Model
    flux_test           : générateur Keras
    nom_experience      : str
    sauvegarder         : bool
    chemin_history_json : str   (optionnel, pour les courbes d'apprentissage)

    Retourne
    --------
    dict : rapport complet incluant toutes les métriques
    """
    print("\n" + "═" * 70)
    print("  ÉVALUATION COMPLÈTE DU MODÈLE CNN")
    print("═" * 70)

    # ── Collecte des prédictions ──────────────────────────────────────────────
    y_vrai, y_pred, y_scores = collecter_predictions(model, flux_test)

    # ── Métriques ─────────────────────────────────────────────────────────────
    print("\n[1/4] Calcul des métriques...")
    metriques = calculer_metriques(y_vrai, y_pred, y_scores)

    # ── Rapport sklearn ───────────────────────────────────────────────────────
    rapport_sk = classification_report(
        y_vrai, y_pred,
        target_names=CLASS_NAMES,
        output_dict=True,
        zero_division=0
    )

    # ── Figures ───────────────────────────────────────────────────────────────
    print("[2/4] Matrice de confusion...")
    mat = tracer_matrice_confusion(
        y_vrai, y_pred,
        sauvegarder=sauvegarder,
        nom_fichier=f'{nom_experience}_confusion.png'
    )

    print("[3/4] Courbes ROC...")
    tracer_courbes_roc(
        y_vrai, y_scores,
        sauvegarder=sauvegarder,
        nom_fichier=f'{nom_experience}_roc.png'
    )

    if chemin_history_json and os.path.exists(chemin_history_json):
        print("[4/4] Courbes d'apprentissage...")
        tracer_courbes_apprentissage(
            chemin_history_json,
            sauvegarder=sauvegarder,
            nom_fichier=f'{nom_experience}_learning.png'
        )
    else:
        print("[4/4] (Historique d'entraînement non fourni — skipped)")

    # ── Affichage console ─────────────────────────────────────────────────────
    afficher_bilan(metriques)

    # ── Assemblage et sauvegarde du rapport ──────────────────────────────────
    rapport = {
        'experience':       nom_experience,
        'classes':          CLASS_NAMES,
        'metriques':        metriques,
        'rapport_sklearn':  rapport_sk,
        'matrice_confusion': mat.tolist()
    }

    if sauvegarder:
        os.makedirs(LOGS_DIR, exist_ok=True)
        chemin_json = os.path.join(LOGS_DIR, f'{nom_experience}_evaluation.json')
        with open(chemin_json, 'w', encoding='utf-8') as f:
            json.dump(rapport, f, indent=2, ensure_ascii=False)
        print(f"\n[✓] Rapport JSON sauvegardé : {chemin_json}")

    return rapport


# =============================================================================
# 7. Affichage console du bilan
# =============================================================================

def afficher_bilan(metriques: dict):
    """
    Affiche le bilan d'évaluation dans le terminal.

    Paramètres
    ----------
    metriques : dict   Retour de calculer_metriques()
    """
    sep = "─" * 60

    print(f"\n{sep}")
    print("  BILAN GLOBAL")
    print(sep)
    print(f"  Accuracy            : {metriques['accuracy']*100:.2f}%")
    print(f"  Precision (macro)   : {metriques['precision_macro']*100:.2f}%")
    print(f"  Recall (macro)      : {metriques['recall_macro']*100:.2f}%")
    print(f"  F1-Score (macro)    : {metriques['f1_macro']*100:.2f}%")
    print(f"  AUC (macro)         : {metriques['auc_macro']:.4f}")

    print(f"\n{sep}")
    print("  MÉTRIQUES PAR CLASSE")
    print(sep)
    header = f"  {'Classe':<22} {'Précision':>10} {'Recall':>10} {'F1':>10} {'AUC':>10}"
    print(header)
    print("  " + "─" * 56)
    for cls in CLASS_NAMES:
        p   = metriques['precision_par_classe'][cls] * 100
        r   = metriques['recall_par_classe'][cls]    * 100
        f1  = metriques['f1_par_classe'][cls]        * 100
        a   = metriques['auc_par_classe'][cls]
        print(f"  {cls:<22} {p:>9.2f}% {r:>9.2f}% {f1:>9.2f}% {a:>10.4f}")

    print(f"\n{sep}")
    print("  ⚠️  ANALYSE CRITIQUE — Classe 'malignant case'")
    print(sep)
    print(f"  Sensibilité (Recall malin)  : {metriques['sensibilite_malignant']*100:.2f}%")
    print(f"  Spécificité                 : {metriques['specificite_malignant']*100:.2f}%")
    print(f"  Valeur Prédictive Positive  : {metriques['valeur_pred_positive']*100:.2f}%")
    print(f"  Valeur Prédictive Négative  : {metriques['valeur_pred_negative']*100:.2f}%")
    print(f"  Faux Négatifs (cancers ratés): {metriques['faux_negatifs_malignant']}")
    print(f"  Faux Positifs               : {metriques['faux_positifs_malignant']}")

    if metriques['sensibilite_malignant'] < 0.90:
        print(f"\n  ⚠️  ALERTE : Sensibilité < 90% sur les cancers !")
        print(f"      → Risque de cancers non détectés (faux négatifs).")
        print(f"      → Considérer : plus de données, augmentation, ajustement du seuil.")
    else:
        print(f"\n  ✓ Sensibilité ≥ 90% sur la classe maligne — Seuil médical atteint.")

    print(sep)
