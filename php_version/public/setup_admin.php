<?php
/**
 * Setup Admin Script
 * 
 * Kør dette script i terminalen for at oprette første admin bruger:
 *   php setup_admin.php
 * 
 * SLET DENNE FIL EFTER BRUG!
 */

require_once __DIR__ . '/../config.php';

// Check if running from CLI
if (php_sapi_name() !== 'cli') {
    die("Dette script skal køres fra terminalen (CLI).\n");
}

echo "\n";
echo "================================\n";
echo "  F1 Betting - Admin Setup\n";
echo "================================\n\n";

$db = getDB();

// Check if any users exist
$userCount = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
if ($userCount > 0) {
    echo "ADVARSEL: Der findes allerede $userCount bruger(e) i databasen.\n";
    echo "Vil du fortsætte og oprette en ny admin? (ja/nej): ";
    $answer = trim(fgets(STDIN));
    if (strtolower($answer) !== 'ja' && strtolower($answer) !== 'yes') {
        echo "Afbrudt.\n";
        exit(0);
    }
}

// Get admin details
echo "Indtast admin email: ";
$email = trim(fgets(STDIN));

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    die("Ugyldig email.\n");
}

// Check if email exists
$stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
$stmt->execute([$email]);
if ($stmt->fetch()) {
    die("Email er allerede registreret.\n");
}

echo "Indtast admin navn (visningsnavn): ";
$displayName = trim(fgets(STDIN));
if (empty($displayName)) {
    $displayName = explode('@', $email)[0];
}

echo "Indtast password (min. 6 tegn): ";
system('stty -echo');
$password = trim(fgets(STDIN));
system('stty echo');
echo "\n";

if (strlen($password) < 6) {
    die("Password skal være mindst 6 tegn.\n");
}

echo "Bekræft password: ";
system('stty -echo');
$passwordConfirm = trim(fgets(STDIN));
system('stty echo');
echo "\n";

if ($password !== $passwordConfirm) {
    die("Passwords matcher ikke.\n");
}

// Create admin user
$userId = generateUUID();
$hashedPassword = hashPassword($password);

$stmt = $db->prepare("INSERT INTO users (id, email, password, display_name, role, points, stars) VALUES (?, ?, ?, ?, 'admin', 0, 0)");
$stmt->execute([$userId, $email, $hashedPassword, $displayName]);

echo "\n";
echo "================================\n";
echo "  Admin bruger oprettet!\n";
echo "================================\n";
echo "Email: $email\n";
echo "Navn: $displayName\n";
echo "Rolle: admin\n";
echo "\n";
echo "Du kan nu logge ind på: " . SITE_URL . "/login.php\n";
echo "\n";
echo "VIGTIGT: Slet denne fil (setup_admin.php) af sikkerhedshensyn!\n";
echo "\n";
