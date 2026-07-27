<?php
// CLI harness for the pure matching/tally functions in public/includes/home-recap.php — the
// recap carousel's "diverse highlight" feature (Paddock Rumors KB insight + betting-pool
// upset). includes/home-recap.php only defines functions at top level with no ambient $db/file
// reads inside the pure ones, so it's safe to load standalone.
// Run: php tests/unit/home-recap-harness.php    (exit 0 = all green)

if (php_sapi_name() !== 'cli') {
    exit(1);
}

require __DIR__ . '/../../public/includes/home-recap.php';

$fails = 0;
function check(string $name, bool $cond): void {
    global $fails;
    echo ($cond ? 'ok     ' : 'FAILED ') . $name . "\n";
    if (!$cond) $fails++;
}

// ── normalizeGpTokens() / gpNamesMatch() ──
check(
    'normalizeGpTokens strips Grand/Prix/GP and a year',
    normalizeGpTokens('Australian Grand Prix 2026') === ['australian']
);
check(
    'normalizeGpTokens keeps every real word otherwise',
    normalizeGpTokens('Barcelona-Catalunya Grand Prix') === ['barcelona', 'catalunya']
);
check(
    'gpNamesMatch: exact same GP matches',
    gpNamesMatch('Australian Grand Prix', 'Australian Grand Prix 2026 — Race Result & Analysis') === true
);
check(
    'gpNamesMatch: Barcelona-Catalunya vs Barcelona still matches (shared token)',
    gpNamesMatch('Barcelona-Catalunya Grand Prix', 'Barcelona Grand Prix 2026 — Race Result & Analysis') === true
);
check(
    'gpNamesMatch: two different GPs do NOT match (the stopword-exclusion bug this guards against)',
    gpNamesMatch('Monaco Grand Prix', 'Belgian Grand Prix 2026 — Race Result & Analysis') === false
);

// ── findKbRaceMatch() ──
$kbFixture = [
    [
        'id' => 'race-r01', 'title' => 'Australian Grand Prix 2026 — Race Result & Analysis',
        'tags' => ['type' => 'race', 'round' => 1],
        'content' => 'Driver Alpha won the race, crossing the line in 1:23:06.801 to take the win. '
            . 'Driver Gamma was the most notable mover in the field, advancing five places from tenth to fifth.',
    ],
    ['id' => 'race-r02', 'title' => 'Belgian Grand Prix 2026 — Race Result & Analysis', 'tags' => ['type' => 'race', 'round' => 2]],
    [
        'id' => 'race-r03', 'title' => 'Testland Grand Prix 2026 — Race Result & Analysis',
        'tags' => ['type' => 'race', 'round' => 3],
        'content' => 'Driver Alpha won the race. Driver Beta finished second, 0.427 seconds behind.', // no mover marker
    ],
    [
        'id' => 'race-r04', 'title' => 'Moontown Grand Prix 2026 — Race Result & Analysis',
        'tags' => ['type' => 'race', 'round' => 4],
        'content' => 'Driver Alpha won the race. Driver Delta was a standout performer, climbing six places to ninth.',
    ],
    // Round 5: no race doc at all — only a sprint doc. Round 6: no race OR sprint doc — only a
    // qualifying doc, mirroring the real Hungarian GP 2026 case (race result not yet in the KB,
    // qualifying already is, since quali content is generated ahead of the race writeup).
    [
        'id' => 'sprint-r05', 'title' => 'Rivertown Grand Prix 2026 — Sprint Race',
        'tags' => ['type' => 'sprint', 'round' => 5],
        'content' => 'Driver Alpha won the sprint. Driver Epsilon was the largest net gainer, moving four places to sixth.',
    ],
    [
        'id' => 'quali-r06', 'title' => 'Lakeside Grand Prix 2026 — Qualifying',
        'tags' => ['type' => 'qualifying', 'round' => 6],
        'content' => 'Driver Alpha claimed pole. Driver Zeta delivered a notable result, qualifying eighth for a backmarker team.',
    ],
    // Rounds 7-8 reuse real, currently-translated KB sentences (see kbHighlightTranslationsDa())
    // as fixture content — needed to test getRecapHighlight()'s $lang='da' gating end-to-end
    // without adding fake entries to the real translation dictionary (see the tests below).
    [
        'id' => 'race-r07', 'title' => 'Seaside Grand Prix 2026 — Race Result & Analysis',
        'tags' => ['type' => 'race', 'round' => 7],
        'content' => 'Driver Alpha won the race. Driver Beta finished second.', // no marker — irrelevant, analysis wins first
    ],
    // Analysis title cleans (cleanKbTitle()) to exactly a real, translated dictionary entry.
    ['id' => 'analysis-r07', 'title' => "F1TECH: Racing Bulls' crew executes the fastest pit stop in Montreal (F1Technical)", 'tags' => ['type' => 'analysis', 'round' => 7]],
    [
        'id' => 'race-r08', 'title' => 'Harbourtown Grand Prix 2026 — Race Result & Analysis',
        'tags' => ['type' => 'race', 'round' => 8],
        // A real, translated dictionary entry (round 6's real sentence, reused here as fixture content).
        'content' => 'Driver Alpha won the race. Isack Hadjar made the largest standings gain, rising four places to eighth on 29 points.',
    ],
    // Untranslated analysis title for round 8 — must be skipped in favor of the (translated) race doc.
    ['id' => 'analysis-r08', 'title' => 'F1MATHS: A brand new headline not yet translated (F1Technical)', 'tags' => ['type' => 'analysis', 'round' => 8]],
    ['id' => 'driver-x', 'title' => 'Australian driver profile', 'tags' => ['type' => 'driver']], // same word, wrong type — must be ignored
    ['id' => 'analysis-r01-a', 'title' => 'F1MATHS: A boxplot story (F1Technical)', 'tags' => ['type' => 'analysis', 'round' => 1]],
    ['id' => 'analysis-r01-b', 'title' => 'F1TECH: An upgrade story (F1Technical)', 'tags' => ['type' => 'analysis', 'round' => 1]],
    ['id' => 'analysis-r03', 'title' => 'F1ANALYSIS: A Testland story (F1Technical)', 'tags' => ['type' => 'analysis', 'round' => 3]],
    ['id' => 'analysis-general', 'title' => 'F1ANALYSIS: Season so far (F1Technical)', 'tags' => ['type' => 'analysis', 'round' => null]],
];
check(
    'findKbDocByType finds a qualifying doc by name, independent of any same-named race/sprint doc',
    (findKbDocByType('Lakeside Grand Prix', $kbFixture, 'qualifying')['id'] ?? null) === 'quali-r06'
);
check(
    'findKbDocByType returns null when no doc of that specific type matches the name',
    findKbDocByType('Lakeside Grand Prix', $kbFixture, 'race') === null
);
check(
    'findKbRaceMatch finds the right race doc by name, ignoring same-word non-race docs',
    (findKbRaceMatch('Australian Grand Prix', $kbFixture)['id'] ?? null) === 'race-r01'
);
check(
    'findKbRaceMatch returns null when no race doc matches',
    findKbRaceMatch('Japanese Grand Prix', $kbFixture) === null
);

// ── splitIntoSentences() / extractMoverSentence() ──
check(
    'splitIntoSentences: splits on sentence boundaries without breaking on decimal numbers',
    splitIntoSentences('The gap was 0.427 seconds behind. Next sentence starts here.') ===
        ['The gap was 0.427 seconds behind', 'Next sentence starts here.']
);
check(
    'splitIntoSentences: a lap time like 1:23:06.801 is never treated as a sentence boundary',
    count(splitIntoSentences('Winner crossed the line in 1:23:06.801 to take the win. Second was 0.427s back.')) === 2
);

$moverContent = 'Season 2026, Round 1: Testland Grand Prix. Driver Alpha won the race, crossing the '
    . 'line in 1:23:06.801 to take the win. Driver Beta finished second, 0.427 seconds behind. '
    . 'Driver Gamma was the most notable mover in the field, advancing five places from tenth to '
    . 'fifth. Driver Delta retired on lap 12.';
check(
    'extractMoverSentence: finds the sentence carrying a mover marker, skipping boilerplate/decimals',
    extractMoverSentence($moverContent) === 'Driver Gamma was the most notable mover in the field, advancing five places from tenth to fifth.'
);
check(
    'extractMoverSentence: null when no sentence carries any marker',
    extractMoverSentence('Season 2026, Round 1: Testland Grand Prix. Driver Alpha won the race. Driver Beta finished second.') === null
);
// Round 6-style case: no marker in the "grid movers" paragraph, but a later
// "largest standings gain" sentence still counts — extraction isn't paragraph-position-bound.
$standingsOnlyContent = 'Grid position data was unavailable for this round, preventing analysis. '
    . 'In the standings, Driver Epsilon made the largest standings gain, rising four places to eighth.';
check(
    'extractMoverSentence: a standings-gain sentence in a later paragraph is still found',
    extractMoverSentence($standingsOnlyContent) === 'In the standings, Driver Epsilon made the largest standings gain, rising four places to eighth.'
);

// ── findKbAnalysisForRound() ──
check(
    'findKbAnalysisForRound finds an analysis doc for a covered round',
    in_array(findKbAnalysisForRound(1, $kbFixture)['id'] ?? null, ['analysis-r01-a', 'analysis-r01-b'], true)
);
check(
    'findKbAnalysisForRound returns null for an uncovered round',
    findKbAnalysisForRound(2, $kbFixture) === null
);
check(
    'findKbAnalysisForRound never matches a null-round (season-wide) doc',
    findKbAnalysisForRound(0, $kbFixture) === null
);

// ── cleanKbTitle() ──
check('cleanKbTitle strips "F1MATHS: " and "(F1Technical)"', cleanKbTitle('F1MATHS: Does X confirm Y? (F1Technical)') === 'Does X confirm Y?');
check('cleanKbTitle strips "F1TECH: "', cleanKbTitle('F1TECH: Ferrari bring upgrades (F1Technical)') === 'Ferrari bring upgrades');
check('cleanKbTitle strips "F1ANALYSIS: "', cleanKbTitle('F1ANALYSIS: Why did X happen (F1Technical)') === 'Why did X happen');
check('cleanKbTitle handles the no-space-before-colon variant too', cleanKbTitle('F1 MATHS: Some question (F1Technical)') === 'Some question');
check('cleanKbTitle leaves an already-clean title untouched (just trimmed)', cleanKbTitle('  A plain headline  ') === 'A plain headline');

// ── translateKbHighlight() ──
check(
    'translateKbHighlight: returns the Danish translation for a real, currently-translated KB sentence',
    translateKbHighlight("Racing Bulls' crew executes the fastest pit stop in Montreal") ===
        "Racing Bulls' mekanikere udførte det hurtigste pitstop i Montreal"
);
check(
    'translateKbHighlight: null for text with no translation entry yet (new/untopped-up KB content)',
    translateKbHighlight('This sentence does not exist anywhere in the KB.') === null
);

// ── computeBettingUpset() ──
$bets5 = [
    ['p1' => 'driverA', 'p2' => 'driverB', 'p3' => 'driverC'],
    ['p1' => 'driverA', 'p2' => 'driverC', 'p3' => 'driverB'],
    ['p1' => 'driverA', 'p2' => 'driverB', 'p3' => 'driverD'],
    ['p1' => 'driverA', 'p2' => 'driverD', 'p3' => 'driverB'],
    ['p1' => 'driverA', 'p2' => 'driverB', 'p3' => 'driverC'],
];
check(
    'computeBettingUpset: fewer than 2 bets → null',
    computeBettingUpset([$bets5[0]], 'driverA', 'driverB', 'driverC') === null
);
check(
    'computeBettingUpset: incomplete podium (race not fully classified) → null',
    computeBettingUpset($bets5, 'driverA', 'driverB', null) === null
);

// driverX finished P1 but nobody backed them at all → clear surprise.
$surprise = computeBettingUpset($bets5, 'driverX', 'driverB', 'driverC');
check('computeBettingUpset: a 0-picks podium finisher is a surprise', ($surprise['type'] ?? null) === 'surprise');
check('computeBettingUpset: surprise reports the right driver/position/count', $surprise['driver_id'] === 'driverX' && $surprise['position'] === 1 && $surprise['count'] === 0);

// driverA was backed by all 3 bettors (always in p1) but actually finished outside the podium
// → snub. Each actual podium finisher (E/F/G) is picked twice out of 3 — evenly enough that no
// single one is a stand-out "surprise" (2 is not < total/2 = 1.5), so this exercises the snub
// branch specifically, not surprise-again.
$snubBets = [
    ['p1' => 'driverA', 'p2' => 'driverE', 'p3' => 'driverF'],
    ['p1' => 'driverA', 'p2' => 'driverF', 'p3' => 'driverG'],
    ['p1' => 'driverA', 'p2' => 'driverE', 'p3' => 'driverG'],
];
$snub = computeBettingUpset($snubBets, 'driverE', 'driverF', 'driverG');
check('computeBettingUpset: a heavily-backed non-podium driver is a snub', ($snub['type'] ?? null) === 'snub');
check('computeBettingUpset: snub reports the right driver/count, no position (unknown)', $snub['driver_id'] === 'driverA' && $snub['count'] === 3 && !array_key_exists('position', $snub));

// Everyone picked the actual podium fairly evenly — nothing notable to report.
$boringBets = [
    ['p1' => 'driverA', 'p2' => 'driverB', 'p3' => 'driverC'],
    ['p1' => 'driverA', 'p2' => 'driverB', 'p3' => 'driverC'],
];
check('computeBettingUpset: a fully-expected result → null (nothing notable)', computeBettingUpset($boringBets, 'driverA', 'driverB', 'driverC') === null);

// ── parseKbJson() (NFR-703-style degrade-cleanly guarantee) ──
check('parseKbJson: null (file_get_contents() returning false) → []', parseKbJson(null) === []);
check('parseKbJson: empty string → []', parseKbJson('') === []);
check('parseKbJson: malformed JSON → []', parseKbJson('{not valid json') === []);
check('parseKbJson: valid JSON object (not a list) → []', parseKbJson('{"a":1}') === []);
check('parseKbJson: well-formed doc array round-trips', parseKbJson('[{"id":"x"}]') === [['id' => 'x']]);

// ── getRecapHighlight() — thin orchestrator, full 6-tier priority chain ──
// Tier order: analysis doc → race-doc sentence → sprint-doc sentence → qualifying-doc sentence
// → betting upset → nothing. $kbFixture's rounds 1/3/4/5/6 are built so each round isolates
// exactly one link of that chain (see the fixture's own comments above).
$driversById = [
    'driverX' => ['id' => 'driverX', 'name' => 'Driver X'],
    'driverB' => ['id' => 'driverB', 'name' => 'Driver B'],
    'driverC' => ['id' => 'driverC', 'name' => 'Driver C'],
];
$podium = ['result_p1' => 'driverX', 'result_p2' => 'driverB', 'result_p3' => 'driverC'];

$raceRound1 = ['name' => 'Australian Grand Prix'] + $podium;
$resultR1 = getRecapHighlight($raceRound1, $bets5, $driversById, $kbFixture);
check(
    'getRecapHighlight: an analysis doc for the round wins even though the race doc also has a mover sentence',
    ($resultR1['text'] ?? null) === 'A boxplot story'
);
check('getRecapHighlight: an analysis-doc result is tagged source=analysis', ($resultR1['source'] ?? null) === 'analysis');

$raceRound3 = ['name' => 'Testland Grand Prix'] + $podium;
check(
    'getRecapHighlight: an analysis doc wins when the race doc itself has no mover sentence too',
    (getRecapHighlight($raceRound3, $bets5, $driversById, $kbFixture)['text'] ?? null) === 'A Testland story'
);

$raceRound4 = ['name' => 'Moontown Grand Prix'] + $podium;
$resultR4 = getRecapHighlight($raceRound4, $bets5, $driversById, $kbFixture);
check(
    'getRecapHighlight: falls back to the race-doc mover sentence when no analysis doc covers the round',
    ($resultR4['text'] ?? null) === 'Driver Delta was a standout performer, climbing six places to ninth.'
);
check('getRecapHighlight: a race-doc result is tagged source=race', ($resultR4['source'] ?? null) === 'race');

$raceRound5 = ['name' => 'Rivertown Grand Prix'] + $podium;
$resultR5 = getRecapHighlight($raceRound5, $bets5, $driversById, $kbFixture);
check(
    'getRecapHighlight: falls back to a sprint-doc sentence when there is no analysis or race doc for the round',
    ($resultR5['text'] ?? null) === 'Driver Epsilon was the largest net gainer, moving four places to sixth.'
);
check('getRecapHighlight: a sprint-doc result is tagged source=sprint', ($resultR5['source'] ?? null) === 'sprint');

// Qualifying is tried LAST, after even the betting upset (see the file header for why: unlike
// every other KB tier, it describes the PRELIMINARY session for the very race already shown in
// the same slide, so it's the tier most likely to visually conflict with the shown podium — the
// real Hungarian GP 2026 case, where the fix landed after labeling it "Qualifying" alone still
// read as wrong). $boringBetsXBC exactly matches the actual podium — a fully-expected result, so
// computeBettingUpset() returns null and the chain correctly falls through to qualifying.
$boringBetsXBC = [
    ['p1' => 'driverX', 'p2' => 'driverB', 'p3' => 'driverC'],
    ['p1' => 'driverX', 'p2' => 'driverB', 'p3' => 'driverC'],
];
$raceRound6 = ['name' => 'Lakeside Grand Prix'] + $podium;
$resultR6 = getRecapHighlight($raceRound6, $boringBetsXBC, $driversById, $kbFixture);
check(
    'getRecapHighlight: falls back to a qualifying-doc sentence only once a betting upset is also unavailable (the real Hungarian GP 2026 case)',
    ($resultR6['text'] ?? null) === 'Driver Zeta delivered a notable result, qualifying eighth for a backmarker team.'
);
check('getRecapHighlight: a qualifying-doc result is tagged source=qualifying', ($resultR6['source'] ?? null) === 'qualifying');

check(
    'getRecapHighlight: a betting upset wins over a qualifying-doc sentence when both are available — the actual Hungary GP fix (qualifying is a last resort, not tier 4)',
    (getRecapHighlight($raceRound6, $bets5, $driversById, $kbFixture)['type'] ?? null) === 'surprise'
);

// ── recapHighlightLabelKey() — the actual bug fix: qualifying/sprint content describes a
// DIFFERENT session than the race result shown in the same slide, so it must not share the
// generic "Insight" label used for race-outcome content (that's what made the real Hungarian GP
// slide read as contradicting its own podium — see home-recap.php's file header).
check('recapHighlightLabelKey: null highlight → generic label', recapHighlightLabelKey(null) === 'home_recap_insight_label');
check('recapHighlightLabelKey: analysis-sourced kb result → generic label', recapHighlightLabelKey($resultR1) === 'home_recap_insight_label');
check('recapHighlightLabelKey: race-sourced kb result → generic label', recapHighlightLabelKey($resultR4) === 'home_recap_insight_label');
check('recapHighlightLabelKey: sprint-sourced kb result → sprint label', recapHighlightLabelKey($resultR5) === 'home_recap_insight_label_sprint');
check('recapHighlightLabelKey: qualifying-sourced kb result → qualifying label', recapHighlightLabelKey($resultR6) === 'home_recap_insight_label_qualifying');
check(
    'recapHighlightLabelKey: a betting-upset result (no "source" key at all) → generic label',
    recapHighlightLabelKey(['type' => 'surprise', 'driver_id' => 'driverX']) === 'home_recap_insight_label'
);

// ── getRecapHighlight() — $lang='da' translation gating ──
// A KB candidate with no entry in kbHighlightTranslationsDa() must be skipped when $lang='da',
// exactly as if that doc/sentence didn't exist — never shown untranslated to a Danish visitor.
// getRecapHighlight() always returns the English source text — $lang only gates *whether* a
// candidate is selected at all; translating the selected text for display is the caller's job
// (buildRecapHighlightText() in index.php). These checks confirm both halves of that contract:
// the right (English) candidate is selected, and it does translate cleanly via
// translateKbHighlight() — i.e. exactly what the caller does with it.
$raceRound7 = ['name' => 'Seaside Grand Prix'] + $podium;
$resultR7da = getRecapHighlight($raceRound7, $bets5, $driversById, $kbFixture, 'da');
check(
    'getRecapHighlight: lang=da — a translated analysis headline is selected (English source text returned)',
    ($resultR7da['text'] ?? null) === "Racing Bulls' crew executes the fastest pit stop in Montreal"
);
check(
    'getRecapHighlight: lang=da — the selected analysis text does translate cleanly',
    translateKbHighlight($resultR7da['text'] ?? '') === "Racing Bulls' mekanikere udførte det hurtigste pitstop i Montreal"
);

$raceRound8 = ['name' => 'Harbourtown Grand Prix'] + $podium;
$resultR8da = getRecapHighlight($raceRound8, $bets5, $driversById, $kbFixture, 'da');
check(
    'getRecapHighlight: lang=da — an untranslated analysis headline is skipped in favor of the translated race-doc sentence',
    ($resultR8da['text'] ?? null) === 'Isack Hadjar made the largest standings gain, rising four places to eighth on 29 points.'
);
check(
    'getRecapHighlight: lang=da — that selected race-doc sentence does translate cleanly',
    translateKbHighlight($resultR8da['text'] ?? '') === 'Isack Hadjar rykkede mest frem i stillingen og steg fire pladser til en ottendeplads med 29 point.'
);

check(
    'getRecapHighlight: lang=da — an untranslated race-doc sentence with no other KB source falls through to a betting upset, not English',
    (getRecapHighlight($raceRound4, $bets5, $driversById, $kbFixture, 'da')['type'] ?? null) === 'surprise'
);

$raceNoKb = ['name' => 'Unmatched Grand Prix'] + $podium;
check(
    'getRecapHighlight: falls back to a betting upset when there is no KB match of any kind',
    (getRecapHighlight($raceNoKb, $bets5, $driversById, $kbFixture)['type'] ?? null) === 'surprise'
);
check(
    'getRecapHighlight: null when neither tier applies',
    getRecapHighlight($raceNoKb, [], $driversById, $kbFixture) === null
);
check(
    'getRecapHighlight: an upset pointing at an unresolvable driver id is discarded, not surfaced broken',
    getRecapHighlight($raceNoKb, $bets5, [], $kbFixture) === null
);

if ($fails > 0) {
    echo "\n$fails check(s) FAILED\n";
    exit(1);
}
echo "\nAll checks passed.\n";
exit(0);
