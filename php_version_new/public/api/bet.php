<?php
require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json');

// Tjek login
$currentUser = getCurrentUser();
if (!$currentUser) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$db = getDB();
$lang = getLang();

$action = $_POST['action'] ?? 'create';
$raceId = $_POST['race_id'] ?? '';
$betId = $_POST['bet_id'] ?? '';
$p1 = $_POST['p1'] ?? '';
$p2 = $_POST['p2'] ?? '';
$p3 = $_POST['p3'] ?? '';

// Validering
if (!$raceId || !$p1 || !$p2 || !$p3) {
    echo json_encode(['success' => false, 'error' => $lang === 'da' ? 'Udfyld alle felter' : 'Fill all fields']);
    exit;
}

if ($p1 === $p2 || $p1 === $p3 || $p2 === $p3) {
    echo json_encode(['success' => false, 'error' => $lang === 'da' ? 'Kan ikke vælge samme kører flere gange' : 'Cannot select same driver multiple times']);
    exit;
}

// Hent race
$stmt = $db->prepare("SELECT * FROM races WHERE id = ?");
$stmt->execute([$raceId]);
$race = $stmt->fetch();

if (!$race) {
    echo json_encode(['success' => false, 'error' => $lang === 'da' ? 'Løb ikke fundet' : 'Race not found']);
    exit;
}

// Tjek betting status
$status = getBettingStatus($race);
if ($status['status'] !== 'open') {
    echo json_encode(['success' => false, 'error' => $lang === 'da' ? 'Betting er ikke åben' : 'Betting is not open']);
    exit;
}

// Tjek at bet ikke matcher kvalifikation
if ($race['quali_p1'] && $p1 === $race['quali_p1'] && $p2 === $race['quali_p2'] && $p3 === $race['quali_p3']) {
    echo json_encode(['success' => false, 'error' => $lang === 'da' ? 'Bet kan ikke matche kvalifikationsresultatet' : 'Bet cannot match qualifying result']);
    exit;
}

// Hent eksisterende bets for dette løb
if ($action === 'update') {
    $stmt = $db->prepare("SELECT p1, p2, p3 FROM bets WHERE race_id = ? AND id != ?");
    $stmt->execute([$raceId, $betId]);
} else {
    $stmt = $db->prepare("SELECT p1, p2, p3 FROM bets WHERE race_id = ?");
    $stmt->execute([$raceId]);
}
$existingBets = $stmt->fetchAll();

// Tjek om kombinationen allerede er taget
foreach ($existingBets as $eb) {
    if ($eb['p1'] === $p1 && $eb['p2'] === $p2 && $eb['p3'] === $p3) {
        echo json_encode(['success' => false, 'error' => $lang === 'da' ? 'Denne kombination er allerede taget' : 'This combination is already taken']);
        exit;
    }
}

if ($action === 'update') {
    // Opdater eksisterende bet
    $stmt = $db->prepare("SELECT * FROM bets WHERE id = ? AND user_id = ?");
    $stmt->execute([$betId, $currentUser['id']]);
    $existingBet = $stmt->fetch();
    
    if (!$existingBet) {
        echo json_encode(['success' => false, 'error' => $lang === 'da' ? 'Bet ikke fundet' : 'Bet not found']);
        exit;
    }
    
    $stmt = $db->prepare("UPDATE bets SET p1 = ?, p2 = ?, p3 = ?, placed_at = NOW() WHERE id = ?");
    $stmt->execute([$p1, $p2, $p3, $betId]);
    
    echo json_encode(['success' => true, 'message' => $lang === 'da' ? 'Bet opdateret!' : 'Bet updated!']);
} else {
    // Tjek om bruger allerede har bet på dette løb
    $stmt = $db->prepare("SELECT id FROM bets WHERE user_id = ? AND race_id = ?");
    $stmt->execute([$currentUser['id'], $raceId]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => $lang === 'da' ? 'Du har allerede et bet på dette løb' : 'You already have a bet for this race']);
        exit;
    }
    
    // Opret nyt bet
    $newBetId = generateUUID();
    $stmt = $db->prepare("INSERT INTO bets (id, user_id, race_id, p1, p2, p3) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$newBetId, $currentUser['id'], $raceId, $p1, $p2, $p3]);
    
    echo json_encode(['success' => true, 'message' => $lang === 'da' ? 'Bet placeret!' : 'Bet placed!']);
}
