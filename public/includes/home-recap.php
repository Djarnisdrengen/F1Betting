<?php
// Home-page recap carousel — per-slide "diverse" highlight, on top of the base winner+podium
// shown by every slide (public/index.php). Content tiers, best-effort, never fabricated, tried
// in order until one yields something:
//   1. KB analysis-doc headline — a short technical/stats headline for that round (F1MATHS/
//      F1TECH/F1ANALYSIS series). The most distinctly "AI analysis"-flavored source, so it's
//      checked first when available (currently rounds 1-5 only).
//   2. KB race-doc highlight sentence — who overperformed/underperformed their grid slot, or
//      gained the most in the standings, pulled from that race's own KB race-result doc
//      (currently rounds 1-10).
//   3. KB sprint-doc highlight sentence — same idea, from that round's sprint race, when the
//      round had one (currently rounds 2, 4, 5, 9 only).
//   4. Betting-pool upset — a surprise/snub derived from this app's own bets.
//   5. KB qualifying-doc highlight sentence — same idea as tier 2-3, from Saturday's qualifying
//      session, tried LAST among the KB tiers (see below for why) and only after a betting upset
//      also comes up empty.
//   6. Nothing.
// Every KB tier is naturally about someone other than the already-shown P1 (heading) / P2+P3
// (chips) — these are about grid-position movement or a different session, not a restatement of
// the race result. Tiers 1-3 describe the race weekend's own final classification (or, for
// sprint, a separate mini-race with its own well-understood, genuinely different podium) — safe
// to show as "Insight" alongside the race podium. Qualifying is different in kind, not just
// content: it's the PRELIMINARY session for the very race already shown in the same slide, so a
// grid-position claim for a driver who's also in the shown podium reads as contradicting it, not
// complementing it — see the real Hungarian GP 2026 case: qualifying had Leclerc P3/Antonelli P4,
// the race itself (already known, already shown) finished Verstappen P2/Antonelli P3. Labeling it
// "Qualifying" (recapHighlightLabelKey()) helps but isn't sufficient on its own — a reader scanning
// two conflicting position numbers for the same driver in the same card reads it as wrong before
// parsing the label. So qualifying is deliberately tried only as an absolute last resort, after
// the betting upset: in real usage (~5 bets/race in this app) an upset is almost always available
// and is inherently non-contradictory (it's about predictions vs. the SAME shown result), so
// qualifying content in practice now surfaces only for the rare race with neither an analysis/
// race/sprint doc NOR any notable betting upset. getRecapHighlight() still tags every kb-type
// result with its 'source' ('analysis'|'race'|'sprint'|'qualifying') for that labeling.
// Pure decision functions here take no $db and touch no filesystem — unit-harness-tested
// directly (tests/unit/home-recap-harness.php), same convention as
// rumorArchiveBudget()/parseGeneratorState() elsewhere in this codebase. loadPaddockKb() is the
// only I/O in this file. getRecapHighlight() takes a $lang code to gate KB tiers by translation
// availability (see kbHighlightTranslationsDa() below) but never calls the app's own t()/
// getDB() — translated display text stays the caller's responsibility.

// "Grand"/"Prix"/"GP" carry zero discriminating signal — every race name and every KB race-doc
// title contains them, so they must be excluded or every race would match every KB doc.
function normalizeGpTokens(string $name): array {
    $name = preg_replace('/\b(19|20)\d{2}\b/', '', $name); // KB titles carry a year, race names don't
    $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($name), -1, PREG_SPLIT_NO_EMPTY);
    return array_values(array_diff($words, ['grand', 'prix', 'gp']));
}

function gpNamesMatch(string $raceName, string $kbTitle): bool {
    $kbHead = explode('—', $kbTitle, 2)[0]; // drop " — Race Result & Analysis"
    return count(array_intersect(normalizeGpTokens($raceName), normalizeGpTokens($kbHead))) > 0;
}

// Pure. The KB doc of the given type (if any) whose title shares a non-stopword token with
// $raceName — there's no direct races-row-to-KB-doc link anywhere in this schema, so name
// matching is it. Works across 'race'/'qualifying'/'sprint' docs alike; all three follow the
// same "<Race Name> <Year> — <Session>" title convention.
function findKbDocByType(string $raceName, array $kbDocs, string $type): ?array {
    foreach ($kbDocs as $doc) {
        if ((($doc['tags']['type'] ?? null) === $type) && gpNamesMatch($raceName, $doc['title'] ?? '')) {
            return $doc;
        }
    }
    return null;
}

// Pure. The KB 'race' doc matching $raceName — the original, most-common case of
// findKbDocByType(), kept as its own function since most callers only ever want this one.
function findKbRaceMatch(string $raceName, array $kbDocs): ?array {
    return findKbDocByType($raceName, $kbDocs, 'race');
}

// Pure. Splits prose into sentences on ". "/".\n" followed by a capital letter, without
// splitting mid-number on decimal lap times/gaps ("1:23:06.801", "0.427 seconds") — those
// always have a digit immediately before the period, never a letter, so requiring a letter
// right before the period (and never a digit) excludes them by construction.
function splitIntoSentences(string $text): array {
    $parts = preg_split('/(?<=[a-zA-Z])\.\s+(?=[A-Z])/', $text);
    return $parts === false ? [$text] : $parts;
}

// Keyword markers observed across every real KB race/qualifying/sprint-doc `content` field
// (25/25 docs as of this writing — 10 race, 11 qualifying, 4 sprint) that reliably flag an
// evaluative "this stood out" sentence, whatever the specific angle (grid movement, standings
// gain, a qualifying gap, a DSQ, an odd session anomaly). Deliberately excludes generic words
// like "retired"/"did not start" — those are common enough to shadow a better sentence further
// down the same doc without actually being interesting on their own. A finite, non-exhaustive
// list, not a general NLP classifier — a doc with none of these phrasings simply yields nothing
// (never fabricated), same philosophy as every other tier here.
function kbHighlightMarkers(): array {
    return [
        'notable', 'standout', 'significant', 'sharpest', 'largest', 'impressive',
        'underperformance', 'anomaly', 'subdued', 'considerable', 'remarkable', 'striking',
        'noteworthy', 'disqualified',
    ];
}

// Pure. The first sentence in a KB doc's content containing one of the markers above, or null
// if none match (e.g. a round where the specific data needed for that framing was unavailable).
function extractMoverSentence(string $content): ?string {
    foreach (splitIntoSentences($content) as $sentence) {
        $lower = mb_strtolower($sentence);
        foreach (kbHighlightMarkers() as $marker) {
            if (str_contains($lower, $marker)) {
                return rtrim(trim($sentence), '.') . '.';
            }
        }
    }
    return null;
}

// Pure. First KB 'analysis' doc tagged with the given round. Season-wide analysis docs (no
// round in their tags) never match here, by construction — null !== any int.
function findKbAnalysisForRound(int $round, array $kbDocs): ?array {
    foreach ($kbDocs as $doc) {
        if ((($doc['tags']['type'] ?? null) === 'analysis') && (($doc['tags']['round'] ?? null) === $round)) {
            return $doc;
        }
    }
    return null;
}

// Pure. Strips the F1MATHS:/F1TECH:/F1ANALYSIS: prefix (with or without a space before the
// colon — both forms occur in the real KB) and the trailing "(F1Technical)" source tag, so the
// headline reads as a standalone sentence rather than a content-pipeline label.
function cleanKbTitle(string $title): string {
    $title = preg_replace('/^F1\s?(MATHS|TECH|ANALYSIS):\s*/i', '', $title);
    $title = preg_replace('/\s*\(F1Technical\)\s*$/i', '', $title);
    return trim($title);
}

// English KB highlight text → Danish translation. KB content (race/qualifying/sprint/analysis
// docs) is English-only — this app has no live translation call anywhere in its render path, so
// Danish text is a manually-maintained, hand-translated cache of exactly the highlight strings
// getRecapHighlight() can currently produce (cleanKbTitle()'d analysis headlines + the first-
// round-1-11 analysis/race/sprint/qualifying selections as of this writing). English source text
// is the lookup key since extraction is deterministic — same doc, same sentence, every render.
// New KB content the pipeline generates later has no entry here until manually topped up;
// getRecapHighlight() treats a missing translation as "this tier isn't usable in Danish" and
// falls through to the next tier rather than showing English text to a Danish visitor.
function kbHighlightTranslationsDa(): array {
    return [
        'Race‑pace data from Melbourne reveals a clear front‑running quartet' =>
            'Løbstempodata fra Melbourne afslører en tydelig kvartet i front',
        'How did Russell contribute to a perfect double stack pit stop at Shanghai?' =>
            'Hvordan bidrog Russell til et perfekt dobbelt pitstop i Shanghai?',
        "Does the boxplot from Suzuka continue to confirm Mercedes' dominant form?" =>
            'Bekræfter boxplot-dataene fra Suzuka fortsat Mercedes\' dominerende form?',
        'How did the field closed on Mercedes after upgrade festival at the Miami Grand Prix?' =>
            'Hvordan indhentede feltet Mercedes efter opgraderingsfesten ved Miami Grand Prix?',
        "Racing Bulls' crew executes the fastest pit stop in Montreal" =>
            'Racing Bulls\' mekanikere udførte det hurtigste pitstop i Montreal',
        'Isack Hadjar made the largest standings gain, rising four places to eighth on 29 points.' =>
            'Isack Hadjar rykkede mest frem i stillingen og steg fire pladser til en ottendeplads med 29 point.',
        'The most notable gainers across the field were Pierre Gasly and Franco Colapinto, both of Alpine F1 Team, who climbed seven and five positions respectively to finish seventh and eighth.' =>
            'Feltets mest markante fremrykkere var Pierre Gasly og Franco Colapinto, begge fra Alpine F1 Team, der klatrede henholdsvis syv og fem pladser og sluttede som nummer syv og otte.',
        'Verstappen was the most significant forward mover among the frontrunners, advancing three places from fifth on the grid to second.' =>
            'Verstappen var den mest markante fremrykker blandt front-kørerne og avancerede tre pladser fra femtepladsen på startgriddet til en andenplads.',
        'Notable gainers across the field included Franco Colapinto, who advanced ten positions from nineteenth on the grid to ninth, and Liam Lawson, who rose four places from tenth to sixth for RB F1 Team.' =>
            'Blandt feltets fremrykkere var Franco Colapinto, der avancerede ti pladser fra en 19. startplads til en niendeplads, og Liam Lawson, der steg fire pladser fra tiende til sjettepladsen for RB F1 Team.',
        'The standout mover across the full field was Isack Hadjar, who climbed fifteen places from twenty-first on the grid to sixth at the flag for Red Bull.' =>
            'Feltets mest markante fremrykker var Isack Hadjar, der kravlede femten pladser fra 21. startplads til en sjetteplads i mål for Red Bull.',
        'Charles Leclerc secured third for Ferrari at 1:17.445, while Andrea Kimi Antonelli placed fourth for Mercedes at 1:17.479, impressively splitting the two Ferrari entries.' =>
            'Charles Leclerc sikrede sig tredjepladsen for Ferrari på 1:17.445, mens Andrea Kimi Antonelli blev nummer fire for Mercedes på 1:17.479 og imponerende nok splittede de to Ferrari-biler.',
    ];
}

// Pure. The Danish translation of a KB highlight's English text, or null if none exists yet.
function translateKbHighlight(string $enText): ?string {
    return kbHighlightTranslationsDa()[$enText] ?? null;
}

// Pure. Tallies how many bettors picked each driver (any of p1/p2/p3) for a race, then looks
// for something notable: a podium finisher very few backed (a "surprise" — exact finish
// position known), or failing that, the most-picked driver who missed the podium entirely (a
// "snub" — no known finish position, this schema only stores the actual top 3, never full
// classification). Returns null if there are too few bets to say anything meaningful, the race
// isn't fully classified yet, or nothing actually stands out.
function computeBettingUpset(array $raceBets, ?string $resultP1, ?string $resultP2, ?string $resultP3): ?array {
    if (count($raceBets) < 2) {
        return null;
    }
    $podium = array_filter([1 => $resultP1, 2 => $resultP2, 3 => $resultP3]);
    if (count($podium) < 3) {
        return null;
    }

    $picks = [];
    foreach ($raceBets as $bet) {
        foreach ([$bet['p1'] ?? null, $bet['p2'] ?? null, $bet['p3'] ?? null] as $driverId) {
            if ($driverId) {
                $picks[$driverId] = ($picks[$driverId] ?? 0) + 1;
            }
        }
    }
    $total = count($raceBets);

    // Surprise: the podium finisher with the fewest backers (ties broken by position, P1 first).
    $surpriseId = null;
    $surprisePos = null;
    $surpriseCount = null;
    foreach ($podium as $pos => $driverId) {
        $count = $picks[$driverId] ?? 0;
        if ($surpriseCount === null || $count < $surpriseCount) {
            $surpriseId = $driverId;
            $surprisePos = $pos;
            $surpriseCount = $count;
        }
    }
    // Only worth surfacing if meaningfully under half the field backed them.
    if ($surpriseCount !== null && $surpriseCount < $total / 2) {
        return ['type' => 'surprise', 'driver_id' => $surpriseId, 'position' => $surprisePos, 'count' => $surpriseCount, 'total' => $total];
    }

    // Snub: the most-picked driver who isn't in the podium at all.
    $podiumIds = array_values($podium);
    $snubId = null;
    $snubCount = 0;
    foreach ($picks as $driverId => $count) {
        if (!in_array($driverId, $podiumIds, true) && $count > $snubCount) {
            $snubId = $driverId;
            $snubCount = $count;
        }
    }
    if ($snubId !== null && $snubCount >= $total / 2) {
        return ['type' => 'snub', 'driver_id' => $snubId, 'count' => $snubCount, 'total' => $total];
    }

    return null;
}

// Pure — takes an already-read string (or null, simulating file_get_contents() returning
// false), never touches the filesystem itself. Degrades to [] on any missing/malformed input,
// never throws — same split as parseGeneratorState() in admin-dashboards/challenges-usage-lib.php.
function parseKbJson(?string $json): array {
    $data = $json === null || $json === '' ? null : json_decode($json, true);
    return is_array($data) && array_is_list($data) ? $data : [];
}

// Thin wrapper — the only filesystem I/O in this file. public/paddock-rumors/knowledge-base.json
// is the deployed, web-root copy — NOT paddock-rumors/data/knowledge-base.json, the CI-only
// master the Node ingest pipeline writes to (only public/ is uploaded by
// build-deploy/deploy.js — see docs/paddock-rumors-reference.md).
function loadPaddockKb(): array {
    $json = @file_get_contents(__DIR__ . '/../paddock-rumors/knowledge-base.json');
    return parseKbJson($json === false ? null : $json);
}

// Thin orchestrator — tries every tier in the order documented in the file header (analysis →
// race → sprint → betting upset → qualifying-as-last-resort), returns null if nothing applies.
// $lang gates the KB tiers only: when
// 'da', a KB candidate with no entry in kbHighlightTranslationsDa() is treated as unusable and
// skipped, same as if that doc/sentence didn't exist — never shown untranslated. The returned
// 'kb' text is always the English source; translating it for display is the caller's job (it
// needs $lang there too, to choose between showing English as-is or the Danish lookup).
// $driversById is keyed by driver id (see fetchDrivers()); used here only to guard against an
// orphaned/unresolvable driver id, never to build display text (that's the caller's job, since
// it needs t()/driverLastName() — this file stays free of the app's global t()/getDB() calls).
function getRecapHighlight(array $race, array $raceBets, array $driversById, array $kbDocs, string $lang = 'en'): ?array {
    $usable = fn(string $text) => $lang !== 'da' || translateKbHighlight($text) !== null;

    $raceName = $race['name'] ?? '';
    $raceDoc   = findKbDocByType($raceName, $kbDocs, 'race');
    $sprintDoc = findKbDocByType($raceName, $kbDocs, 'sprint');
    $qualiDoc  = findKbDocByType($raceName, $kbDocs, 'qualifying');

    $round = $raceDoc['tags']['round'] ?? $sprintDoc['tags']['round'] ?? $qualiDoc['tags']['round'] ?? null;
    if ($round !== null) {
        $analysisDoc = findKbAnalysisForRound((int) $round, $kbDocs);
        if ($analysisDoc && !empty($analysisDoc['title'])) {
            $text = cleanKbTitle($analysisDoc['title']);
            if ($usable($text)) {
                return ['type' => 'kb', 'text' => $text, 'source' => 'analysis'];
            }
        }
    }

    foreach ([['doc' => $raceDoc, 'source' => 'race'], ['doc' => $sprintDoc, 'source' => 'sprint']] as $entry) {
        if ($entry['doc']) {
            $sentence = extractMoverSentence($entry['doc']['content'] ?? '');
            if ($sentence && $usable($sentence)) {
                return ['type' => 'kb', 'text' => $sentence, 'source' => $entry['source']];
            }
        }
    }

    $upset = computeBettingUpset($raceBets, $race['result_p1'] ?? null, $race['result_p2'] ?? null, $race['result_p3'] ?? null);
    if ($upset && isset($driversById[$upset['driver_id']])) {
        return $upset;
    }

    // Qualifying is tried last, after even the betting upset — see the file header for why:
    // it's the preliminary session for the very race already shown in this slide, so it's the
    // most likely KB tier to visually conflict with the shown podium.
    if ($qualiDoc) {
        $sentence = extractMoverSentence($qualiDoc['content'] ?? '');
        if ($sentence && $usable($sentence)) {
            return ['type' => 'kb', 'text' => $sentence, 'source' => 'qualifying'];
        }
    }

    return null;
}

// Pure. Which t() key to use for the insight label above a getRecapHighlight() result. Analysis/
// race-doc content and the betting upset all describe the race itself, so they share the plain
// "Insight" label — but sprint/qualifying-doc content describes a DIFFERENT session, whose facts
// (grid order, sprint result) routinely differ from the final race classification shown in the
// same slide's podium chips. Labeling those distinctly (e.g. "Qualifying") is what stops them
// from reading as a wrong/contradictory restatement of the race result.
function recapHighlightLabelKey(?array $highlight): string {
    if (($highlight['type'] ?? null) === 'kb') {
        if (($highlight['source'] ?? null) === 'qualifying') {
            return 'home_recap_insight_label_qualifying';
        }
        if (($highlight['source'] ?? null) === 'sprint') {
            return 'home_recap_insight_label_sprint';
        }
    }
    return 'home_recap_insight_label';
}
