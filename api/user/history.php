<?php
// api/user/history.php — GET, returns the caller's own purchase
// history from Firestore, most recent first.

require __DIR__ . '/../../src/firebase.php';

apply_cors();
header('Content-Type: application/json');

$uid = require_firebase_uid();

$db = firestore();
$snap = $db->collection('users')->document($uid)->snapshot();
$purchases = $snap->exists() ? ($snap->data()['purchaseHistory'] ?? []) : [];

echo json_encode(['success' => true, 'history' => array_reverse($purchases)]);
