<?php
// ============================================
// CHALLENGES SYSTEM HELPERS
// ============================================

function getChallengeParticipant() {
    try {
        $db = getDB();

        if (!empty($_SESSION['user_id'])) {
            $stmt = $db->prepare("
                SELECT cp.* FROM challenge_participants cp
                WHERE cp.core_user_id = ?
            ");
            $stmt->execute([$_SESSION['user_id']]);
            $participant = $stmt->fetch();

            if ($participant) {
                return $participant;
            }

            $user = getCurrentUser();
            if (!is_array($user)) return null;

            $participantId = generateUUID();
            $db->prepare("
                INSERT INTO challenge_participants
                (id, core_user_id, display_name, language, status, verified_at, created_at)
                VALUES (?, ?, ?, ?, 'verified', NOW(), NOW())
            ")->execute([
                $participantId,
                $user['id'],
                $user['display_name'] ?: null,
                $user['language'] ?? 'da'
            ]);

            return $db->prepare("SELECT * FROM challenge_participants WHERE id = ?")
                      ->execute([$participantId])
                      ->fetch();
        }

        if (!empty($_SESSION['challenge_participant_id'])) {
            $stmt = $db->prepare("SELECT * FROM challenge_participants WHERE id = ?");
            $stmt->execute([$_SESSION['challenge_participant_id']]);
            return $stmt->fetch();
        }

        return null;
    } catch (Exception $e) {
        return null;
    }
}

function requireChallengeParticipant() {
    if (!getChallengeParticipant()) {
        header("HTTP/1.1 403 Forbidden");
        echo "Access denied.";
        exit;
    }
}

function awardChallengePoints(PDO $db, string $participantId, string $game, int $points, string $sourceRef): bool {
    try {
        $id = generateUUID();
        $db->prepare("
            INSERT INTO challenge_points
            (id, participant_id, game, points, source_ref, awarded_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ")->execute([
            $id,
            $participantId,
            $game,
            $points,
            $sourceRef
        ]);
        return true;
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'uniq_participant_source') !== false) {
            return false;
        }
        throw $e;
    }
}

function getCpLeaderboard(PDO $db, int $limit = null): array {
    $sql = "
        SELECT
            cp.participant_id,
            p.display_name,
            p.email,
            p.id as id,
            SUM(cp.points) as total_cp,
            COUNT(DISTINCT cp.source_ref) as awards_count
        FROM challenge_points cp
        JOIN challenge_participants p ON cp.participant_id = p.id
        GROUP BY cp.participant_id
        ORDER BY total_cp DESC, p.created_at ASC
    ";

    if ($limit !== null) {
        $sql .= " LIMIT " . intval($limit);
    }

    return $db->query($sql)->fetchAll();
}

function getChallengeStreak(PDO $db, string $participantId): int {
    $tz = new DateTimeZone('Europe/Copenhagen');
    $today = new DateTime('today', $tz);
    $yesterday = (clone $today)->modify('-1 day');

    $stmt = $db->prepare("
        SELECT DISTINCT DATE(CONVERT_TZ(answered_at, '+00:00', '+01:00')) as action_date
        FROM (
            SELECT answered_at FROM challenge_answers WHERE participant_id = ?
            UNION
            SELECT answered_at FROM challenge_trivia_answers WHERE participant_id = ?
            UNION
            SELECT submitted_at as answered_at FROM duel_predictions dp
            WHERE dp.participant_id = ?
        ) actions
        ORDER BY action_date DESC
    ");

    $stmt->execute([$participantId, $participantId, $participantId]);
    $dates = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($dates)) {
        return 0;
    }

    $streak = 0;
    $currentDate = null;

    foreach ($dates as $dateStr) {
        $actionDate = new DateTime($dateStr, $tz);

        if ($currentDate === null) {
            $currentDate = $actionDate;
            if ($actionDate->format('Y-m-d') === $today->format('Y-m-d') ||
                $actionDate->format('Y-m-d') === $yesterday->format('Y-m-d')) {
                $streak = 1;
            } else {
                return 0;
            }
        } else {
            $expected = (clone $currentDate)->modify('-1 day');
            if ($actionDate->format('Y-m-d') === $expected->format('Y-m-d')) {
                $currentDate = $actionDate;
                $streak++;
            } else {
                break;
            }
        }
    }

    return $streak;
}

function scoreDuelPrediction(array $picks, array $result): int {
    $score = 0;

    for ($i = 0; $i < 3; $i++) {
        $pickPos = $i;
        $pickDriverId = $picks[$i] ?? null;
        $resultDriverId = $result[$i] ?? null;

        if (!$pickDriverId || !$resultDriverId) {
            continue;
        }

        if ($pickDriverId === $resultDriverId) {
            $score += 5;
        } else {
            foreach ($result as $resultPos => $resultDriver) {
                if ($resultDriver === $pickDriverId && $resultPos !== $pickPos) {
                    $score += 2;
                    break;
                }
            }
        }
    }

    return $score;
}

function isRaceHeroWindow(array $race, array $settings = null, DateTime $now = null): bool {
    if ($now === null) {
        $now = new DateTime();
    }

    if ($settings === null) {
        $settings = getSettings();
    }

    $bettingWindowHours = $settings['betting_window_hours'] ?? 48;

    $raceDateTime = new DateTime($race['race_date'] . ' ' . $race['race_time']);
    $raceEnd = clone $raceDateTime;
    $raceEnd->modify('+3 hours');

    $windowOpen = clone $raceDateTime;
    $windowOpen->modify("-{$bettingWindowHours} hours");

    $windowStart = clone $windowOpen;
    $windowStart->modify('-24 hours');

    return $now >= $windowStart && $now <= $raceEnd;
}
