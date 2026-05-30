<?php
// Import des participants Aude, Alice, Marie, Cécile depuis l'ancien
// sondage Framadate (https://beta.framadate.org/polls/9486ec5e108fe91cec98)
// vers le sondage existant sur cette instance.
//
// Idempotent : si un email est déjà présent dans le sondage, le participant
// est laissé tel quel (pas de doublon, pas d'écrasement des votes).
//
// Usage :   php migrate_from_frama.php [poll-uuid]
//   par défaut : b8e3a7f1-2d94-4c6a-9e5b-3f1a2c8d7e60

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    echo "Run from CLI only.\n";
    exit(1);
}

$GLOBALS['CONFIG'] = require __DIR__ . '/config.php';
require __DIR__ . '/lib/db.php';

$poll_uuid = $argv[1] ?? 'b8e3a7f1-2d94-4c6a-9e5b-3f1a2c8d7e60';

$pdo = db();
$stmt = $pdo->prepare("SELECT id, title FROM polls WHERE uuid = ?");
$stmt->execute([$poll_uuid]);
$poll = $stmt->fetch();
if (!$poll) {
    fwrite(STDERR, "Sondage introuvable pour uuid=$poll_uuid\n");
    exit(1);
}
$poll_id = (int)$poll['id'];
echo "Sondage cible : \"{$poll['title']}\" (id={$poll_id})\n";

// Construit la map [day][label] => choice_id à partir de la structure existante.
$rows = $pdo->prepare("
    SELECT c.id AS choice_id, c.label, d.day
    FROM poll_choices c
    JOIN poll_dates d ON d.id = c.date_id
    WHERE d.poll_id = ?
");
$rows->execute([$poll_id]);
$choice_of = [];
foreach ($rows as $r) {
    $choice_of[$r['day']][$r['label']] = (int)$r['choice_id'];
}

$participants = [
    ['name' => 'Aude',   'email' => 'aude@example.com'],
    ['name' => 'Alice',  'email' => 'alice@example.com'],
    ['name' => 'Marie',  'email' => 'marie@example.com'],
    ['name' => 'Cécile', 'email' => 'cecile@example.com'],
];

$votes = require __DIR__ . '/migrate_from_frama_votes.php';

$check  = $pdo->prepare("SELECT id FROM participants WHERE poll_id = ? AND email = ?");
$ins_p  = $pdo->prepare("INSERT INTO participants (poll_id, email, name, created_at) VALUES (?, ?, ?, ?)");
$ins_v  = $pdo->prepare("INSERT INTO votes (participant_id, choice_id, value) VALUES (?, ?, ?)");

$totals = ['created' => 0, 'skipped' => 0, 'votes_inserted' => 0, 'votes_skipped' => 0];

foreach ($participants as $p) {
    $check->execute([$poll_id, $p['email']]);
    if ($check->fetch()) {
        echo "  - {$p['name']} <{$p['email']}> : déjà présent, skip\n";
        $totals['skipped']++;
        continue;
    }

    $pdo->beginTransaction();
    $ins_p->execute([$poll_id, $p['email'], $p['name'], time()]);
    $pid = (int)$pdo->lastInsertId();

    $count_in = 0;
    $count_miss = 0;
    foreach (($votes[$p['name']] ?? []) as $day => $slot_vals) {
        foreach ($slot_vals as $label => $val) {
            if ($val === null) continue;
            $cid = $choice_of[$day][$label] ?? null;
            if ($cid === null) {
                $count_miss++;
                continue;
            }
            $ins_v->execute([$pid, $cid, $val]);
            $count_in++;
        }
    }
    $pdo->commit();
    $totals['created']++;
    $totals['votes_inserted'] += $count_in;
    $totals['votes_skipped']  += $count_miss;
    echo "  + {$p['name']} <{$p['email']}> : créé (id=$pid), {$count_in} votes inscrits"
       . ($count_miss > 0 ? ", {$count_miss} sans correspondance" : "") . "\n";
}

echo "\nRésumé :\n";
echo "  participants créés       : {$totals['created']}\n";
echo "  participants déjà là     : {$totals['skipped']}\n";
echo "  votes insérés            : {$totals['votes_inserted']}\n";
if ($totals['votes_skipped'] > 0) {
    echo "  votes sans correspondance: {$totals['votes_skipped']}\n";
    echo "  (vérifie que les libellés des créneaux correspondent : Journée / Soirée / Nuit)\n";
}
