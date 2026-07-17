<?php
// api/user/keys.php — manages the caller's own API keys (the ones
// used against the separate srtxcheats.web.app/api/keys lookup
// service). Replaces every direct Firestore read/write around the
// `apiKeys` field in script.js (createApiKey, revokeApiKey,
// deleteApiKey, and the full-page equivalents — they all pointed at
// the same field, so one endpoint covers all of them).
//
// GET                       -> list the caller's keys
// POST { action: "generate" } -> create a new key (max 3 active)
// POST { action: "revoke", key } -> mark a key inactive
// POST { action: "delete",  key } -> remove a key entirely

require __DIR__ . '/../../src/firebase.php';

apply_cors();
header('Content-Type: application/json');

$uid = require_firebase_uid();
$db = firestore();
$userRef = $db->collection('users')->document($uid);

function current_keys($userRef): array {
  $snap = $userRef->snapshot();
  return $snap->exists() ? ($snap->data()['apiKeys'] ?? []) : [];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  echo json_encode(['success' => true, 'apiKeys' => current_keys($userRef)]);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['success' => false, 'error' => 'Method not allowed']);
  exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$action = (string)($body['action'] ?? '');

if ($action === 'generate') {
  try {
    $updated = $db->runTransaction(function ($transaction) use ($userRef) {
      $snapshot = $transaction->snapshot($userRef);
      $keys = $snapshot->exists() ? ($snapshot->data()['apiKeys'] ?? []) : [];

      $activeCount = count(array_filter($keys, fn($k) => !empty($k['active'])));
      if ($activeCount >= 3) {
        throw new \RuntimeException('Max 3 active keys allowed. Revoke one first.');
      }

      $newKey = [
        'key' => 'srtx_' . bin2hex(random_bytes(20)),
        'createdAt' => date('c'),
        'active' => true,
      ];
      $keys[] = $newKey;

      $transaction->set($userRef, ['apiKeys' => $keys], ['merge' => true]);
      return $keys;
    });
    echo json_encode(['success' => true, 'apiKeys' => $updated]);
  } catch (\RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
  }
  exit;
}

if ($action === 'revoke' || $action === 'delete') {
  $keyStr = (string)($body['key'] ?? '');
  if ($keyStr === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing key']);
    exit;
  }
  $updated = $db->runTransaction(function ($transaction) use ($userRef, $keyStr, $action) {
    $snapshot = $transaction->snapshot($userRef);
    $keys = $snapshot->exists() ? ($snapshot->data()['apiKeys'] ?? []) : [];

    if ($action === 'revoke') {
      $keys = array_map(fn($k) => $k['key'] === $keyStr ? [...$k, 'active' => false] : $k, $keys);
    } else {
      $keys = array_values(array_filter($keys, fn($k) => $k['key'] !== $keyStr));
    }

    $transaction->set($userRef, ['apiKeys' => $keys], ['merge' => true]);
    return $keys;
  });
  echo json_encode(['success' => true, 'apiKeys' => $updated]);
  exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Unknown action']);
