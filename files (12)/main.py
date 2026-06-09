"""
=============================================================================
main.py — Point d'entrée principal du système IA
=============================================================================
Orchestre tous les modules selon le mode d'exécution :
    --mode train    : entraîne le CNN depuis les données
    --mode evaluate : évalue le modèle sur les données de test
    --mode predict  : prédit sur une image ou un dossier
    --mode serve    : démarre le serveur Flask pour l'API PHP

Utilisation :
    python main.py --mode train
    python main.py --mode predict --image path/to/scan.jpg
    python main.py --mode evaluate
    python main.py --mode serve --port 5000
=============================================================================
"""

import os
import sys
import json
import argparse

# =============================================================================
# Arguments CLI
# =============================================================================

def construire_parser() -> argparse.ArgumentParser:
    """Construit le parser d'arguments en ligne de commande."""
    parser = argparse.ArgumentParser(
        description='Système IA de Détection du Cancer du Poumon — CNN',
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Exemples :
  python main.py --mode train
  python main.py --mode train --conv_blocks "32,3,1;64,3,1;128,3,1" --dense_layers "512,0.5;256,0.3" --epochs 50
  python main.py --mode predict --image scans/patient001.jpg
  python main.py --mode predict --image scans/patient001.jpg --localisation central
  python main.py --mode evaluate
  python main.py --mode serve --port 5000
        """
    )

    parser.add_argument('--mode', type=str, required=True,
                        choices=['train', 'predict', 'evaluate', 'serve', 'demo'],
                        help='Mode d\'exécution')

    # Arguments entraînement
    parser.add_argument('--conv_blocks', type=str, default=None,
                        help='Blocs conv : "filtres,kernel,stride;..." ex: "32,3,1;64,3,1;128,3,1"')
    parser.add_argument('--dense_layers', type=str, default=None,
                        help='Couches denses : "neurones,dropout;..." ex: "512,0.5;256,0.3"')
    parser.add_argument('--lr', type=float, default=None,
                        help='Learning rate (défaut: config.py)')
    parser.add_argument('--epochs', type=int, default=None,
                        help='Nombre d\'epochs (défaut: config.py)')
    parser.add_argument('--batch_size', type=int, default=None,
                        help='Taille du batch (défaut: config.py)')
    parser.add_argument('--transfer', type=str, default=None,
                        choices=['EfficientNetB0', 'ResNet50V2', 'VGG16'],
                        help='Utiliser le transfer learning avec ce modèle de base')

    # Arguments prédiction
    parser.add_argument('--image', type=str, default=None,
                        help='Chemin vers l\'image CT Scan à analyser')
    parser.add_argument('--dossier', type=str, default=None,
                        help='Dossier d\'images CT Scan à analyser en lot')
    parser.add_argument('--localisation', type=str, default='variable',
                        choices=['central', 'peripherique', 'variable'],
                        help='Localisation tumorale (hint pour le diagnostic enrichi)')
    parser.add_argument('--gradcam', action='store_true',
                        help='Activer la visualisation Grad-CAM')
    parser.add_argument('--export', type=str, default=None,
                        help='Chemin de sortie du rapport JSON')

    # Arguments serveur
    parser.add_argument('--port', type=int, default=5000,
                        help='Port du serveur Flask (défaut: 5000)')
    parser.add_argument('--host', type=str, default='0.0.0.0',
                        help='Hôte du serveur Flask (défaut: 0.0.0.0)')

    # Arguments communs
    parser.add_argument('--model_path', type=str, default=None,
                        help='Chemin vers un modèle .h5 spécifique')
    parser.add_argument('--experience', type=str, default=None,
                        help='Nom de l\'expérience')
    parser.add_argument('--verbose', action='store_true',
                        help='Mode verbeux')

    return parser


# =============================================================================
# Parsers des arguments architecturaux (miroir du notebook ANN)
# =============================================================================

def parser_conv_blocks(chaine: str) -> tuple:
    """
    Parse la chaîne conv_blocks en tuple de triplets.
    "32,3,1;64,3,1;128,3,1" → ((32,3,1), (64,3,1), (128,3,1))

    Paramètres
    ----------
    chaine : str

    Retourne
    --------
    tuple de (int, int, int)
    """
    blocs = []
    for bloc in chaine.split(';'):
        parties = [x.strip() for x in bloc.split(',')]
        if len(parties) != 3:
            raise ValueError(f"Format conv_block invalide : '{bloc}' — attendu 'filtres,kernel,stride'")
        filtres, kernel, stride = int(parties[0]), int(parties[1]), int(parties[2])
        blocs.append((filtres, kernel, stride))
    return tuple(blocs)


def parser_dense_layers(chaine: str) -> tuple:
    """
    Parse la chaîne dense_layers en tuple de paires.
    "512,0.5;256,0.3" → ((512, 0.5), (256, 0.3))

    Paramètres
    ----------
    chaine : str

    Retourne
    --------
    tuple de (int, float)
    """
    couches = []
    for couche in chaine.split(';'):
        parties = [x.strip() for x in couche.split(',')]
        if len(parties) != 2:
            raise ValueError(f"Format dense_layer invalide : '{couche}' — attendu 'neurones,dropout'")
        neurones, dropout = int(parties[0]), float(parties[1])
        couches.append((neurones, dropout))
    return tuple(couches)


# =============================================================================
# Modes d'exécution
# =============================================================================

def mode_train(args):
    """Lance l'entraînement du CNN."""
    from config import (
        CONV_BLOCKS, DENSE_LAYERS, LEARNING_RATE, N_EPOCHS, BATCH_SIZE
    )
    from preprocess import charger_donnees_depuis_dossiers
    from train_model import entrainer_cnn, entrainer_avec_transfer

    # Résolution des paramètres : CLI > config.py
    conv_blocks  = parser_conv_blocks(args.conv_blocks)  if args.conv_blocks  else CONV_BLOCKS
    dense_layers = parser_dense_layers(args.dense_layers) if args.dense_layers else DENSE_LAYERS
    lr           = args.lr         if args.lr         else LEARNING_RATE
    epochs       = args.epochs     if args.epochs     else N_EPOCHS
    batch_size   = args.batch_size if args.batch_size else BATCH_SIZE

    print("\n══════════════════════════════════════════════════════")
    print("  MODE : ENTRAÎNEMENT CNN")
    print("══════════════════════════════════════════════════════")
    print(f"  conv_blocks  : {conv_blocks}")
    print(f"  dense_layers : {dense_layers}")
    print(f"  learning_rate: {lr}")
    print(f"  epochs       : {epochs}")
    print(f"  batch_size   : {batch_size}")
    if args.transfer:
        print(f"  transfer     : {args.transfer}")

    # Chargement des données
    flux_train, flux_val, flux_test = charger_donnees_depuis_dossiers(
        batch_size=batch_size
    )

    if args.transfer:
        model, history, exp = entrainer_avec_transfer(
            flux_train, flux_val,
            base_model_nom=args.transfer,
            dense_layers=dense_layers,
            nom_experience=args.experience
        )
    else:
        model, history, exp = entrainer_cnn(
            flux_train, flux_val,
            conv_blocks=conv_blocks,
            dense_layers=dense_layers,
            learning_rate=lr,
            n_epochs=epochs,
            nom_experience=args.experience
        )

    print(f"\n[✓] Entraînement terminé. Expérience : {exp}")


def mode_evaluate(args):
    """Lance l'évaluation complète du modèle."""
    from config import MODEL_PATH, LOGS_DIR
    from preprocess import charger_donnees_depuis_dossiers
    from model_builder import charger_modele
    from evaluate import rapport_evaluation_complet
    import glob

    chemin_modele = args.model_path if args.model_path else MODEL_PATH
    model = charger_modele(chemin_modele)

    _, _, flux_test = charger_donnees_depuis_dossiers()

    # Chercher le dernier historique d'entraînement
    fichiers_history = glob.glob(os.path.join(LOGS_DIR, '*_history.json'))
    history_json = sorted(fichiers_history)[-1] if fichiers_history else None

    rapport = rapport_evaluation_complet(
        model, flux_test,
        nom_experience=args.experience or 'evaluation',
        chemin_history_json=history_json
    )

    print("\n[✓] Évaluation terminée.")


def mode_predict(args):
    """Lance la prédiction sur une image ou un dossier."""
    from config import MODEL_PATH
    from model_builder import charger_modele
    from predict import diagnostiquer, predire_lot, exporter_rapport_json, afficher_rapport
    from gradcam import gradcam_complet, visualiser_gradcam

    chemin_modele = args.model_path if args.model_path else MODEL_PATH
    model = charger_modele(chemin_modele)

    if args.image:
        # Prédiction unique
        rapport = diagnostiquer(
            args.image, model,
            localisation_hint=args.localisation
        )
        afficher_rapport(rapport)

        if args.gradcam:
            classe_idx = rapport['niveau1_cnn']['classe_idx']
            gc_result  = gradcam_complet(model, args.image, classe_idx)
            # Mettre à jour la localisation avec Grad-CAM
            loc_auto = gc_result['localisation']['localisation']
            print(f"\n[Grad-CAM] Localisation auto-détectée : {loc_auto}")
            rapport = diagnostiquer(args.image, model, localisation_hint=loc_auto)
            visualiser_gradcam(gc_result, rapport)

        if args.export:
            exporter_rapport_json(rapport, args.export)

    elif args.dossier:
        # Prédiction par lot
        extensions = ('.jpg', '.jpeg', '.png', '.dcm', '.bmp')
        chemins    = [
            os.path.join(args.dossier, f)
            for f in os.listdir(args.dossier)
            if f.lower().endswith(extensions)
        ]

        if not chemins:
            print(f"[Erreur] Aucune image trouvée dans : {args.dossier}")
            sys.exit(1)

        rapports = predire_lot(chemins, model, localisation_hint=args.localisation)

        if args.export:
            os.makedirs(args.export, exist_ok=True)
            for r in rapports:
                if r['rapport']:
                    nom  = os.path.basename(r['chemin']).replace('.', '_')
                    dest = os.path.join(args.export, f'rapport_{nom}.json')
                    exporter_rapport_json(r['rapport'], dest)
    else:
        print("[Erreur] Spécifiez --image ou --dossier pour le mode predict.")
        sys.exit(1)


def mode_serve(args):
    """Démarre le serveur Flask pour l'API PHP."""
    from api_server import creer_application

    print(f"\n[Serveur] Démarrage sur http://{args.host}:{args.port}")
    print(f"[Serveur] Endpoints disponibles :")
    print(f"   POST /predict        — Prédiction CT Scan")
    print(f"   POST /predict/gradcam — Prédiction + Grad-CAM")
    print(f"   GET  /health         — Vérification du serveur")

    app = creer_application()
    app.run(host=args.host, port=args.port, debug=False)


def mode_demo(args):
    """Affiche un rapport de démonstration sans modèle ni image réels."""
    from config import CLASS_NAMES, STADES_TNM, CANCER_TYPES
    from predict import generer_recommandations, estimer_stade_tnm, identifier_type_histologique

    print("\n" + "═" * 70)
    print("  DÉMONSTRATION — Taxonomie médicale du système IA")
    print("═" * 70)

    print("\n  Classes CNN détectables :")
    for i, cls in enumerate(CLASS_NAMES):
        print(f"    [{i}] {cls}")

    print("\n  Types histologiques :")
    for type_key, type_data in CANCER_TYPES.items():
        print(f"\n  ── {type_data['nom_complet']} ({type_key}) — {type_data['frequence']}")
        for sous_key, sous in type_data['sous_types'].items():
            print(f"       • {sous['nom']} ({sous['localisation']}) — {sous['tabac']}")

    print("\n  Stades TNM :")
    for num, info in STADES_TNM.items():
        print(f"    {info['label']} ({info['code_tnm']}) — {info['gravite'].upper()}")
        print(f"      {info['description']}")

    print("\n  Simulation d'un diagnostic (confiance malignant = 0.78) :")
    conf = 0.78
    stade = estimer_stade_tnm(conf, 'central')
    hist  = identifier_type_histologique(2, conf, 'central')
    recs  = generer_recommandations(2, 'eleve', stade['numero'])

    print(f"    Stade estimé       : {stade['label']} ({stade['code_tnm']})")
    print(f"    Type probable      : {hist['nom_sous_type']}")
    print(f"    Recommandations    :")
    for i, r in enumerate(recs, 1):
        print(f"      {i}. {r}")

    print("\n" + "═" * 70)


# =============================================================================
# Point d'entrée
# =============================================================================

def main():
    parser = construire_parser()
    args   = parser.parse_args()

    modes = {
        'train':    mode_train,
        'evaluate': mode_evaluate,
        'predict':  mode_predict,
        'serve':    mode_serve,
        'demo':     mode_demo
    }

    try:
        modes[args.mode](args)
    except KeyboardInterrupt:
        print("\n[Arrêt] Interruption clavier.")
        sys.exit(0)
    except Exception as e:
        print(f"\n[Erreur] {type(e).__name__}: {e}")
        if args.verbose:
            import traceback
            traceback.print_exc()
        sys.exit(1)


if __name__ == '__main__':
    main()
