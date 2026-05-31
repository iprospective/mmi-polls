<?php
// Service Votes : map des votes par participant et par choice.
require_once __DIR__ . '/../lib/db.php';

function poll_votes_map(int $poll_id): array {
    $stmt = db()->prepare("
        SELECT v.participant_id, v.choice_id, v.value
        FROM votes v
        JOIN participants p ON p.id = v.participant_id
        WHERE p.poll_id = ?
    ");
    $stmt->execute([$poll_id]);
    $map = [];
    foreach ($stmt as $r) {
        $map[(int)$r['participant_id']][(int)$r['choice_id']] = $r['value'];
    }
    return $map;
}
