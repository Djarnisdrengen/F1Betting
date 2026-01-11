<?php
require_once __DIR__ . '/../config.php';

$currentUser = getCurrentUser();
if (!$currentUser) {
    header('Location: login.php');
    exit;
}

$settings = getSettings();
$pointsP1 = $settings['points_p1'] ?? 25;
$pointsP2 = $settings['points_p2'] ?? 18;
$pointsP3 = $settings['points_p3'] ?? 15;
$pointsWrongPos = $settings['points_wrong_pos'] ?? 5;
$bettingWindowHours = $settings['betting_window_hours'] ?? 48;

include __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="page-header mb-2">
        <h1><i class="fas fa-book text-accent"></i> <?= $lang === 'da' ? 'Spilleregler' : 'Betting Rules' ?></h1>
    </div>
    
    <div class="rules-container">
        <!-- Betting Window -->
        <div class="card mb-2">
            <div class="card-header">
                <h3><i class="fas fa-clock text-accent"></i> <?= $lang === 'da' ? 'Betting Vindue' : 'Betting Window' ?></h3>
            </div>
            <div class="card-body">
                <table class="rules-table">
                    <tr>
                        <td><strong><?= $lang === 'da' ? 'Åbner' : 'Opens' ?></strong></td>
                        <td><?= $lang === 'da' ? $bettingWindowHours . ' timer før løbets starttid' : $bettingWindowHours . ' hours before race start time' ?></td>
                    </tr>
                    <tr>
                        <td><strong><?= $lang === 'da' ? 'Lukker' : 'Closes' ?></strong></td>
                        <td><?= $lang === 'da' ? 'Ved løbets starttid' : 'At race start time' ?></td>
                    </tr>
                    <tr>
                        <td><strong><?= $lang === 'da' ? 'Rediger' : 'Edit' ?></strong></td>
                        <td><?= $lang === 'da' ? 'Bets kan redigeres så længe vinduet er åbent' : 'Bets can be edited while window is open' ?></td>
                    </tr>
                </table>
            </div>
        </div>
        
        <!-- Points System -->
        <div class="card mb-2">
            <div class="card-header">
                <h3><i class="fas fa-star text-accent"></i> <?= $lang === 'da' ? 'Point System' : 'Points System' ?></h3>
            </div>
            <div class="card-body">
                <table class="rules-table">
                    <thead>
                        <tr>
                            <th><?= $lang === 'da' ? 'Position' : 'Position' ?></th>
                            <th><?= $lang === 'da' ? 'Korrekt Forudsigelse' : 'Correct Prediction' ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><span class="position-badge position-1">P1</span></td>
                            <td><strong><?= $pointsP1 ?> <?= $lang === 'da' ? 'point' : 'points' ?></strong></td>
                        </tr>
                        <tr>
                            <td><span class="position-badge position-2">P2</span></td>
                            <td><strong><?= $pointsP2 ?> <?= $lang === 'da' ? 'point' : 'points' ?></strong></td>
                        </tr>
                        <tr>
                            <td><span class="position-badge position-3">P3</span></td>
                            <td><strong><?= $pointsP3 ?> <?= $lang === 'da' ? 'point' : 'points' ?></strong></td>
                        </tr>
                        <tr>
                            <td><i class="fas fa-plus-circle text-accent"></i> <strong>Bonus</strong></td>
                            <td>+<?= $pointsWrongPos ?> <?= $lang === 'da' ? 'point hvis kører er i top 3, men forkert position' : 'points if driver is in top 3 but wrong position' ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Stars -->
        <div class="card mb-2">
            <div class="card-header">
                <h3><span class="star">★</span> <?= $lang === 'da' ? 'Stjerner' : 'Stars' ?></h3>
            </div>
            <div class="card-body">
                <table class="rules-table">
                    <tr>
                        <td><strong><?= $lang === 'da' ? 'Perfekt bet' : 'Perfect bet' ?></strong></td>
                        <td><?= $lang === 'da' ? 'Hvis alle 3 positioner er korrekte, modtager du' : 'If all 3 positions are correct, you receive' ?> <span class="star">★</span> 1 <?= $lang === 'da' ? 'stjerne' : 'star' ?></td>
                    </tr>
                </table>
            </div>
        </div>
        
        <!-- Restrictions -->
        <div class="card mb-2">
            <div class="card-header">
                <h3><i class="fas fa-ban text-accent"></i> <?= $lang === 'da' ? 'Restriktioner' : 'Restrictions' ?></h3>
            </div>
            <div class="card-body">
                <table class="rules-table">
                    <tr>
                        <td><strong><?= $lang === 'da' ? 'Én bet per løb' : 'One bet per race' ?></strong></td>
                        <td><?= $lang === 'da' ? 'Hver bruger kan kun have ét bet per løb' : 'Each user can only have one bet per race' ?></td>
                    </tr>
                    <tr>
                        <td><strong><?= $lang === 'da' ? 'Ingen duplikater' : 'No duplicates' ?></strong></td>
                        <td><?= $lang === 'da' ? 'Samme kører kan ikke vælges flere gange i ét bet' : 'Same driver cannot be selected multiple times in one bet' ?></td>
                    </tr>
                    <tr>
                        <td><strong><?= $lang === 'da' ? 'Unik kombination' : 'Unique combination' ?></strong></td>
                        <td><?= $lang === 'da' ? 'To brugere kan ikke have identisk P1/P2/P3 kombination' : 'Two users cannot have identical P1/P2/P3 combination' ?></td>
                    </tr>
                    <tr>
                        <td><strong><?= $lang === 'da' ? 'Ikke kvalifikation' : 'Not qualifying' ?></strong></td>
                        <td><?= $lang === 'da' ? 'Bet kan ikke matche kvalifikationsresultatet 100%' : 'Bet cannot match qualifying result 100%' ?></td>
                    </tr>
                </table>
            </div>
        </div>
        
        <!-- Example -->
        <div class="card mb-2">
            <div class="card-header">
                <h3><i class="fas fa-lightbulb text-accent"></i> <?= $lang === 'da' ? 'Eksempel' : 'Example' ?></h3>
            </div>
            <div class="card-body">
                <div class="example-box">
                    <p><strong><?= $lang === 'da' ? 'Løbsresultat' : 'Race Result' ?>:</strong> P1 = Verstappen, P2 = Norris, P3 = Leclerc</p>
                </div>
                
                <div class="example-scenario mt-2">
                    <h4><?= $lang === 'da' ? 'Scenarie 1' : 'Scenario 1' ?>:</h4>
                    <p><strong><?= $lang === 'da' ? 'Dit bet' : 'Your bet' ?>:</strong> P1 = Verstappen, P2 = Leclerc, P3 = Norris</p>
                    <ul class="example-calc">
                        <li><span class="text-accent">✓</span> P1 <?= $lang === 'da' ? 'korrekt' : 'correct' ?>: <strong>+<?= $pointsP1 ?> <?= $lang === 'da' ? 'point' : 'points' ?></strong></li>
                        <li><span class="text-muted">○</span> P2 <?= $lang === 'da' ? 'forkert, men Leclerc i top 3' : 'wrong, but Leclerc in top 3' ?>: <strong>+<?= $pointsWrongPos ?> <?= $lang === 'da' ? 'point' : 'points' ?></strong></li>
                        <li><span class="text-muted">○</span> P3 <?= $lang === 'da' ? 'forkert, men Norris i top 3' : 'wrong, but Norris in top 3' ?>: <strong>+<?= $pointsWrongPos ?> <?= $lang === 'da' ? 'point' : 'points' ?></strong></li>
                        <li class="total"><strong>Total: <?= $pointsP1 + $pointsWrongPos + $pointsWrongPos ?> <?= $lang === 'da' ? 'point' : 'points' ?></strong></li>
                    </ul>
                </div>
                
                <div class="example-scenario mt-2">
                    <h4><?= $lang === 'da' ? 'Scenarie 2 (Perfekt!)' : 'Scenario 2 (Perfect!)' ?> <span class="star">★</span></h4>
                    <p><strong><?= $lang === 'da' ? 'Dit bet' : 'Your bet' ?>:</strong> P1 = Verstappen, P2 = Norris, P3 = Leclerc</p>
                    <ul class="example-calc">
                        <li><span class="text-accent">✓</span> P1 <?= $lang === 'da' ? 'korrekt' : 'correct' ?>: <strong>+<?= $pointsP1 ?> <?= $lang === 'da' ? 'point' : 'points' ?></strong></li>
                        <li><span class="text-accent">✓</span> P2 <?= $lang === 'da' ? 'korrekt' : 'correct' ?>: <strong>+<?= $pointsP2 ?> <?= $lang === 'da' ? 'point' : 'points' ?></strong></li>
                        <li><span class="text-accent">✓</span> P3 <?= $lang === 'da' ? 'korrekt' : 'correct' ?>: <strong>+<?= $pointsP3 ?> <?= $lang === 'da' ? 'point' : 'points' ?></strong></li>
                        <li class="total"><strong>Total: <?= $pointsP1 + $pointsP2 + $pointsP3 ?> <?= $lang === 'da' ? 'point' : 'points' ?> + <span class="star">★</span> 1 <?= $lang === 'da' ? 'stjerne' : 'star' ?></strong></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
