"""
=============================================================================
train_model.py — Module d'entraînement du CNN
=============================================================================
Équivalent de la fonction `deep_neural_network()` du notebook ANN :
une seule fonction principale orchestre tout le processus d'entraînement.
Programmation modulaire : chaque étape est une fonction indépendante.
=============================================================================
"""

import os
import json
import numpy as np
import tensorflow as tf
from tensorflow.keras.callbacks import (
    ModelCheckpoint, EarlyStopping,
    ReduceLROnPlateau, TensorBoard, CSVLogger
)
from datetime import datetime

from config import (
    CONV_BLOCKS, DENSE_LAYERS, NUM_CLASSES, INPUT_SHAPE,
    LEARNING_RATE, BATCH_SIZE, N_EPOCHS, OPTIMIZER, LOSS_FUNCTION,
    EARLY_STOPPING_PATIENCE, REDUCE_LR_PATIENCE, REDUCE_LR_FACTOR,
    MIN_LR, CHECKPOINT_MONITOR, MODEL_PATH, WEIGHTS_DIR, LOGS_DIR
)
from model_builder import (
    construire_cnn, compiler_modele,
    afficher_resume, sauvegarder_modele, obtenir_config_architecture
)
from preprocess import charger_donnees_depuis_dossiers


# =============================================================================
# 1. Création des callbacks
# =============================================================================

def creer_callbacks(
    nom_experience: str,
    checkpoint_monitor: str = CHECKPOINT_MONITOR,
    patience_arret: int = EARLY_STOPPING_PATIENCE,
    patience_lr: int = REDUCE_LR_PATIENCE,
    facteur_lr: float = REDUCE_LR_FACTOR,
    lr_minimum: float = MIN_LR
) -> list:
    """
    Crée la liste des callbacks Keras pour l'entraînement.
    Chaque callback est configurable via ses paramètres.

    Paramètres
    ----------
    nom_experience    : str    Identifiant unique de l'expérience
    checkpoint_monitor: str    Métrique surveillée pour le checkpoint
    patience_arret    : int    Patience pour EarlyStopping
    patience_lr       : int    Patience pour ReduceLROnPlateau
    facteur_lr        : float  Facteur de réduction du LR
    lr_minimum        : float  Valeur minimale du learning rate

    Retourne
    --------
    list : liste de callbacks Keras
    """
    os.makedirs(WEIGHTS_DIR, exist_ok=True)
    os.makedirs(LOGS_DIR, exist_ok=True)

    # Callback 1 : Sauvegarde du meilleur modèle
    chemin_checkpoint = os.path.join(WEIGHTS_DIR, f'{nom_experience}_best.weights.h5')
    callback_checkpoint = ModelCheckpoint(
        filepath=chemin_checkpoint,
        monitor=checkpoint_monitor,
        save_best_only=True,
        save_weights_only=True,
        mode='max',
        verbose=1
    )

    # Callback 2 : Arrêt anticipé (équivalent de la condition d'arrêt dans le notebook)
    callback_arret = EarlyStopping(
        monitor=checkpoint_monitor,
        patience=patience_arret,
        restore_best_weights=True,
        mode='max',
        verbose=1
    )

    # Callback 3 : Réduction du learning rate sur plateau
    callback_lr = ReduceLROnPlateau(
        monitor='val_loss',
        factor=facteur_lr,
        patience=patience_lr,
        min_lr=lr_minimum,
        verbose=1
    )

    # Callback 4 : TensorBoard
    log_dir = os.path.join(LOGS_DIR, nom_experience)
    callback_tensorboard = TensorBoard(
        log_dir=log_dir,
        histogram_freq=1,
        write_graph=True
    )

    # Callback 5 : CSV Logger
    csv_path = os.path.join(LOGS_DIR, f'{nom_experience}_history.csv')
    callback_csv = CSVLogger(csv_path, separator=',', append=False)

    return [
        callback_checkpoint,
        callback_arret,
        callback_lr,
        callback_tensorboard,
        callback_csv
    ]


# =============================================================================
# 2. Entraînement principal — miroir de deep_neural_network()
# =============================================================================

def entrainer_cnn(
    flux_train,
    flux_val,
    conv_blocks: tuple = CONV_BLOCKS,
    dense_layers: tuple = DENSE_LAYERS,
    num_classes: int = NUM_CLASSES,
    learning_rate: float = LEARNING_RATE,
    n_epochs: int = N_EPOCHS,
    optimizer_nom: str = OPTIMIZER,
    nom_experience: str = None
) -> tuple:
    """
    Orchestre l'entraînement complet du CNN.

    Inspiré de deep_neural_network() du notebook :
    ─────────────────────────────────────────────────────────────────────
    Notebook :  deep_neural_network(X, y, hidden_layers=(16,16,16),
                                    learning_rate=0.1, n_iter=3000)

    Ici :       entrainer_cnn(flux_train, flux_val,
                              conv_blocks=((32,3,1),(64,3,1),(128,3,1)),
                              dense_layers=((512,0.5),(256,0.3)),
                              learning_rate=0.001, n_epochs=50)
    ─────────────────────────────────────────────────────────────────────

    Paramètres
    ----------
    flux_train   : générateur Keras (train)
    flux_val     : générateur Keras (validation)
    conv_blocks  : tuple   Configuration blocs convolutifs
    dense_layers : tuple   Configuration couches denses
    num_classes  : int
    learning_rate: float
    n_epochs     : int
    optimizer_nom: str
    nom_experience: str    Identifiant (auto-généré si None)

    Retourne
    --------
    tuple : (model, history, nom_experience)
    """

    # ── Nom de l'expérience ───────────────────────────────────────────────────
    if nom_experience is None:
        horodatage    = datetime.now().strftime('%Y%m%d_%H%M%S')
        nb_conv       = len(conv_blocks)
        nb_dense      = len(dense_layers)
        nom_experience = f'exp_{nb_conv}conv_{nb_dense}dense_{horodatage}'

    print("\n" + "═" * 70)
    print(f"  EXPÉRIENCE : {nom_experience}")
    print("═" * 70)

    # ── Étape 1 : Construction du modèle ─────────────────────────────────────
    print("\n[1/4] Construction du modèle CNN...")
    model = construire_cnn(
        conv_blocks=conv_blocks,
        dense_layers=dense_layers,
        num_classes=num_classes
    )
    afficher_resume(model)

    # ── Étape 2 : Compilation ─────────────────────────────────────────────────
    print("[2/4] Compilation...")
    model = compiler_modele(
        model,
        learning_rate=learning_rate,
        optimizer_nom=optimizer_nom
    )

    # ── Étape 3 : Callbacks ───────────────────────────────────────────────────
    print("[3/4] Création des callbacks...")
    callbacks = creer_callbacks(nom_experience)

    # ── Étape 4 : Entraînement ────────────────────────────────────────────────
    print(f"[4/4] Entraînement ({n_epochs} epochs max)...\n")
    history = model.fit(
        flux_train,
        validation_data=flux_val,
        epochs=n_epochs,
        callbacks=callbacks,
        verbose=1
    )

    # ── Sauvegarde finale ─────────────────────────────────────────────────────
    sauvegarder_modele(model, MODEL_PATH)
    sauvegarder_historique(history, nom_experience)
    sauvegarder_config(conv_blocks, dense_layers, learning_rate, nom_experience)

    print(f"\n[✓] Entraînement terminé ! Expérience : {nom_experience}")
    return model, history, nom_experience


# =============================================================================
# 3. Transfer Learning (fine-tuning sur modèle pré-entraîné)
# =============================================================================

def entrainer_avec_transfer(
    flux_train,
    flux_val,
    base_model_nom: str = 'EfficientNetB0',
    dense_layers: tuple = DENSE_LAYERS,
    num_classes: int = NUM_CLASSES,
    learning_rate_finetune: float = 1e-4,
    n_epochs_figer: int = 10,
    n_epochs_finetune: int = 30,
    nb_couches_liberer: int = 20,
    nom_experience: str = None
) -> tuple:
    """
    Entraînement par transfer learning avec fine-tuning.
    Phase 1 : couches de base figées, nouvelles couches denses entraînées.
    Phase 2 : fine-tuning des N dernières couches de la base.

    Paramètres
    ----------
    flux_train           : générateur (train)
    flux_val             : générateur (validation)
    base_model_nom       : str   'EfficientNetB0', 'ResNet50V2', 'VGG16'
    dense_layers         : tuple Configuration couches denses
    num_classes          : int
    learning_rate_finetune: float LR pour le fine-tuning (plus petit)
    n_epochs_figer       : int   Epochs avec base figée
    n_epochs_finetune    : int   Epochs de fine-tuning
    nb_couches_liberer   : int   Nombre de couches dégelées en fine-tuning
    nom_experience       : str

    Retourne
    --------
    tuple : (model, history_total, nom_experience)
    """
    from tensorflow.keras.applications import EfficientNetB0, ResNet50V2, VGG16

    if nom_experience is None:
        horodatage    = datetime.now().strftime('%Y%m%d_%H%M%S')
        nom_experience = f'transfer_{base_model_nom}_{horodatage}'

    print("\n" + "═" * 70)
    print(f"  TRANSFER LEARNING : {base_model_nom}")
    print("═" * 70)

    # ── Sélection de la base pré-entraînée ───────────────────────────────────
    bases_disponibles = {
        'EfficientNetB0': EfficientNetB0,
        'ResNet50V2':     ResNet50V2,
        'VGG16':          VGG16
    }
    if base_model_nom not in bases_disponibles:
        raise ValueError(f"Base inconnue : {base_model_nom}. Options : {list(bases_disponibles.keys())}")

    BaseModel = bases_disponibles[base_model_nom]
    base = BaseModel(
        include_top=False,
        weights='imagenet',
        input_shape=INPUT_SHAPE
    )
    base.trainable = False

    # ── Construction du modèle transfer ──────────────────────────────────────
    entree = tf.keras.Input(shape=INPUT_SHAPE, name='input_ct_scan')
    x = base(entree, training=False)
    x = tf.keras.layers.GlobalAveragePooling2D(name='global_avg_pool')(x)

    for c, (nb_neurones, taux_dropout) in enumerate(dense_layers):
        x = tf.keras.layers.Dense(nb_neurones, activation='relu', name=f'dense_{c+1}')(x)
        if taux_dropout > 0:
            x = tf.keras.layers.Dropout(taux_dropout, name=f'dropout_{c+1}')(x)

    sortie = tf.keras.layers.Dense(num_classes, activation='softmax', name='sortie')(x)
    model  = tf.keras.Model(inputs=entree, outputs=sortie, name=f'Transfer_{base_model_nom}')

    # ── Phase 1 : Base figée ──────────────────────────────────────────────────
    print(f"\n[Phase 1] Entraînement avec base {base_model_nom} figée ({n_epochs_figer} epochs)...")
    model.compile(
        optimizer=Adam(learning_rate=LEARNING_RATE),
        loss=LOSS_FUNCTION,
        metrics=['accuracy']
    )
    callbacks_p1 = creer_callbacks(f'{nom_experience}_phase1')
    history_p1 = model.fit(
        flux_train,
        validation_data=flux_val,
        epochs=n_epochs_figer,
        callbacks=callbacks_p1,
        verbose=1
    )

    # ── Phase 2 : Fine-tuning ─────────────────────────────────────────────────
    print(f"\n[Phase 2] Fine-tuning : libération des {nb_couches_liberer} dernières couches...")
    base.trainable = True
    for couche in base.layers[:-nb_couches_liberer]:
        couche.trainable = False

    model.compile(
        optimizer=Adam(learning_rate=learning_rate_finetune),
        loss=LOSS_FUNCTION,
        metrics=['accuracy']
    )
    callbacks_p2 = creer_callbacks(f'{nom_experience}_phase2')
    history_p2 = model.fit(
        flux_train,
        validation_data=flux_val,
        epochs=n_epochs_finetune,
        initial_epoch=n_epochs_figer,
        callbacks=callbacks_p2,
        verbose=1
    )

    sauvegarder_modele(model, MODEL_PATH)
    print(f"\n[✓] Transfer learning terminé ! Expérience : {nom_experience}")
    return model, (history_p1, history_p2), nom_experience


# =============================================================================
# 4. Sauvegarde des méta-données d'entraînement
# =============================================================================

def sauvegarder_historique(history, nom_experience: str):
    """
    Sauvegarde l'historique d'entraînement en JSON.

    Paramètres
    ----------
    history        : Keras History object
    nom_experience : str
    """
    os.makedirs(LOGS_DIR, exist_ok=True)
    chemin = os.path.join(LOGS_DIR, f'{nom_experience}_history.json')

    historique = {k: [float(v) for v in vals] for k, vals in history.history.items()}
    with open(chemin, 'w') as f:
        json.dump(historique, f, indent=2)

    print(f"[✓] Historique sauvegardé : {chemin}")


def sauvegarder_config(
    conv_blocks: tuple,
    dense_layers: tuple,
    learning_rate: float,
    nom_experience: str
):
    """
    Sauvegarde la configuration de l'expérience en JSON.
    Permet la reproductibilité complète.

    Paramètres
    ----------
    conv_blocks    : tuple
    dense_layers   : tuple
    learning_rate  : float
    nom_experience : str
    """
    os.makedirs(LOGS_DIR, exist_ok=True)
    chemin = os.path.join(LOGS_DIR, f'{nom_experience}_config.json')

    config = {
        'experience': nom_experience,
        'date': datetime.now().isoformat(),
        'architecture': obtenir_config_architecture(conv_blocks, dense_layers),
        'hyperparametres': {
            'learning_rate': learning_rate,
            'batch_size': BATCH_SIZE,
            'optimizer': OPTIMIZER,
            'loss': LOSS_FUNCTION
        }
    }
    with open(chemin, 'w') as f:
        json.dump(config, f, indent=2, ensure_ascii=False)

    print(f"[✓] Configuration sauvegardée : {chemin}")
