<?php
declare(strict_types=1);

echo "PulmoCare — check admin user\n";

// Charger autoload + .env si disponible
$autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require $autoload;
    if (class_exists('\Dotenv\Dotenv')) {
        try {
            \Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
        } catch (Exception $e) {
            // ignore
        }
    }
}

$dbHost = $_ENV['DB_HOST'] ?? '127.0.0.1';
$dbPort = (int)($_ENV['DB_PORT'] ?? 3306);
$dbName = $_ENV['DB_NAME'] ?? 'cancer_detection';
$dbUser = $_ENV['DB_USER'] ?? 'root';
$dbPass = $_ENV['DB_PASS'] ?? '';

$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $dbHost, $dbPort, $dbName);

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "Échec de connexion à la base : " . $e->getMessage() . "\n";
    exit(2);
}

$email = 'admin@pulmocare.fr';
$stmt = $pdo->prepare('SELECT id, email, password, is_active, email_verified_at FROM users WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user) {
    echo "Utilisateur non trouvé pour: {$email}\n";
    exit(1);
}

echo "Utilisateur trouvé:\n";
echo "- id: " . $user['id'] . "\n";
echo "- email: " . $user['email'] . "\n";
echo "- is_active: " . $user['is_active'] . "\n";
echo "- email_verified_at: " . ($user['email_verified_at'] ?? 'NULL') . "\n";

echo "\nVérification du mot de passe 'Admin@2024!':\n";
$match = password_verify('Admin@2024!', $user['password']);
echo $match ? "-> MATCH (le mot de passe correspond)\n" : "-> NO MATCH (mot de passe incorrect)\n";

// Afficher le hash (utile pour debug local)
echo "\nHash stocké: " . $user['password'] . "\n";

// Lister fichiers de rate-limit
$rlDir = sys_get_temp_dir() . '/cancer_ratelimit/';
if (is_dir($rlDir)) {
    echo "\nFichiers de rate-limit ({$rlDir}):\n";
    $files = glob($rlDir . '*');
    if (empty($files)) {
        echo "(aucun fichier)\n";
    } else {
        foreach ($files as $f) {
            echo "- " . basename($f) . ": " . file_get_contents($f) . "\n";
        }
    }
} else {
    echo "\nAucun répertoire de rate-limit trouvé (c'est normal si jamais utilisé).\n";
}

echo "\nConseil : si " . strtoupper('NO MATCH') . ", importez le dump modifié ou exécutez la requête UPDATE fournie précédemment.\n";

exit(0);
