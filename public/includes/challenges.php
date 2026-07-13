<?php
// ============================================
// CHALLENGES SYSTEM HELPERS
// ============================================

// Persistent-return device cookie (B3/D13). The raw token lives only here and in the
// emailed access link; the DB stores only its sha256. ~90-day life, rotated on re-use.
if (!defined('CH_ACCESS_COOKIE')) define('CH_ACCESS_COOKIE', 'ch_access');
if (!defined('CH_ACCESS_TTL'))    define('CH_ACCESS_TTL', 60 * 60 * 24 * 90);

/**
 * Resolves the current challenge participant, cached per request. Order (REQ-121):
 * core session → challenge session marker → valid ch_access device cookie
 * (re-establishing the session + rotating the token) → null.
 */
function getChallengeParticipant() {
    static $cached = false;
    if ($cached !== false) {
        return $cached;
    }

    try {
        $db = getDB();

        // 1. Core member → their linked participant (auto-create + link on first hub visit, REQ-104)
        if (!empty($_SESSION['user_id'])) {
            $stmt = $db->prepare("SELECT * FROM challenge_participants WHERE core_user_id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $participant = $stmt->fetch();

            if (!$participant) {
                $user = getCurrentUser();
                if (is_array($user)) {
                    $participantId = generateUUID();
                    $db->prepare("
                        INSERT INTO challenge_participants
                        (id, core_user_id, display_name, language, status, verified_at, created_at)
                        VALUES (?, ?, ?, ?, 'verified', NOW(), NOW())
                    ")->execute([
                        $participantId,
                        $user['id'],
                        $user['display_name'] ?: null,
                        $user['language'] ?? 'da',
                    ]);
                    $stmt = $db->prepare("SELECT * FROM challenge_participants WHERE id = ?");
                    $stmt->execute([$participantId]);
                    $participant = $stmt->fetch();
                }
            }
            return $cached = ($participant ?: null);
        }

        // 2. Guest session marker
        if (!empty($_SESSION['challenge_participant_id'])) {
            $stmt = $db->prepare("SELECT * FROM challenge_participants WHERE id = ?");
            $stmt->execute([$_SESSION['challenge_participant_id']]);
            $participant = $stmt->fetch();
            if ($participant) {
                return $cached = $participant;
            }
            unset($_SESSION['challenge_participant_id']); // marker points at a deleted row
        }

        // 3. Persistent device cookie → re-establish the session (REQ-121/122)
        return $cached = (resolveAccessCookie($db) ?: null);
    } catch (Exception $e) {
        return $cached = null;
    }
}

/**
 * Issues a persistent access token for a participant: stores only its sha256, sets the
 * ch_access cookie (raw), and returns the raw token so callers can also build an email
 * link. MUST be called before output (it sets a cookie).
 */
function issueAccessToken(PDO $db, string $participantId): string {
    $raw  = bin2hex(random_bytes(32));
    $hash = hash('sha256', $raw);
    $db->prepare("
        INSERT INTO challenge_access_tokens (participant_id, token_hash, expires_at, created_at)
        VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 90 DAY), NOW())
    ")->execute([$participantId, $hash]);

    if (!headers_sent()) {
        setcookie(CH_ACCESS_COOKIE, $raw, [
            'expires'  => time() + CH_ACCESS_TTL,
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[CH_ACCESS_COOKIE] = $raw; // reflect within this request
    }
    return $raw;
}

/**
 * Re-establishes a challenge session from the ch_access cookie (REQ-121/122). On a hit:
 * sets the session marker and — while headers are still open — rotates the token (fresh
 * one issued, old one dropped) so a leaked cookie has a short useful life. Returns the
 * participant row or null.
 */
function resolveAccessCookie(PDO $db): ?array {
    $raw = $_COOKIE[CH_ACCESS_COOKIE] ?? '';
    if ($raw === '' || !ctype_xdigit($raw)) {
        return null;
    }
    $stmt = $db->prepare("
        SELECT t.id AS token_id, p.*
        FROM challenge_access_tokens t
        JOIN challenge_participants p ON p.id = t.participant_id
        WHERE t.token_hash = ? AND t.expires_at > NOW()
        LIMIT 1
    ");
    $stmt->execute([hash('sha256', $raw)]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    $tokenId = $row['token_id'];
    unset($row['token_id']);
    $_SESSION['challenge_participant_id'] = $row['id'];

    if (!headers_sent()) {
        $db->prepare("DELETE FROM challenge_access_tokens WHERE id = ?")->execute([$tokenId]);
        issueAccessToken($db, $row['id']); // rotate
    } else {
        $db->prepare("UPDATE challenge_access_tokens SET last_used_at = NOW() WHERE id = ?")->execute([$tokenId]);
    }
    return $row;
}

/** Sign out on this device (REQ-123): revoke this cookie's token and clear the cookie. */
function revokeAccessToken(PDO $db): void {
    $raw = $_COOKIE[CH_ACCESS_COOKIE] ?? '';
    if ($raw !== '' && ctype_xdigit($raw)) {
        $db->prepare("DELETE FROM challenge_access_tokens WHERE token_hash = ?")
           ->execute([hash('sha256', $raw)]);
    }
    clearAccessCookie();
}

/** Sign out everywhere (REQ-123): revoke every access token for the participant. */
function revokeAllAccessTokens(PDO $db, string $participantId): void {
    $db->prepare("DELETE FROM challenge_access_tokens WHERE participant_id = ?")->execute([$participantId]);
    clearAccessCookie();
}

function clearAccessCookie(): void {
    if (!headers_sent()) {
        setcookie(CH_ACCESS_COOKIE, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    unset($_COOKIE[CH_ACCESS_COOKIE]);
}

/**
 * Returns the current participant, creating an anonymous one (pending, email NULL) if none
 * exists — called on the first game action so play needs no email (B1/REQ-101).
 */
function getOrCreateAnonymousParticipant(PDO $db): ?array {
    $existing = getChallengeParticipant();
    if ($existing) {
        return $existing;
    }
    $id = generateUUID();
    $db->prepare("
        INSERT INTO challenge_participants (id, language, status, created_at)
        VALUES (?, ?, 'pending', NOW())
    ")->execute([$id, getLang()]);
    $_SESSION['challenge_participant_id'] = $id;
    $stmt = $db->prepare("SELECT * FROM challenge_participants WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** Records a participant's request to become a core member (admin-approved, B6/D14/REQ-108). */
function requestCoreMembership(PDO $db, string $participantId): void {
    $db->prepare("
        UPDATE challenge_participants
        SET promotion_requested_at = NOW()
        WHERE id = ? AND promotion_requested_at IS NULL
    ")->execute([$participantId]);
}

/** Total CP for a participant (used by the header chip). */
function getChallengeCpTotal(PDO $db, string $participantId): int {
    $stmt = $db->prepare("SELECT COALESCE(SUM(points),0) FROM challenge_points WHERE participant_id = ?");
    $stmt->execute([$participantId]);
    return (int)$stmt->fetchColumn();
}

/**
 * Creates a beat-my-score invite (B2/D12). $itemIds is the exact set the challenger played;
 * $challengerScore their score on it. Returns [inviteId, rawFriendToken]. Long-lived token
 * (14 days) so the friend can accept later.
 */
function createChallengeInvite(PDO $db, string $challengerId, string $game, array $itemIds, int $challengerScore, string $friendEmail): array {
    $id          = generateUUID();
    $friendToken = bin2hex(random_bytes(32));
    $expires     = date('Y-m-d H:i:s', time() + 14 * 24 * 3600);
    $db->prepare("
        INSERT INTO challenge_invites
        (id, challenger_id, game, item_ids, challenger_score, friend_email, friend_token, status, created_at, expires_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'sent', NOW(), ?)
    ")->execute([
        $id, $challengerId, $game, json_encode(array_values($itemIds)),
        $challengerScore, $friendEmail, $friendToken, $expires,
    ]);
    return [$id, $friendToken];
}

/**
 * Guardrail gate for every friend-invite send (REQ-801, Feature 5). All checks fail-closed
 * and are checked in this order: suppressed, already-core, per-friend dedupe, per-sender
 * daily cap, IP/email rate limit. Callers must not send a friend email when this is false.
 */
function canSendInvite(PDO $db, string $senderParticipantId, string $ip, string $friendEmail): bool {
    // 1. Suppressed — absolute, never re-emailed once opted out (REQ-802).
    $stmt = $db->prepare("SELECT 1 FROM challenge_email_suppressions WHERE email = ?");
    $stmt->execute([$friendEmail]);
    if ($stmt->fetch()) {
        return false;
    }

    // 2. Already a core account — never solicited as a friend invite (REQ-111 territory).
    $stmt = $db->prepare("SELECT 1 FROM users WHERE email = ?");
    $stmt->execute([$friendEmail]);
    if ($stmt->fetch()) {
        return false;
    }

    // 3. Per-friend dedupe — one pending ask per friend at a time (REQ-805).
    $stmt = $db->prepare("
        SELECT 1 FROM challenge_invites
        WHERE friend_email = ? AND status = 'sent' AND expires_at > NOW()
              AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        LIMIT 1
    ");
    $stmt->execute([$friendEmail]);
    if ($stmt->fetch()) {
        return false;
    }

    // 4. Per-sender daily cap (REQ-806).
    $cap = (int) (getSettings()['challenge_invite_daily_cap'] ?? 5);
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM challenge_invites
        WHERE challenger_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ");
    $stmt->execute([$senderParticipantId]);
    if ((int) $stmt->fetchColumn() >= $cap) {
        return false;
    }

    // 5. Per-IP and per-friend-email rate limit, scope 'invite' (REQ-807).
    if (isRateLimited($db, $ip, 'invite', $friendEmail)) {
        return false;
    }

    return true;
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

/** ISO-8601 "<year>-W<week>" key, e.g. "2026-W28" — the trivia_week: source_ref format (REQ-405). */
function isoWeekKey(DateTime $dt): string {
    return $dt->format('o') . '-W' . $dt->format('W');
}

/** Correct trivia answers for the current ISO week (Perfect Week tracker, REQ-407). */
function getTriviaCorrectThisWeek(PDO $db, string $participantId): int {
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(ta.correct), 0)
        FROM challenge_trivia_answers ta
        JOIN challenge_trivia_questions tq ON tq.id = ta.question_id
        WHERE ta.participant_id = ? AND YEARWEEK(tq.publish_date, 3) = YEARWEEK(CURDATE(), 3)
    ");
    $stmt->execute([$participantId]);
    return (int) $stmt->fetchColumn();
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
