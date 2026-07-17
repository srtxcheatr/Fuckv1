<?php
// api/admin/adjust-balance.php
// Header: X-Admin-Secret: <your ADMIN_SECRET>
// Body:   { "uid": "...", "amount": 500, "direction": "add", "note": "..." }

require __DIR__ . '/../../src/firebase.php';

apply_admin_cors();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['success' => false, 'error' => 'Method not allowed']);
  exit;
}

require_admin();

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$uid = trim((string)($body['uid'] ?? ''));
$amount = (int)($body['amount'] ?? 0);
$direction = (string)($body['direction'] ?? 'add');
$note = trim((string)($body['note'] ?? ''));

if ($uid === '' || $amount <= 0 || $amount > 1000000) {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'Provide a uid and a positive amount']);
  exit;
}
if (!in_array($direction, ['add', 'deduct'], true)) {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'direction must be "add" or "deduct"']);
  exit;
}

$db = firestore();
$userRef = $db->collection('users')->document($uid);

$outcome = $db->runTransaction(function ($transaction) use ($userRef, $amount, $direction, $note) {
  $snapshot = $transaction->snapshot($userRef);
  $data = $snapshot->exists() ? $snapshot->data() : ['balance' => 0, 'purchaseHistory' => [], 'adminLog' => []];

  $delta = $direction === 'add' ? $amount : -$amount;
  $newBalance = (int)($data['balance'] ?? 0) + $delta;

  $log = $data['adminLog'] ?? [];
  $log[] = [
    'delta' => $delta,
    'note' => $note !== '' ? $note : 'Manual ' . $direction,
    'resultingBalance' => $newBalance,
    'at' => date('c'),
  ];

  $transaction->set($userRef, [
    'balance' => $newBalance,
    'adminLog' => $log,
  ], ['merge' => true]);

  return $newBalance;
});

echo json_encode(['success' => true, 'newBalance' => $outcome]);
