<?php
// api/user/topup.php — POST, submits a top-up request (the user
// claims they paid via eSewa and gives a transaction code; an admin
// verifies it later and approves the balance credit through
// adjust-balance.php). Replaces the client-side updateDoc(...,
// { topupRequests: arrayUnion(...) }) call in window.submitTopup().
//
// This does NOT touch balance directly — it only records a pending
// claim for a human admin to review, exactly like before.

require __DIR__ . '/../../src/firebase.php';

apply_cors();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['success' => false, 'error' => 'Method not allowed']);
  exit;
}

$uid = require_firebase_uid();
$email = firebase_email();

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$amount = (int)($body['amount'] ?? 0);
$esewaId = trim((string)($body['esewaId'] ?? ''));
$txCode = strtoupper(trim((string)($body['txCode'] ?? '')));

if ($amount < 50) {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'Enter a valid amount']);
  exit;
}
if ($esewaId === '' || $txCode === '') {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'eSewa ID and transaction code are required']);
  exit;
}

$db = firestore();
$userRef = $db->collection('users')->document($uid);

try {
  $result = $db->runTransaction(function ($transaction) use ($userRef, $uid, $email, $amount, $esewaId, $txCode) {
    $snapshot = $transaction->snapshot($userRef);
    $existing = $snapshot->exists() ? ($snapshot->data()['topupRequests'] ?? []) : [];

    foreach ($existing as $t) {
      if (isset($t['txCode']) && strtoupper($t['txCode']) === $txCode) {
        throw new \RuntimeException('This transaction ID was already submitted');
      }
    }

    $entry = [
      'date' => date('c'),
      'amount' => $amount,
      'esewaId' => $esewaId,
      'txCode' => $txCode,
      'status' => 'PENDING',
      'uid' => $uid,
      'email' => $email,
    ];
    $existing[] = $entry;

    $transaction->set($userRef, ['topupRequests' => $existing], ['merge' => true]);
    return $entry;
  });

  echo json_encode(['success' => true, 'request' => $result]);
} catch (\RuntimeException $e) {
  http_response_code(409);
  echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
