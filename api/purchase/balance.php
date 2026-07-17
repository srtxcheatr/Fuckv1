<?php
// api/purchase/balance.php — POST, the actual checkout endpoint.
//
// This implements exactly the security model described: the backend
// is the only source of truth for price and balance. Nothing the
// client sends about price is trusted — the real price is looked up
// server-side from src/catalog.php, and the balance check + deduction
// happens atomically in a Firestore transaction, so a tampered
// frontend (devtools price edits, replayed requests, etc.) can never
// result in an under-priced or double-spent purchase.
//
// What this file does NOT do: actually fetch/deliver the product key
// from your reseller. That call is a stub below — plug in your
// existing implementation there. Everything around it (auth, price
// authority, balance check, atomic deduction, history, notifications)
// is complete and wired up.

require __DIR__ . '/../../src/firebase.php';
require __DIR__ . '/../../src/catalog.php';
require __DIR__ . '/../../src/telegram.php';

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
$sku = (string)($body['sku'] ?? '');
$buyerName = trim((string)($body['name'] ?? ''));
$buyerWa = trim((string)($body['waNum'] ?? ''));

// ---- 1. Real price authority: look up the product server-side. ----
// The client's own idea of the price/name/duration is never used —
// only $sku is trusted, and everything else is re-derived from here.
$product = catalog_find($sku);
if ($product === null) {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'Unknown product']);
  exit;
}
$realPrice = (int)$product['price'];

$db = firestore();
$userRef = $db->collection('users')->document($uid);

telegram_notify(telegram_format('Purchase attempt', [
  'username' => $buyerName ?: $email, 'email' => $email, 'product' => $product['name'],
  'price' => $realPrice, 'uid' => $uid, 'status' => 'attempt',
]));

try {
  // ---- 2. Balance check + deduction, atomically. ----
  // Reading and writing inside one transaction is what makes this
  // safe against double-submits / race conditions — two simultaneous
  // requests can't both read "sufficient balance" and both succeed.
  $result = $db->runTransaction(function ($transaction) use ($userRef, $realPrice, $product, $sku, $uid, $email, $buyerName, $buyerWa) {
    $snapshot = $transaction->snapshot($userRef);
    $currentBalance = $snapshot->exists() ? (int)($snapshot->data()['balance'] ?? 0) : 0;

    if ($currentBalance < $realPrice) {
      throw new \RuntimeException('Insufficient balance');
    }

    // ---- 3. Fetch/deliver the actual product key. ----
    // This is the one piece intentionally left for you to fill in —
    // your reseller call, however it works. It must return the key
    // string, or throw on failure (which rolls back — no balance is
    // deducted if this fails).
    $key = fetch_real_key($sku, $product);

    $newBalance = $currentBalance - $realPrice;
    $historyEntry = [
      'at' => date('c'),
      'name' => $product['name'],
      'duration' => $product['duration'],
      'price' => $realPrice,
      'key' => $key,
      'buyerName' => $buyerName,
      'buyerWa' => $buyerWa,
    ];
    $purchaseHistory = $snapshot->exists() ? ($snapshot->data()['purchaseHistory'] ?? []) : [];
    $purchaseHistory[] = $historyEntry;

    $transaction->set($userRef, [
      'balance' => $newBalance,
      'purchaseHistory' => $purchaseHistory,
    ], ['merge' => true]);

    return ['key' => $key, 'newBalance' => $newBalance];
  });

  telegram_notify(telegram_format('Purchase success', [
    'username' => $buyerName ?: $email, 'email' => $email, 'product' => $product['name'],
    'price' => $realPrice, 'uid' => $uid, 'status' => 'success',
  ]));

  echo json_encode([
    'success' => true,
    'key' => $result['key'],
    'newBalance' => $result['newBalance'],
  ]);
} catch (\RuntimeException $e) {
  telegram_notify(telegram_format('Purchase rejected', [
    'username' => $buyerName ?: $email, 'email' => $email, 'product' => $product['name'],
    'price' => $realPrice, 'uid' => $uid, 'status' => 'failed', 'others' => $e->getMessage(),
  ]));
  http_response_code(402);
  echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

/**
 * STUB — not implemented here.
 *
 * Plug in your actual reseller/key-fetch call. It receives the sku
 * and the full catalog entry (pid, row, name, duration, price), and
 * must either return the key string on success or throw an exception
 * on failure (which cancels the whole transaction — no balance gets
 * deducted, no history entry gets written).
 */
function fetch_real_key(string $sku, array $product): string {
  throw new \RuntimeException('fetch_real_key() is not implemented — plug in your reseller call here.');
}
