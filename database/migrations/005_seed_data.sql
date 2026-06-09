-- Migration 005 — Données initiales
INSERT IGNORE INTO hospitals (nom, ville, pays, telephone, email) VALUES
    ('CHU Pitié-Salpêtrière',  'Paris',     'France', '+33 1 42 16 00 00', 'contact@chu-pitie.fr'),
    ('Hôpital Cochin',         'Paris',     'France', '+33 1 58 41 41 41', 'contact@cochin.fr'),
    ('CHU de Lyon',            'Lyon',      'France', '+33 4 72 11 69 11', 'contact@chu-lyon.fr'),
    ('Hôpital Lariboisière',   'Paris',     'France', '+33 1 49 95 65 65', 'contact@lariboisiere.fr'),
    ('CHU de Bordeaux',        'Bordeaux',  'France', '+33 5 56 79 56 79', 'contact@chu-bordeaux.fr'),
    ('CHU de Lille',           'Lille',     'France', '+33 3 20 44 44 44', 'contact@chu-lille.fr'),
    ('Hôpital Européen',       'Marseille', 'France', '+33 4 13 42 70 00', 'contact@hopital-europeen.fr'),
    ('CHU de Toulouse',        'Toulouse',  'France', '+33 5 61 77 77 77', 'contact@chu-toulouse.fr');
