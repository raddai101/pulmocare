"""
=============================================================================
model_builder.py — Constructeur du modèle CNN
=============================================================================
Architecture modulaire et paramétrable, directement inspirée du notebook
04_ANN_profond.ipynb :

    ANN (notebook)              CNN (ce module)
    ─────────────────────────── ────────────────────────────────────────
    initialisation(dimensions)  → construire_cnn(conv_blocks, dense_layers)
    hidden_layers=(16,16,16)    → conv_blocks=((32,3,1),(64,3,1),(128,3,1))
    boucle for c in range(C)    → boucle for c, (filtres, k, s) in enumerate(conv_blocks)
    forward: sigmoid par couche → conv: ReLU + BN + MaxPool par bloc
    sortie: 1 neurone sigmoid   → sortie: N neurones softmax

Chaque bloc convolutif et chaque couche dense est ajouté dynamiquement
en itérant sur les tuples de configuration — exactement comme la boucle
sur `dimensions` dans le notebook.
=============================================================================
"""

import os
import tensorflow as tf
from tensorflow.keras import Model, Input
from tensorflow.keras.layers import (
    Conv2D, MaxPooling2D, BatchNormalization,
    Dense, Dropout, Flatten, GlobalAveragePooling2D
)
from tensorflow.keras.optimizers import Adam, SGD, RMSprop
from tensorflow.keras.regularizers import l2

from config import (
    INPUT_SHAPE, NUM_CLASSES, CLASS_NAMES,
    CONV_BLOCKS, DENSE_LAYERS, POOLING_SIZE,
    USE_BATCH_NORM, ACTIVATION_CONV, ACTIVATION_DENSE, ACTIVATION_OUT,
    LEARNING_RATE, OPTIMIZER, LOSS_FUNCTION,
    MODEL_PATH, WEIGHTS_DIR
)


# Compatibility wrapper: accept legacy BatchNormalization kwargs (renorm, renorm_clipping, renorm_momentum)
class CompatBatchNormalization(tf.keras.layers.BatchNormalization):
    def __init__(self, *args, **kwargs):
        # Remove legacy args that may be present in older saved model configs
        kwargs.pop('renorm', None)
        kwargs.pop('renorm_clipping', None)
        kwargs.pop('renorm_momentum', None)
        super().__init__(*args, **kwargs)


# Compatibility wrapper: accept legacy Dense kwargs (quantization_config)
class CompatDense(tf.keras.layers.Dense):
    def __init__(self, *args, **kwargs):
        # Remove legacy/unknown args that may be present in older saved model configs
        kwargs.pop('quantization_config', None)
        super().__init__(*args, **kwargs)


# =============================================================================
# 1. Blocs de construction élémentaires
# =============================================================================

def bloc_convolutif(
    x,
    nb_filtres: int,
    taille_kernel: int,
    stride: int,
    batch_norm: bool,
    activation: str,
    regularisation: float,
    nom: str
):
    """
    Construit UN bloc convolutif : Conv2D → [BN] → Activation → MaxPool.
   

    Paramètres
    ----------
    x              : tensor Keras    Entrée du bloc
    nb_filtres     : int             Nombre de filtres Conv2D
    taille_kernel  : int             Taille du kernel (ex: 3 → 3×3)
    stride         : int             Pas de la convolution
    batch_norm     : bool            Ajouter Batch Normalization
    activation     : str             Fonction d'activation (ex: 'relu')
    regularisation : float           Coefficient L2, 0 = désactivé
    nom            : str             Préfixe des noms de couches

    Retourne
    --------
    tensor Keras : sortie du bloc après MaxPool
    """
    reg = l2(regularisation) if regularisation > 0 else None

    # Conv2D — équivalent du Z = W·A + b dans le notebook
    x = Conv2D(
        filters=nb_filtres,
        kernel_size=(taille_kernel, taille_kernel),
        strides=(stride, stride),
        padding='same',
        kernel_regularizer=reg,
        name=f'{nom}_conv'
    )(x)

    # Batch Normalization (optionnel)
    if batch_norm:
        x = BatchNormalization(name=f'{nom}_bn')(x)

    # Activation  ( ReLU)
    x = tf.keras.layers.Activation(activation, name=f'{nom}_act')(x)

    # MaxPooling — réduit les dimensions spatiales
    x = MaxPooling2D(pool_size=POOLING_SIZE, name=f'{nom}_pool')(x)

    return x


def couche_dense(
    x,
    nb_neurones: int,
    taux_dropout: float,
    activation: str,
    regularisation: float,
    nom: str
):
    
    reg = l2(regularisation) if regularisation > 0 else None

    x = Dense(nb_neurones, kernel_regularizer=reg, name=f'{nom}_dense')(x)
    x = tf.keras.layers.Activation(activation, name=f'{nom}_act')(x)

    if taux_dropout > 0:
        x = Dropout(rate=taux_dropout, name=f'{nom}_dropout')(x)

    return x


# =============================================================================
# 2. Constructeur principal — le cœur modulaire
# =============================================================================

def construire_cnn(
    input_shape: tuple = INPUT_SHAPE,
    conv_blocks: tuple = CONV_BLOCKS,
    dense_layers: tuple = DENSE_LAYERS,
    num_classes: int = NUM_CLASSES,
    batch_norm: bool = USE_BATCH_NORM,
    activation_conv: str = ACTIVATION_CONV,
    activation_dense: str = ACTIVATION_DENSE,
    activation_sortie: str = ACTIVATION_OUT,
    regularisation: float = 1e-4,
    pooling_global: bool = False
) -> Model:
    

    # ── Entrée ────────────────────────────────────────────────────────────────
    entree = Input(shape=input_shape, name='input_ct_scan')
    x = entree

    # ── Blocs convolutifs ─────────────────────────────────────────────────────
    # Boucle identique à "for c in range(1, C+1)" du notebook
    # mais itère sur des triplets (filtres, kernel, stride)
    for c, (nb_filtres, taille_kernel, stride) in enumerate(conv_blocks):
        nom_bloc = f'conv_bloc_{c + 1}'
        x = bloc_convolutif(
            x=x,
            nb_filtres=nb_filtres,
            taille_kernel=taille_kernel,
            stride=stride,
            batch_norm=batch_norm,
            activation=activation_conv,
            regularisation=regularisation,
            nom=nom_bloc
        )

    # ── Transition conv → dense ───────────────────────────────────────────────
    if pooling_global:
        # GlobalAveragePooling : réduit chaque feature map à 1 valeur
        x = GlobalAveragePooling2D(name='global_avg_pool')(x)
    else:
        # Flatten classique
        x = Flatten(name='flatten')(x)

    # ── Couches denses ────────────────────────────────────────────────────────
    # Même philosophie : boucle sur les couples (neurones, dropout)
    for c, (nb_neurones, taux_dropout) in enumerate(dense_layers):
        nom_couche = f'dense_{c + 1}'
        x = couche_dense(
            x=x,
            nb_neurones=nb_neurones,
            taux_dropout=taux_dropout,
            activation=activation_dense,
            regularisation=regularisation,
            nom=nom_couche
        )

    # ── Couche de sortie ──────────────────────────────────────────────────────
    # Équivalent de la couche de sortie sigmoid/softmax du notebook
    sortie = Dense(num_classes, activation=activation_sortie, name='sortie')(x)

    model = Model(inputs=entree, outputs=sortie, name='CancerPoumon_CNN')
    return model


# =============================================================================
# 3. Compilation du modèle
# =============================================================================

def compiler_modele(
    model: Model,
    learning_rate: float = LEARNING_RATE,
    optimizer_nom: str = OPTIMIZER,
    loss: str = LOSS_FUNCTION,
    metriques: list = None
) -> Model:
    """
    Compile le modèle avec l'optimiseur, la loss et les métriques.
    Model : modèle compilé
    """
    if metriques is None:
        metriques = ['accuracy']

    # Sélection de l'optimiseur — paramétrable comme learning_rate dans le notebook
    optimiseurs = {
        'adam':    Adam(learning_rate=learning_rate),
        'sgd':     SGD(learning_rate=learning_rate, momentum=0.9, nesterov=True),
        'rmsprop': RMSprop(learning_rate=learning_rate)
    }

    if optimizer_nom not in optimiseurs:
        raise ValueError(f"Optimiseur inconnu : '{optimizer_nom}'. Choisir parmi {list(optimiseurs.keys())}")

    model.compile(
        optimizer=optimiseurs[optimizer_nom],
        loss=loss,
        metrics=metriques
    )
    return model


# =============================================================================
# 4. Initialisation des poids (miroir de `initialisation()` du notebook)
# =============================================================================

def initialiser_poids(model: Model, methode: str = 'he_normal') -> Model:
    """
        Model avec poids réinitialisés
    """
    initialiseurs = {
        'he_normal':      tf.keras.initializers.HeNormal(),
        'glorot_uniform': tf.keras.initializers.GlorotUniform(),
        'lecun_normal':   tf.keras.initializers.LecunNormal()
    }

    if methode not in initialiseurs:
        raise ValueError(f"Initialiseur inconnu : '{methode}'")

    init = initialiseurs[methode]

    for couche in model.layers:
        if hasattr(couche, 'kernel_initializer'):
            couche.kernel.assign(init(shape=couche.kernel.shape))

    return model


# =============================================================================
# 5. Résumé et informations du modèle
# =============================================================================

def afficher_resume(model: Model):
    
    print("\n" + "=" * 80)
    print("  ARCHITECTURE CNN - Détection Cancer du Poumon")
    print("=" * 80)
    model.summary()
    total_params = model.count_params()
    print(f"\n  Total paramètres : {total_params:,}")
    print(f"  Paramètres entraînables : {sum([tf.size(w).numpy() for w in model.trainable_weights]):,}")
    print("=" * 80 + "\n")


def obtenir_config_architecture(
    conv_blocks: tuple = CONV_BLOCKS,
    dense_layers: tuple = DENSE_LAYERS,
    num_classes: int = NUM_CLASSES
) -> dict:
    """
    Retourne
    --------
    dict : configuration complète
    """
    return {
        'conv_blocks': [
            {'bloc': c + 1, 'filtres': f, 'kernel': f'{k}×{k}', 'stride': s}
            for c, (f, k, s) in enumerate(conv_blocks)
        ],
        'dense_layers': [
            {'couche': c + 1, 'neurones': n, 'dropout': d}
            for c, (n, d) in enumerate(dense_layers)
        ],
        'num_classes': num_classes,
        'input_shape': INPUT_SHAPE,
        'total_conv_blocs': len(conv_blocks),
        'total_dense': len(dense_layers)
    }


# =============================================================================
# 6. Sauvegarde / Chargement
# =============================================================================

def sauvegarder_modele(model: Model, chemin: str = MODEL_PATH):
    
    os.makedirs(os.path.dirname(chemin), exist_ok=True)
    model.save(chemin)
    print(f"[OK] Modèle sauvegardé : {chemin}")


def sauvegarder_poids(model: Model, nom: str = 'checkpoint'):
    
    os.makedirs(WEIGHTS_DIR, exist_ok=True)
    chemin = os.path.join(WEIGHTS_DIR, f'{nom}.weights.h5')
    model.save_weights(chemin)
    print(f"[OK] Poids sauvegardés : {chemin}")

compiler_modele

def charger_modele(chemin: str = MODEL_PATH) -> Model:
    
    if not os.path.exists(chemin):
        raise FileNotFoundError(f"Modèle introuvable : {chemin}")

    # Load with compatibility for legacy BatchNormalization config
    try:
        model = tf.keras.models.load_model(
            chemin,
            custom_objects={
                'BatchNormalization': CompatBatchNormalization,
                'Dense': CompatDense
            }
        )
    except Exception:
        # Fallback to default loader if custom_objects failed for some reason
        model = tf.keras.models.load_model(chemin)
    print(f"[OK] Modèle chargé : {chemin}")
    return model


def charger_poids(model: Model, nom: str = 'checkpoint') -> Model:
   
    chemin = os.path.join(WEIGHTS_DIR, f'{nom}.weights.h5')
    if not os.path.exists(chemin):
        raise FileNotFoundError(f"Poids introuvables : {chemin}")

    model.load_weights(chemin)
    print(f"[OK] Poids chargés : {chemin}")
    return model


if __name__ == '__main__':
    modele = construire_cnn()
    modele = compiler_modele(modele)
    afficher_resume(modele)
    print('OK model_builder.py exécuté avec succès.')
