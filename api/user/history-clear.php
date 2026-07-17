<?php
// api/user/history-clear.php — POST, wipes the caller's own purchase
// history. Replaces the client-side updateDoc(..., { history:
// deleteField() }) call in window.processHistoryDelete(). Operates
// on `purchaseHistory` — the real, backend-managed ledger — rather
// than the old client-only `history` field it used to touch.

require __DIR__ . '/../../src/firebase.php';

apply_cors();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['success' => false, 'error' => 'Method not allowed']);
  exit;
}

$uid = require_firebase_uid();

$db = firestore();
$db->collection('users')->document($uid)->set([
  'purchaseHistory' => [],
], ['merge' => true]);

echo json_encode(['success' => true]);
