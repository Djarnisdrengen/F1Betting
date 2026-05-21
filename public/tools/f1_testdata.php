<?php
/**
 * f1_testdata.php
 * Stub F1 data for E2E cron tests — only the seeded Australian GP test race.
 * Keeping this to one entry prevents the cron test from touching real races in the DB.
 */

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

return [
    'MRData' => [
        'RaceTable' => [
            'Races' => [
                [
                    'raceName' => 'Australian Grand Prix',
                    'date' => '2026-03-08',
                    'round' => 1,
                    'QualifyingResults' => [
                        ['Driver' => ['givenName' => 'Lewis',   'familyName' => 'Hamilton',  'code' => 'HAM', 'permanentNumber' => 44]],
                        ['Driver' => ['givenName' => 'Max',     'familyName' => 'Verstappen', 'code' => 'VER', 'permanentNumber' => 33]],
                        ['Driver' => ['givenName' => 'Charles', 'familyName' => 'Leclerc',   'code' => 'LEC', 'permanentNumber' => 16]],
                    ],
                ],
            ],
        ],
    ],
];
