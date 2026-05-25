<?php
// Seed: recrée le sondage exemple (Framadate 9486ec5e108fe91cec98).
// Usage: php seed.php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    echo "Run from CLI only.\n";
    exit(1);
}

$GLOBALS['CONFIG'] = require __DIR__ . '/config.php';
require __DIR__ . '/lib/db.php';

$pdo = db();

$uuid = 'b8e3a7f1-2d94-4c6a-9e5b-3f1a2c8d7e60';

$title = "SOS aide Anna & Mathou pour accouchement";
$description = <<<TXT
On a besoin de faire une liste d'amis proches qui seraient OK pour nous emmener à la maternité de Romans au moment de l'accouchement.

Si personne de nos proches n'est dispo, on peut appeler le 15, mais on est pas sûr et certain qu'ils seront dispo vite et qu'ils voudront bien nous emmener à Romans (selon la situation ils pourraient nous emmener à Valence, et j'ai vraiment envie d'accoucher à Romans pour plusieurs raisons, c'est important pour moi...). Donc l'idéal serait de se faire emmener par qqun de proche.

Pour rappel le terme est prévu au 21 juin, mais je peux accoucher entre 1 mois avant et 1 semaine après, donc entre le 21 mai et le 26 juin, c'est bébé qui décidera !

L'idée c'est de noter ici TOUTES VOS DISPOS.

Puis, pour éviter que tout le monde se rendre dispo souvent, on essayera de choisir 2 PERSONNES "D'ASTREINTE" PAR CRENEAU, ou sur des petites périodes. Idéalement 1 personne principale qu'on appellera en 1er, et 1 personne suppléante qu'on appellera en 2ème. On vous enverra cette info quand tout le monde aura remplit ce frama.

Un très GRAND MERCI de votre soutien les copains :)
TXT;

$existing = $pdo->prepare("SELECT uuid FROM polls WHERE uuid = ?");
$existing->execute([$uuid]);
if ($existing->fetch()) {
    echo "Sondage déjà présent : uuid=$uuid\n";
    echo "Lien public : " . rtrim($GLOBALS['CONFIG']['app_url'], '/') . "/p/$uuid\n";
    exit(0);
}

$pdo->beginTransaction();

$pdo->prepare("INSERT INTO polls (uuid, title, description, created_at) VALUES (?, ?, ?, ?)")
    ->execute([$uuid, $title, $description, time()]);
$poll_id = (int)$pdo->lastInsertId();

// Dates 21/05/2026 → 26/06/2026, 3 créneaux chacun.
$start = new DateTimeImmutable('2026-05-21');
$end   = new DateTimeImmutable('2026-06-26');
$slots = ['Journée', 'Soirée', 'Nuit'];
$choice_ids = []; // [yyyy-mm-dd][slot-key] => choice_id
$slot_keys = ['Journée' => 'journee', 'Soirée' => 'soiree', 'Nuit' => 'nuit'];

$ins_date   = $pdo->prepare("INSERT INTO poll_dates (poll_id, day, sort_order) VALUES (?, ?, ?)");
$ins_choice = $pdo->prepare("INSERT INTO poll_choices (date_id, label, sort_order) VALUES (?, ?, ?)");

$order = 0;
for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
    $iso = $d->format('Y-m-d');
    $ins_date->execute([$poll_id, $iso, $order++]);
    $date_id = (int)$pdo->lastInsertId();
    foreach ($slots as $i => $label) {
        $ins_choice->execute([$date_id, $label, $i]);
        $choice_ids[$iso][$slot_keys[$label]] = (int)$pdo->lastInsertId();
    }
}

// Participants & votes (données issues du sondage Framadate original).
$participants = [
    ['key' => 'suzy',   'name' => 'Suzy',   'email' => 'suzy@example.com'],
    ['key' => 'lulu',   'name' => 'Lulu',   'email' => 'lulu@example.com'],
    ['key' => 'elodie', 'name' => 'Elodie', 'email' => 'elodie@example.com'],
    ['key' => 'irene',  'name' => 'Irène',  'email' => 'irene@example.com'],
];

$ins_p = $pdo->prepare("INSERT INTO participants (poll_id, email, name, created_at) VALUES (?, ?, ?, ?)");
$ins_v = $pdo->prepare("INSERT INTO votes (participant_id, choice_id, value) VALUES (?, ?, ?)");

$votes = require __DIR__ . '/seed_votes.php';

foreach ($participants as $p) {
    $ins_p->execute([$poll_id, $p['email'], $p['name'], time()]);
    $pid = (int)$pdo->lastInsertId();
    $pv = $votes[$p['key']] ?? [];
    foreach ($pv as $day => $slots_vals) {
        foreach ($slots_vals as $slot => $val) {
            if ($val === null) continue;
            $cid = $choice_ids[$day][$slot] ?? null;
            if ($cid === null) continue;
            $ins_v->execute([$pid, $cid, $val]);
        }
    }
}

$pdo->commit();

echo "Sondage créé.\n";
echo "uuid       : $uuid\n";
echo "Lien public: " . rtrim($GLOBALS['CONFIG']['app_url'], '/') . "/p/$uuid\n";
