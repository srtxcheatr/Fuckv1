<?php
// api/user/balance.php — GET, returns the caller's own state in one
// call: balance, admin notice, account status, and saved profile
// fields. The frontend polls this on an interval instead of using a
// live Firestore listener, since the browser no longer has direct
// read access to Firestore at all.

require __DIR__ . '/../../src/firebase.php';

apply_cors();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  http_response_code(405);
  echo json_encode(['success' => false, 'error' => 'Method not allowed']);
  exit;
}

$uid = require_firebase_uid();
$email = firebase_email();

$db = firestore();
$userRef = $db->collection('users')->document($uid);
$snap = $userRef->snapshot();

if (!$snap->exists()) {
  // Same defaults as init.php — covers a client that polls balance
  // before ever calling init (e.g. a returning session).
  $defaults = [
    'email' => $email,
    'profileName' => '',
    'profilePhone' => '',
    'requestStatus' => 'Active',
    'adminMessage' => 'Welcome! Pay via eSewa or Balance to get your key 🔑',
    'balance' => 0,
    'purchaseHistory' => [],
    'apiKeys' => [],
  ];
  $userRef->set($defaults, ['merge' => true]);
  $data = $defaults;
} else {
  $data = $snap->data();
}

echo json_encode([
  'success' => true,
  'balance' => (int)($data['balance'] ?? 0),
  'adminMessage' => (string)($data['adminMessage'] ?? ''),
  'requestStatus' => (string)($data['requestStatus'] ?? 'Active'),
  'profileName' => (string)($data['profileName'] ?? ''),
  'profilePhone' => (string)($data['profilePhone'] ?? ''),
  'email' => (string)($data['email'] ?? $email),
]);
