-- Beispieldaten für Testbatterien
-- Dieses Script kann nach dem Laden von schema.sql ausgeführt werden

-- Testbatterie 1: Wesenstest nach VDH Standard
INSERT INTO test_batteries (id, name, description) VALUES
(1, 'Wesenstest Standard VDH', 'Standardisierte Testbatterie nach VDH-Richtlinien für Wesenstests');

-- Tests für Batterie 1 (35 Tests gemäß Original-Anwendung)
INSERT INTO battery_tests (battery_id, test_number, test_name, ocean_dimension, max_value) VALUES
-- Offenheit (O) - 7 Tests
(1, 1, 'Reaktion auf neue Umgebung', 'Offenheit', 7),
(1, 2, 'Interesse an neuen Objekten', 'Offenheit', 7),
(1, 3, 'Erkundungsverhalten', 'Offenheit', 7),
(1, 4, 'Anpassung an Veränderungen', 'Offenheit', 7),
(1, 5, 'Neugier bei unbekannten Geräuschen', 'Offenheit', 7),
(1, 6, 'Reaktion auf unbekannte Personen', 'Offenheit', 7),
(1, 7, 'Flexibilität im Verhalten', 'Offenheit', 7),

-- Gewissenhaftigkeit (C) - 7 Tests
(1, 8, 'Impulskontrolle', 'Gewissenhaftigkeit', 7),
(1, 9, 'Aufmerksamkeit bei Aufgaben', 'Gewissenhaftigkeit', 7),
(1, 10, 'Ausdauer und Durchhaltevermögen', 'Gewissenhaftigkeit', 7),
(1, 11, 'Orientierung am Handler', 'Gewissenhaftigkeit', 7),
(1, 12, 'Arbeitsbereitschaft', 'Gewissenhaftigkeit', 7),
(1, 13, 'Zuverlässigkeit bei Kommandos', 'Gewissenhaftigkeit', 7),
(1, 14, 'Konzentrationsfähigkeit', 'Gewissenhaftigkeit', 7),

-- Extraversion (E) - 7 Tests
(1, 15, 'Aktivitätslevel', 'Extraversion', 7),
(1, 16, 'Sozialverhalten mit anderen Hunden', 'Extraversion', 7),
(1, 17, 'Kontaktfreudigkeit', 'Extraversion', 7),
(1, 18, 'Spielverhalten', 'Extraversion', 7),
(1, 19, 'Energielevel', 'Extraversion', 7),
(1, 20, 'Kommunikationsfreudigkeit', 'Extraversion', 7),
(1, 21, 'Selbstbewusstsein', 'Extraversion', 7),

-- Verträglichkeit (A) - 7 Tests
(1, 22, 'Freundlichkeit gegenüber Fremden', 'Verträglichkeit', 14),
(1, 23, 'Kooperationsbereitschaft', 'Verträglichkeit', 7),
(1, 24, 'Sanftmut im Umgang', 'Verträglichkeit', 7),
(1, 25, 'Empathie/Sensibilität', 'Verträglichkeit', 7),
(1, 26, 'Verträglichkeit mit Artgenossen', 'Verträglichkeit', 7),
(1, 27, 'Konfliktverhalten', 'Verträglichkeit', 7),
(1, 28, 'Unterordnung/Hierarchieverhalten', 'Verträglichkeit', 7),

-- Neurotizismus (N) - 7 Tests
(1, 29, 'Ängstlichkeit/Furchtsamkeit', 'Neurotizismus', 7),
(1, 30, 'Stressresistenz', 'Neurotizismus', 7),
(1, 31, 'Reaktion auf unerwartete Ereignisse', 'Neurotizismus', 7),
(1, 32, 'Emotionale Stabilität', 'Neurotizismus', 7),
(1, 33, 'Erregbarkeit', 'Neurotizismus', 7),
(1, 34, 'Gelassenheit in Konfliktsituationen', 'Neurotizismus', 7),
(1, 35, 'Beruhigungsfähigkeit', 'Neurotizismus', 7);

-- Testbatterie 2: Kurz-Screening (optional - für schnelle Tests)
INSERT INTO test_batteries (id, name, description) VALUES
(2, 'Kurz-Screening', 'Verkürzter Wesenstest für Ersteinschätzung (5 Dimensionen, je 1 Test)');

INSERT INTO battery_tests (battery_id, test_number, test_name, ocean_dimension, max_value) VALUES
(2, 1, 'Offenheit: Reaktion auf Neues', 'Offenheit', 7),
(2, 2, 'Gewissenhaftigkeit: Impulskontrolle', 'Gewissenhaftigkeit', 7),
(2, 3, 'Extraversion: Aktivitätslevel', 'Extraversion', 7),
(2, 4, 'Verträglichkeit: Freundlichkeit', 'Verträglichkeit', 14),
(2, 5, 'Neurotizismus: Ängstlichkeit', 'Neurotizismus', 7);

-- Beispiel-Hund
INSERT INTO dogs (owner_name, dog_name, breed, age_years, age_months, gender, neutered, intended_use) VALUES
('Max Mustermann', 'Bello', 'Golden Retriever', 3, 6, 'Rüde', true, 'Therapiehund'),
('Anna Schmidt', 'Luna', 'Border Collie', 2, 0, 'Hündin', false, 'Sporthund'),
('Tom Wagner', 'Rex', 'Deutscher Schäferhund', 5, 3, 'Rüde', true, 'Schutzhund');

-- Beispiel-Session (optional - zum Testen)
INSERT INTO test_sessions (dog_id, battery_id, session_notes) VALUES
(1, 1, 'Erste Testung im Freigelände. Hund war sehr aufmerksam.');

COMMIT;

-- Info ausgeben
SELECT 
    b.name AS battery_name,
    COUNT(bt.id) AS test_count,
    GROUP_CONCAT(DISTINCT bt.ocean_dimension) AS dimensions
FROM test_batteries b
LEFT JOIN battery_tests bt ON b.id = bt.battery_id
GROUP BY b.id, b.name;
