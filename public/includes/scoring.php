<?php
require_once __DIR__ . '/../../config.php';

function calculateRacePoints($raceId, $p1, $p2, $p3) {
    global $db;
    $results = [$p1, $p2, $p3];

    $settings       = getSettings();
    $pointsP1       = $settings['points_p1']       ?? 25;
    $pointsP2       = $settings['points_p2']       ?? 18;
    $pointsP3       = $settings['points_p3']       ?? 15;
    $pointsWrongPos = $settings['points_wrong_pos'] ?? 5;

    $stmt = $db->prepare("SELECT * FROM bets WHERE race_id = ? ORDER BY placed_at ASC, id ASC");
    $stmt->execute([$raceId]);
    $bets = $stmt->fetchAll();

    $db->prepare("UPDATE races SET bettingpool_won = 0 WHERE id = ?")->execute([$raceId]);

    // Fetch current race and next race once, before the per-bet loop
    $stmtRace = $db->prepare("SELECT * FROM races WHERE id = ?");
    $stmtRace->execute([$raceId]);
    $currentRace = $stmtRace->fetch(PDO::FETCH_ASSOC);

    $stmtNext = $db->prepare("SELECT * FROM races WHERE race_date > ? ORDER BY race_date ASC LIMIT 1");
    $stmtNext->execute([$currentRace['race_date']]);
    $upcomingRace = $stmtNext->fetch(PDO::FETCH_ASSOC);

    $anyPerfect = false;

    foreach ($bets as $bet) {
        $oldPoints    = $bet['points'];
        $oldIsPerfect = $bet['is_perfect'];
        $predictions  = [$bet['p1'], $bet['p2'], $bet['p3']];

        $points = 0;
        if ($bet['p1'] === $p1) $points += $pointsP1;
        if ($bet['p2'] === $p2) $points += $pointsP2;
        if ($bet['p3'] === $p3) $points += $pointsP3;

        foreach ($predictions as $i => $pred) {
            $ri = array_search($pred, $results);
            if ($ri !== false && $ri !== $i) $points += $pointsWrongPos;
        }

        $isPerfect = ($bet['p1'] === $p1 && $bet['p2'] === $p2 && $bet['p3'] === $p3) ? 1 : 0;

        if ($isPerfect) {
            $anyPerfect = true;
            $db->prepare("UPDATE races SET bettingpool_won = 1 WHERE id = ?")->execute([$raceId]);
        }

        $db->prepare("UPDATE bets SET points = ?, is_perfect = ? WHERE id = ?")
           ->execute([$points, $isPerfect, $bet['id']]);

        $stmtUser = $db->prepare("SELECT points, stars FROM users WHERE id = ?");
        $stmtUser->execute([$bet['user_id']]);
        $user = $stmtUser->fetch();

        $newPoints = $user['points'] - $oldPoints + $points;
        $newStars  = $user['stars']  - ($oldIsPerfect ? 1 : 0) + $isPerfect;

        $db->prepare("UPDATE users SET points = ?, stars = ? WHERE id = ?")
           ->execute([max(0, $newPoints), max(0, $newStars), $bet['user_id']]);
    }

    // Update next race pool once, after all bets are scored
    if ($upcomingRace !== false) {
        $stmtCount = $db->prepare("SELECT COUNT(*) as count FROM users WHERE in_competition = 1");
        $stmtCount->execute();
        $numberOfBetters = $stmtCount->fetch()['count'] ?? 0;
        $betSize     = $settings['bet_size'] ?? 0;
        $newPoolSize = $numberOfBetters * $betSize;

        if (!$anyPerfect) {
            $newPoolSize += $currentRace['bettingpool_size'];
        }

        $db->prepare("UPDATE races SET bettingpool_size = ? WHERE id = ?")
           ->execute([$newPoolSize, $upcomingRace['id']]);
    }
}
