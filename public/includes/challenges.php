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

/** CP earned in the current ISO week (Overview hero's "+N this week") — same YEARWEEK(...,3) window as getTriviaCorrectThisWeek(). */
function getChallengeCpThisWeek(PDO $db, string $participantId): int {
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(points), 0)
        FROM challenge_points
        WHERE participant_id = ? AND YEARWEEK(awarded_at, 3) = YEARWEEK(CURDATE(), 3)
    ");
    $stmt->execute([$participantId]);
    return (int)$stmt->fetchColumn();
}

/**
 * A participant's position on the CP leaderboard ('rank' 1-based, 'total' verified+scored
 * participants) — extracted from the loop index.php and challenges-profile.php already ran
 * inline over getCpLeaderboard(); 'rank' is null if the participant hasn't scored yet.
 */
function getChallengeRank(PDO $db, string $participantId): array {
    $board = getCpLeaderboard($db);
    foreach ($board as $i => $row) {
        if ($row['participant_id'] === $participantId) {
            return ['rank' => $i + 1, 'total' => count($board)];
        }
    }
    return ['rank' => null, 'total' => count($board)];
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
    // Anonymous/pending participants never appear here — REQ-106 keeps the public board and any
    // reuse of this function (home top-3 section) limited to verified/core-linked identities.
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
        WHERE p.status = 'verified'
        GROUP BY cp.participant_id
        ORDER BY total_cp DESC, p.created_at ASC
    ";

    if ($limit !== null) {
        $sql .= " LIMIT " . intval($limit);
    }

    return $db->query($sql)->fetchAll();
}

// Consecutive ISO weeks (Mon-Sun, Europe/Copenhagen) with any CP-earning action — weekly, not
// daily, because Rumor/Trivia content now arrives as one atomic batch a week (Friday-generated,
// Monday-live; see docs/paddock-challenges-reference.md "Content pipeline"), so an engaged
// player naturally clears a week's content in one sitting and has nothing new until the next
// Monday. A day-granularity streak broke on that gap every week regardless of loyalty; this
// metric only breaks when a full ISO week passes with no action at all.
function getChallengeStreak(PDO $db, string $participantId): int {
    $tz = new DateTimeZone('Europe/Copenhagen');
    $today = new DateTime('today', $tz);
    $thisWeekMonday = (clone $today)->modify('-' . ((int)$today->format('N') - 1) . ' days');
    $lastWeekMonday = (clone $thisWeekMonday)->modify('-7 days');

    // No CONVERT_TZ: the DB server's NOW()/CURRENT_TIMESTAMP already returns Europe/Copenhagen
    // local time (config.shared.php sets the same PHP-side default), so answered_at/submitted_at
    // are already local — converting again double-shifted the hour, pushing the date into
    // tomorrow during the hour before local midnight and silently zeroing the streak then.
    $stmt = $db->prepare("
        SELECT DISTINCT DATE(answered_at) as action_date
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

    // Collapse action dates to their distinct week-start (Monday) date, preserving order —
    // several actions in the same ISO week must only count once.
    $weekStarts = [];
    foreach ($dates as $dateStr) {
        $d = new DateTime($dateStr, $tz);
        $monday = (clone $d)->modify('-' . ((int)$d->format('N') - 1) . ' days')->format('Y-m-d');
        if (empty($weekStarts) || end($weekStarts) !== $monday) {
            $weekStarts[] = $monday;
        }
    }

    $streak = 0;
    $currentWeek = null;

    foreach ($weekStarts as $weekStr) {
        if ($currentWeek === null) {
            if ($weekStr === $thisWeekMonday->format('Y-m-d') || $weekStr === $lastWeekMonday->format('Y-m-d')) {
                $currentWeek = new DateTime($weekStr, $tz);
                $streak = 1;
            } else {
                return 0;
            }
        } else {
            $expected = (clone $currentWeek)->modify('-7 days');
            if ($weekStr === $expected->format('Y-m-d')) {
                $currentWeek = $expected;
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

// ============================================
// PREDICTION DUELS (Phase 5)
// ============================================

/** The single race duels attach to (REQ-301) — the next one that hasn't started yet. */
function getNextDuelRace(PDO $db): ?array {
    $stmt = $db->query("
        SELECT * FROM races
        WHERE TIMESTAMP(race_date, race_time) > NOW()
        ORDER BY race_date ASC, race_time ASC LIMIT 1
    ");
    return $stmt->fetch() ?: null;
}

/** True once picks are locked for a duel's race — race start, same boundary as core betting (REQ-304). */
function isDuelRaceLocked(array $race): bool {
    $status = getBettingStatus($race);
    return in_array($status['status'], ['closed', 'completed'], true);
}

/**
 * The Overview tab's "Games Live Now" duels row needs to know if it's this participant's move,
 * without running the full duels-tab setup (friend search, driver fetch, all buckets) which is
 * gated to $section === 'duels'. Trimmed to just: the most recent unresolved duel this
 * participant hasn't picked yet, on a race that hasn't locked — or null if none.
 */
function getPendingDuelForOverview(PDO $db, string $participantId): ?array {
    $stmt = $db->prepare("
        SELECT d.*, r.race_date, r.race_time,
               cp_o.display_name AS opponent_name
        FROM duels d
        JOIN races r ON r.id = d.race_id
        JOIN challenge_participants cp_o
            ON cp_o.id = IF(d.challenger_id = ?, d.opponent_id, d.challenger_id)
        WHERE (d.challenger_id = ? OR d.opponent_id = ?)
              AND d.status NOT IN ('resolved', 'void')
              AND NOT EXISTS (
                  SELECT 1 FROM duel_predictions dp
                  WHERE dp.duel_id = d.id AND dp.participant_id = ?
              )
        ORDER BY d.created_at DESC
    ");
    $stmt->execute([$participantId, $participantId, $participantId, $participantId]);
    foreach ($stmt->fetchAll() as $d) {
        if (!isDuelRaceLocked($d)) {
            return $d;
        }
    }
    return null;
}

/**
 * Participants a challenger can search by display name (REQ-301's "challenge a friend"
 * picker). Anonymous participants never set a display_name, so they're naturally excluded —
 * reachable only via Quick Match, not by direct search.
 */
function searchChallengeParticipants(PDO $db, string $query, string $excludeParticipantId, int $limit = 10): array {
    // `id != ?` keeps you out of your own results — you can't duel yourself (also enforced in
    // the challenge_friend handler and in createDirectDuel()). display_name alone is often just
    // a first name and rarely unique, so we also return a masked email hint to tell two
    // same-named people apart without exposing anyone's full address (REQ-301 privacy).
    $stmt = $db->prepare("
        SELECT id, display_name, email FROM challenge_participants
        WHERE display_name IS NOT NULL AND display_name LIKE ? AND id != ?
        ORDER BY display_name ASC LIMIT ?
    ");
    $stmt->bindValue(1, '%' . $query . '%', PDO::PARAM_STR);
    $stmt->bindValue(2, $excludeParticipantId, PDO::PARAM_STR);
    $stmt->bindValue(3, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $row['email_hint'] = maskEmailForSearch($row['email']);
        unset($row['email']); // never hand the raw address back to the caller/UI
    }
    unset($row);
    return $rows;
}

/**
 * Partially masks an email for the opponent-search list: reveals a couple of leading local-part
 * characters plus the domain, hides the middle. Enough to disambiguate two same-named people,
 * not enough to harvest the address. Returns '' for a null/malformed email so callers can just
 * skip the hint.
 */
function maskEmailForSearch(?string $email): string {
    if ($email === null || $email === '') {
        return '';
    }
    $at = strrpos($email, '@');
    if ($at === false || $at === 0) {
        return '';
    }
    $local  = substr($email, 0, $at);
    $domain = substr($email, $at); // includes the leading '@'
    $len    = mb_strlen($local);

    if ($len <= 2) {
        $masked = mb_substr($local, 0, 1) . '•••';
    } else {
        $masked = mb_substr($local, 0, 2) . '•••' . mb_substr($local, -1);
    }
    return $masked . $domain;
}

/** Direct "challenge a friend" duel creation — live immediately, no separate accept step. */
function createDirectDuel(PDO $db, string $raceId, string $challengerId, string $opponentId): string {
    // Structural block on duelling yourself — callers (challenge_friend handler, quick match)
    // already filter self out, but a duel with challenger == opponent is meaningless and must
    // never be creatable, so enforce it at the one place every duel is born.
    if ($challengerId === $opponentId) {
        throw new InvalidArgumentException('A duel cannot pit a participant against themselves.');
    }
    $duelId = generateUUID();
    $db->prepare("
        INSERT INTO duels (id, race_id, challenger_id, opponent_id, is_quick_match, status, created_at)
        VALUES (?, ?, ?, ?, 0, 'active', NOW())
    ")->execute([$duelId, $raceId, $challengerId, $opponentId]);
    return $duelId;
}

/**
 * Quick Match (REQ-302): queues the participant, then tries to pair with the oldest other
 * waiting entry for the same race. A MySQL named lock scopes the whole check-and-pair
 * critical section per race, so two concurrent requests can never both see the same
 * opponent row and both create a duel — exactly one duel comes out regardless of timing.
 * Returns the new duel id once paired, or null if still waiting.
 */
function tryQuickMatchPairing(PDO $db, string $participantId, string $raceId): ?string {
    $lockName = 'duel_qm_' . $raceId;
    $db->prepare("SELECT GET_LOCK(?, 10)")->execute([$lockName]);

    try {
        try {
            $db->prepare("INSERT INTO duel_quickmatch (race_id, participant_id, created_at) VALUES (?, ?, NOW())")
               ->execute([$raceId, $participantId]);
        } catch (PDOException $e) {
            // UNIQUE(race_id, participant_id) — already queued from an earlier click; fine,
            // still try to pair below.
        }

        $stmt = $db->prepare("
            SELECT participant_id FROM duel_quickmatch
            WHERE race_id = ? AND participant_id != ?
            ORDER BY created_at ASC LIMIT 1 FOR UPDATE
        ");
        $stmt->execute([$raceId, $participantId]);
        $opponent = $stmt->fetchColumn();

        if (!$opponent) {
            return null;
        }

        $db->prepare("DELETE FROM duel_quickmatch WHERE race_id = ? AND participant_id IN (?, ?)")
           ->execute([$raceId, $participantId, $opponent]);

        return createDirectDuel($db, $raceId, $opponent, $participantId);
    } finally {
        $db->prepare("SELECT RELEASE_LOCK(?)")->execute([$lockName]);
    }
}

/** Presence/duplicate/valid-driver checks only (NFR-302) — none of core betting's extra rules. */
function validateDuelPick(string $p1, string $p2, string $p3, array $validDriverIds): string {
    if (!$p1 || !$p2 || !$p3) {
        return t('select_all_positions');
    }
    if (!in_array($p1, $validDriverIds, true) || !in_array($p2, $validDriverIds, true) || !in_array($p3, $validDriverIds, true)) {
        return t('invalid_driver');
    }
    if ($p1 === $p2 || $p1 === $p3 || $p2 === $p3) {
        return t('no_same_driver');
    }
    return '';
}

/**
 * Resolves every open duel for a race after results are entered (REQ-309), called from the
 * admin `update_race` handler right after `calculateRacePoints()`. Skips duels already
 * `resolved`/`void` — re-saving results is a no-op for settled duels (REQ-309/DUEL-08);
 * changing a result requires `reset_race_result` first. Either side missing a pick by race
 * start voids the duel, no CP either way (REQ-308).
 */
function resolveDuelsForRace(PDO $db, string $raceId, array $result): void {
    $stmt = $db->prepare("SELECT * FROM duels WHERE race_id = ? AND status NOT IN ('resolved', 'void')");
    $stmt->execute([$raceId]);
    $duels = $stmt->fetchAll();

    foreach ($duels as $duel) {
        $duelId = $duel['id'];
        $pStmt = $db->prepare("SELECT * FROM duel_predictions WHERE duel_id = ?");
        $pStmt->execute([$duelId]);
        $picksByParticipant = [];
        foreach ($pStmt->fetchAll() as $p) {
            $picksByParticipant[$p['participant_id']] = $p;
        }

        $challengerPick = $picksByParticipant[$duel['challenger_id']] ?? null;
        $opponentPick   = $picksByParticipant[$duel['opponent_id']] ?? null;

        if (!$challengerPick || !$opponentPick) {
            $db->prepare("UPDATE duels SET status = 'void', resolved_at = NOW() WHERE id = ?")->execute([$duelId]);
            continue;
        }

        $challengerScore = scoreDuelPrediction([$challengerPick['p1'], $challengerPick['p2'], $challengerPick['p3']], $result);
        $opponentScore   = scoreDuelPrediction([$opponentPick['p1'], $opponentPick['p2'], $opponentPick['p3']], $result);

        $db->prepare("UPDATE duel_predictions SET score = ? WHERE id = ?")->execute([$challengerScore, $challengerPick['id']]);
        $db->prepare("UPDATE duel_predictions SET score = ? WHERE id = ?")->execute([$opponentScore, $opponentPick['id']]);

        if ($challengerScore === $opponentScore) {
            $winnerId = null;
            awardChallengePoints($db, $duel['challenger_id'], 'duel', 10, "duel:$duelId");
            awardChallengePoints($db, $duel['opponent_id'], 'duel', 10, "duel:$duelId");
        } elseif ($challengerScore > $opponentScore) {
            $winnerId = $duel['challenger_id'];
            awardChallengePoints($db, $duel['challenger_id'], 'duel', 15, "duel:$duelId");
            awardChallengePoints($db, $duel['opponent_id'], 'duel', 5, "duel:$duelId");
        } else {
            $winnerId = $duel['opponent_id'];
            awardChallengePoints($db, $duel['opponent_id'], 'duel', 15, "duel:$duelId");
            awardChallengePoints($db, $duel['challenger_id'], 'duel', 5, "duel:$duelId");
        }

        $db->prepare("UPDATE duels SET status = 'resolved', winner_id = ?, resolved_at = NOW() WHERE id = ?")
           ->execute([$winnerId, $duelId]);

        sendDuelOutcomeEmails($db, $duel, $challengerScore, $opponentScore, $winnerId);
    }
}

/**
 * Reverses duel resolution for a race (REQ-310): deletes the CP ledger rows (matched by
 * `source_ref`, both sides share one), clears scores/winner, returns the duel to `active` —
 * it remains functionally locked (the race already started) but is no longer settled, so
 * re-entering results can resolve it again. Void duels are untouched: they voided because a
 * pick was missing by race start, which a result reset can never change, so they stay void.
 */
function resetDuelsForRace(PDO $db, string $raceId): void {
    $stmt = $db->prepare("SELECT id FROM duels WHERE race_id = ? AND status = 'resolved'");
    $stmt->execute([$raceId]);

    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $duelId) {
        $db->prepare("DELETE FROM challenge_points WHERE source_ref = ?")->execute(["duel:$duelId"]);
        $db->prepare("UPDATE duel_predictions SET score = NULL WHERE duel_id = ?")->execute([$duelId]);
        $db->prepare("UPDATE duels SET status = 'active', winner_id = NULL, resolved_at = NULL WHERE id = ?")->execute([$duelId]);
    }
}

/** Outcome email to both sides (REQ-311) — best effort, never blocks resolution; skipped for a
 *  side with no email (anonymous participants). Requires includes/smtp.php already loaded. */
function sendDuelOutcomeEmails(PDO $db, array $duel, int $challengerScore, int $opponentScore, ?string $winnerId): void {
    if (!function_exists('sendEmail') || !function_exists('getEmailTemplate')) {
        return;
    }

    $stmt = $db->prepare("SELECT * FROM challenge_participants WHERE id IN (?, ?)");
    $stmt->execute([$duel['challenger_id'], $duel['opponent_id']]);
    $byId = [];
    foreach ($stmt->fetchAll() as $p) {
        $byId[$p['id']] = $p;
    }

    $sides = [
        ['id' => $duel['challenger_id'], 'own_score' => $challengerScore, 'opp_id' => $duel['opponent_id'], 'opp_score' => $opponentScore],
        ['id' => $duel['opponent_id'],   'own_score' => $opponentScore,   'opp_id' => $duel['challenger_id'], 'opp_score' => $challengerScore],
    ];

    foreach ($sides as $side) {
        $participant = $byId[$side['id']] ?? null;
        if (!$participant || empty($participant['email'])) {
            continue;
        }
        $opponent = $byId[$side['opp_id']] ?? null;
        $pLang    = $participant['language'] ?: 'da';
        $oppName  = ($opponent['display_name'] ?? '') ?: t('ch_your_opponent', $pLang);

        if ($winnerId === null) {
            $outcomeKey = 'email_duel_result_tie';
        } elseif ($winnerId === $participant['id']) {
            $outcomeKey = 'email_duel_result_won';
        } else {
            $outcomeKey = 'email_duel_result_lost';
        }

        $footer = sprintf(t('email_footer', $pLang), defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : '');
        $html = getEmailTemplate(
            sprintf(t('email_duel_result_greeting', $pLang), $participant['display_name'] ?: $participant['email']),
            sprintf(t($outcomeKey, $pLang), $oppName, $side['own_score'], $side['opp_score']),
            t('email_duel_result_button', $pLang),
            (defined('SITE_URL') ? SITE_URL : '') . '/challenges.php?section=duels',
            '', '', $footer, defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : ''
        );
        sendEmail($participant['email'], t('email_duel_result_subject', $pLang), $html);
    }
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
