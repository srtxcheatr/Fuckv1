<?php
// api/user/init.php — POST, called once right after Firebase Auth
// signup or Google sign-in succeeds. Creates the user's Firestore
// doc with safe defaults if it doesn't exist yet.
//
// This replaces the old client-side setDoc() calls in script.js —
// with Firestore rules now denying all direct client access, the
// browser can no longer create this document itself.

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

$db = firestore();
$userRef = $db->collection('users')->document($uid);
$snap = $userRef->snapshot();

if (!$snap->exists()) {
  $userRef->set([
    'email' => $email,
    'profileName' => '',
    'profilePhone' => '',
    'requestStatus' => 'Active',
    'adminMessage' => 'Welcome! Pay via eSewa or Balance to get your key 🔑',
    'balance' => 0,
    'purchaseHistory' => [],
    'apiKeys' => [],
  ], ['merge' => true]);
} elseif ($email !== '' && ($snap->data()['email'] ?? '') !== $email) {
  $userRef->set(['email' => $email], ['merge' => true]);
}

echo json_encode(['success' => true]);
