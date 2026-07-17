<?php
// api/admin/lookup.php
// GET https://<backend>/api/admin/lookup.php?uid=...
// Header: X-Admin-Secret: <your ADMIN_SECRET>

require __DIR__ . '/../../src/firebase.php';

apply_admin_cors();
header('Content-Type: application/json');
require_admin();

$uid = trim((string)($_GET['uid'] ?? ''));
if ($uid === '') {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'Provide a uid']);
  exit;
}

$db = firestore();
$snap = $db->collection('users')->document($uid)->snapshot();

if (!$snap->exists()) {
  echo json_encode([
    'success' => true, 'uid' => $uid, 'found' => false,
    'balance' => 0, 'email' => '', 'adminLog' => [], 'purchases' => [],
  ]);
  exit;
}

$data = $snap->data();
$adminLog = array_reverse($data['adminLog'] ?? []);
$purchases = array_reverse($data['purchaseHistory'] ?? []);

echo json_encode([
  'success' => true, 'uid' => $uid, 'found' => true,
  'balance' => (int)($data['balance'] ?? 0),
  'email' => $data['email'] ?? '',
  'adminLog' => array_slice($adminLog, 0, 50),
  'purchases' => array_slice($purchases, 0, 50),
]);
