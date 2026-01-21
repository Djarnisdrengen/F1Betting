<?php
/**
 * f1_testdata.php
 * Structured test data for F1 Qualifying Results Import (24 races, 3 drivers each)
 */

return [
    'MRData' => [
        'RaceTable' => [
            'Races' => [
                // 1. Australian GP
                [
                    'raceName' => 'Australian Grand Prix',
                    'date' => '2026-03-08',
                    'round' => 1,
                    'QualifyingResults' => [
                        ['Driver' => ['givenName'=>'Lewis','familyName'=>'Hamilton','code'=>'HAM','permanentNumber'=>44]],
                        ['Driver' => ['givenName'=>'Max','familyName'=>'Verstappen','code'=>'VER','permanentNumber'=>33]],
                        ['Driver' => ['givenName'=>'Charles','familyName'=>'Leclerc','code'=>'LEC','permanentNumber'=>16]],
                    ]
                ],
                // 2. Chinese GP
                [
                    'raceName' => 'Chinese Grand Prix',
                    'date' => '2026-03-15',
                    'round' => 2,
                    'QualifyingResults' => [
                        ['Driver' => ['givenName'=>'Carlos','familyName'=>'Sainz','code'=>'SAI','permanentNumber'=>55]],
                        ['Driver' => ['givenName'=>'Lando','familyName'=>'Norris','code'=>'NOR','permanentNumber'=>4]],
                        ['Driver' => ['givenName'=>'Valtteri','familyName'=>'Bottas','code'=>'BOT','permanentNumber'=>77]],
                    ]
                ],
                // 3. Japanese GP
                [
                    'raceName' => 'Japanese Grand Prix',
                    'date' => '2026-03-29',
                    'round' => 3,
                    'QualifyingResults' => [
                        ['Driver' => ['givenName'=>'Pierre','familyName'=>'Gasly','code'=>'GAS','permanentNumber'=>10]],
                        ['Driver' => ['givenName'=>'Franco','familyName'=>'Colapinto','code'=>'COL','permanentNumber'=>43]],
                        ['Driver' => ['givenName'=>'Sergio','familyName'=>'Perez','code'=>'PER','permanentNumber'=>11]],
                    ]
                ],
                // 4. Bahrain GP
                [
                    'raceName' => 'Bahrain Grand Prix',
                    'date' => '2026-04-12',
                    'round' => 4,
                    'QualifyingResults' => [
                        ['Driver' => ['givenName'=>'Lance','familyName'=>'Stroll','code'=>'STR','permanentNumber'=>18]],
                        ['Driver' => ['givenName'=>'Nico','familyName'=>'Hulkenberg','code'=>'HUL','permanentNumber'=>27]],
                        ['Driver' => ['givenName'=>'Gabriel','familyName'=>'Bortoletto','code'=>'BOR','permanentNumber'=>5]],
                    ]
                ],
                // 5. Saudi Arabian GP
                [
                    'raceName' => 'Saudi Arabian Grand Prix',
                    'date' => '2026-04-19',
                    'round' => 5,
                    'QualifyingResults' => [
                        ['Driver' => ['givenName'=>'Valtteri','familyName'=>'Bottas','code'=>'BOT','permanentNumber'=>77]],
                        ['Driver' => ['givenName'=>'Esteban','familyName'=>'Ocon','code'=>'OCO','permanentNumber'=>31]],
                        ['Driver' => ['givenName'=>'Oliver','familyName'=>'Bearman','code'=>'BEA','permanentNumber'=>87]],
                    ]
                ],
                // 6. Miami GP
                [
                    'raceName' => 'Miami Grand Prix',
                    'date' => '2026-05-03',
                    'round' => 6,
                    'QualifyingResults' => [
                        ['Driver' => ['givenName'=>'Oscar','familyName'=>'Piastri','code'=>'PIA','permanentNumber'=>81]],
                        ['Driver' => ['givenName'=>'Kimi','familyName'=>'Antonelli','code'=>'ANT','permanentNumber'=>12]],
                        ['Driver' => ['givenName'=>'Liam','familyName'=>'Lawson','code'=>'LAW','permanentNumber'=>30]],
                    ]
                ],                
                // 7. Canadian GP
                [
                    'raceName' => 'Canadian Grand Prix',
                    'date' => '2026-05-24',
                    'round' => 7,
                    'QualifyingResults' => [
                        ['Driver' => ['givenName'=>'Arvid','familyName'=>'Lindblad','code'=>'LIN','permanentNumber'=>41]],
                        ['Driver' => ['givenName'=>'Isack','familyName'=>'Hadjar','code'=>'HAD','permanentNumber'=>6]],
                        ['Driver' => ['givenName'=>'Alexander','familyName'=>'Albon','code'=>'ALB','permanentNumber'=>23]],
                    ]
                ],
                // 8. Monaco GP
                [
                    'raceName' => 'Monaco Grand Prix',
                    'date' => '2026-06-07',
                    'round' => 8,
                    'QualifyingResults' => [
                        ['Driver' => ['givenName'=>'Charles','familyName'=>'Leclerc','code'=>'LEC','permanentNumber'=>16]],
                        ['Driver' => ['givenName'=>'Max','familyName'=>'Verstappen','code'=>'VER','permanentNumber'=>33]],
                        ['Driver' => ['givenName'=>'Lewis','familyName'=>'Hamilton','code'=>'HAM','permanentNumber'=>44]],
                    ]
                ],
                 // 9. Barcelona GP
                [
                    'raceName' => 'Barcelona Grand Prix',
                    'date' => '2026-06-14',
                    'round' => 9,
                    'QualifyingResults' => [
                        ['Driver' => ['givenName'=>'Sergio','familyName'=>'Perez','code'=>'PER','permanentNumber'=>11]],
                        ['Driver' => ['givenName'=>'Fernando','familyName'=>'Alonso','code'=>'ALO','permanentNumber'=>14]],
                        ['Driver' => ['givenName'=>'Carlos','familyName'=>'Sainz','code'=>'SAI','permanentNumber'=>55]],
                    ]
                ],
                // 10. Austrian GP
                [
                    'raceName' => 'Austrian Grand Prix',
                    'date' => '2026-06-28',
                    'round' => 10,
                    'QualifyingResults' => [
                        ['Driver' => ['givenName'=>'Charles','familyName'=>'Leclerc','code'=>'LEC','permanentNumber'=>16]],
                        ['Driver' => ['givenName'=>'Lando','familyName'=>'Norris','code'=>'NOR','permanentNumber'=>4]],
                        ['Driver' => ['givenName'=>'Fernando','familyName'=>'Alonso','code'=>'ALO','permanentNumber'=>14]],
                    ]
                ],
                // 11. British GP
                [
                    'raceName' => 'British Grand Prix',
                    'date' => '2026-07-05',
                    'round' => 11,
                    'QualifyingResults' => [
                        ['Driver' => ['givenName'=>'Lewis','familyName'=>'Hamilton','code'=>'HAM','permanentNumber'=>44]],
                        ['Driver' => ['givenName'=>'Max','familyName'=>'Verstappen','code'=>'VER','permanentNumber'=>33]],
                        ['Driver' => ['givenName'=>'Carlos','familyName'=>'Sainz','code'=>'SAI','permanentNumber'=>55]],
                    ]
                ],
                // 12. Belgian GP
                [
                    'raceName' => 'Belgian Grand Prix',
                    'date' => '2026-07-19',
                    'round' => 12,
                    'QualifyingResults' => [
                        ['Driver' => ['givenName'=>'Lewis','familyName'=>'Hamilton','code'=>'HAM','permanentNumber'=>44]],
                        ['Driver' => ['givenName'=>'Sergio','familyName'=>'Perez','code'=>'PER','permanentNumber'=>11]],
                        ['Driver' => ['givenName'=>'Fernando','familyName'=>'Alonso','code'=>'ALO','permanentNumber'=>14]],
                    ]
                ],
                // 13. Hungarian GP
                [
                    'raceName' => 'Hungarian Grand Prix',
                    'date' => '2026-07-26',
                    'round' => 13,
                    'QualifyingResults' => [
                        ['Driver' => ['givenName'=>'George','familyName'=>'Russell','code'=>'RUS','permanentNumber'=>63]],
                        ['Driver' => ['givenName'=>'Charles','familyName'=>'Leclerc','code'=>'LEC','permanentNumber'=>16]],
                        ['Driver' => ['givenName'=>'Max','familyName'=>'Verstappen','code'=>'VER','permanentNumber'=>33]],
                    ]
                ],
                // 14. Dutch GP
                [
                    'raceName' => 'Dutch Grand Prix',
                    'date' => '2026-08-23',
                    'round' => 14,
                    'QualifyingResults' => [
                        ['Driver' => ['givenName'=>'Max','familyName'=>'Verstappen','code'=>'VER','permanentNumber'=>33]],
                        ['Driver' => ['givenName'=>'Charles','familyName'=>'Leclerc','code'=>'LEC','permanentNumber'=>16]],
                        ['Driver' => ['givenName'=>'Lando','familyName'=>'Norris','code'=>'NOR','permanentNumber'=>4]],
                    ]
                ],
                // 15. Italian GP
                [
                    'raceName' => 'Italian Grand Prix',
                    'date' => '2026-09-06',
                    'round' => 15,
                    'QualifyingResults' => [
                        ['Driver' => ['givenName'=>'Carlos','familyName'=>'Sainz','code'=>'SAI','permanentNumber'=>55]],
                        ['Driver' => ['givenName'=>'Lewis','familyName'=>'Hamilton','code'=>'HAM','permanentNumber'=>44]],
                        ['Driver' => ['givenName'=>'George','familyName'=>'Russell','code'=>'RUS','permanentNumber'=>63]],
                    ]
                ],
                // 16. Spanish GP 
                [
                    'raceName' => 'Spanish Grand Prix',
                    'date' => '2026-09-13',
                    'round' => 16,
                    'QualifyingResults' => [
                        ['Driver' => ['givenName'=>'Sergio','familyName'=>'Perez','code'=>'PER','permanentNumber'=>11]],
                        ['Driver' => ['givenName'=>'Fernando','familyName'=>'Alonso','code'=>'ALO','permanentNumber'=>14]],
                        ['Driver' => ['givenName'=>'Carlos','familyName'=>'Sainz','code'=>'SAI','permanentNumber'=>55]],
                    ]
                ],
                // 17. Azerbaijan GP
                [
                    'raceName' => 'Azerbaijan Grand Prix',
                    'date' => '2026-09-26',
                    'round' => 17,
                    'QualifyingResults' => [
                        ['Driver' => ['givenName'=>'Max','familyName'=>'Verstappen','code'=>'VER','permanentNumber'=>33]],
                        ['Driver' => ['givenName'=>'Lewis','familyName'=>'Hamilton','code'=>'HAM','permanentNumber'=>44]],
                        ['Driver' => ['givenName'=>'Sergio','familyName'=>'Perez','code'=>'PER','permanentNumber'=>11]],
                    ]
                ],
                // 18. Singapore GP 2
                [
                    'raceName' => 'Singapore Grand Prix 2',
                    'date' => '2026-10-11',
                    'round' => 18,
                    'QualifyingResults' => [
                        ['Driver' => ['givenName'=>'Charles','familyName'=>'Leclerc','code'=>'LEC','permanentNumber'=>16]],
                        ['Driver' => ['givenName'=>'Carlos','familyName'=>'Sainz','code'=>'SAI','permanentNumber'=>55]],
                        ['Driver' => ['givenName'=>'George','familyName'=>'Russell','code'=>'RUS','permanentNumber'=>63]],
                    ]
                ],                
                // 19. United States GP
                [
                    'raceName' => 'United States Grand Prix',
                    'date' => '2026-10-25',
                    'round' => 19,
                    'QualifyingResults' => [
                        ['Driver' => ['givenName'=>'Max','familyName'=>'Verstappen','code'=>'VER','permanentNumber'=>33]],
                        ['Driver' => ['givenName'=>'George','familyName'=>'Russell','code'=>'RUS','permanentNumber'=>63]],
                        ['Driver' => ['givenName'=>'Charles','familyName'=>'Leclerc','code'=>'LEC','permanentNumber'=>16]],
                    ]
                ],
                // 20. Mexico GP
                [
                    'raceName' => 'Mexican Grand Prix',
                    'date' => '2026-11-01',
                    'round' => 20,
                    'QualifyingResults' => [
                        ['Driver' => ['givenName'=>'Sergio','familyName'=>'Perez','code'=>'PER','permanentNumber'=>11]],
                        ['Driver' => ['givenName'=>'Lewis','familyName'=>'Hamilton','code'=>'HAM','permanentNumber'=>44]],
                        ['Driver' => ['givenName'=>'Fernando','familyName'=>'Alonso','code'=>'ALO','permanentNumber'=>14]],
                    ]
                ],
                // 21. Brazilian GP
                [
                    'raceName' => 'Brazilian Grand Prix',
                    'date' => '2026-11-08',
                    'round' => 21,
                    'QualifyingResults' => [
                        ['Driver' => ['givenName'=>'Charles','familyName'=>'Leclerc','code'=>'LEC','permanentNumber'=>16]],
                        ['Driver' => ['givenName'=>'Max','familyName'=>'Verstappen','code'=>'VER','permanentNumber'=>33]],
                        ['Driver' => ['givenName'=>'Carlos','familyName'=>'Sainz','code'=>'SAI','permanentNumber'=>55]],
                    ]
                ],
                // 22. Las Vegas GP
                [
                    'raceName' => 'Las Vegas Grand Prix',
                    'date' => '2026-11-21',
                    'round' => 22,
                    'QualifyingResults' => [
                        ['Driver' => ['givenName'=>'Sergio','familyName'=>'Perez','code'=>'PER','permanentNumber'=>11]],
                        ['Driver' => ['givenName'=>'Lewis','familyName'=>'Hamilton','code'=>'HAM','permanentNumber'=>44]],
                        ['Driver' => ['givenName'=>'Fernando','familyName'=>'Alonso','code'=>'ALO','permanentNumber'=>14]],
                    ]
                ],
                // 23. Qatar GP
                [
                    'raceName' => 'Qatar Grand Prix',
                    'date' => '2026-11-29',
                    'round' => 23,
                    'QualifyingResults' => [
                        ['Driver' => ['givenName'=>'Charles','familyName'=>'Leclerc','code'=>'LEC','permanentNumber'=>16]],
                        ['Driver' => ['givenName'=>'Max','familyName'=>'Verstappen','code'=>'VER','permanentNumber'=>33]],
                        ['Driver' => ['givenName'=>'Carlos','familyName'=>'Sainz','code'=>'SAI','permanentNumber'=>55]],
                    ]
                ],
                // 24. Abu Dhabi GP
                [
                    'raceName' => 'Abu Dhabi Grand Prix',
                    'date' => '2026-12-06',
                    'round' => 24,
                    'QualifyingResults' => [
                        ['Driver' => ['givenName'=>'Lewis','familyName'=>'Hamilton','code'=>'HAM','permanentNumber'=>44]],
                        ['Driver' => ['givenName'=>'George','familyName'=>'Russell','code'=>'RUS','permanentNumber'=>63]],
                        ['Driver' => ['givenName'=>'Lando','familyName'=>'Norris','code'=>'NOR','permanentNumber'=>4]],
                    ]
                ]
            ]
        ]
    ]
];
