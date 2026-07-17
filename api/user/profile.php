<?php
// api/user/profile.php — POST, saves the caller's display name and
// WhatsApp/phone number. Replaces the client-side updateDoc() call
// in window.saveProfile().

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
$name = trim((string)($body['name'] ?? ''));
$phone = trim((string)($body['phone'] ?? ''));

if ($name === '' || $phone === '') {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'Please fill both fields']);
  exit;
}
if (mb_strlen($name) > 60 || mb_strlen($phone) > 30) {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'Name or phone is too long']);
  exit;
}

$db = firestore();
$db->collection('users')->document($uid)->set([
  'profileName' => $name,
  'profilePhone' => $phone,
  'name' => $name,
  'whatsapp' => $phone,
  'email' => $email,
], ['merge' => true]);

echo json_encode(['success' => true]);
