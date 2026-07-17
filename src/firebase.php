<?php
// firebase.php — the trust boundary. Verifies WHO is calling, and
// gives access to Firestore with full admin trust (bypassing Security
// Rules — which now deny direct client access entirely, so this
// backend is the ONLY way in or out of your data).
//
// SETUP:
// 1. Firebase Console → Project Settings → Service Accounts →
//    Generate new private key.
// 2. Render → your service → Environment → FIREBASE_SERVICE_ACCOUNT_JSON
//    = the entire contents of that JSON file.

// ------------------------------------------------------------------
// HARDENING — must run before anything else. A PHP warning/notice
// printed into the response is what caused "Unexpected token '<'"
// before; this makes that structurally impossible from here on.
// ------------------------------------------------------------------
ini_set('display_errors', '0');
error_reporting(E_ALL);
ob_start();

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
  error_log("[srtx-backend] $errstr in $errfile:$errline");
  return true;
});

register_shutdown_function(function () {
  $err = error_get_last();
  if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
    error_log('[srtx-backend] FATAL: ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Internal server error. Please try again.']);
  }
});

require __DIR__ . '/../vendor/autoload.php';

use Kreait\Firebase\Factory;

function firebase(): Factory {
  static $factory = null;
  if ($factory === null) {
    $json = getenv('FIREBASE_SERVICE_ACCOUNT_JSON');
    if ($json) {
      $creds = json_decode($json, true);
      if (!$creds) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Server misconfigured: FIREBASE_SERVICE_ACCOUNT_JSON is not valid JSON']);
        exit;
      }
      $factory = (new Factory())->withServiceAccount($creds);
    } else {
      $keyPath = __DIR__ . '/../serviceAccountKey.json';
      if (!file_exists($keyPath)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Server misconfigured: no service account credentials found']);
        exit;
      }
      $factory = (new Factory())->withServiceAccount($keyPath);
    }
  }
  return $factory;
}

function firestore() {
  return firebase()->createFirestore()->database();
}

/**
 * Reads the Authorization header from wherever it actually lands.
 * Apache (and some proxies) strip this header from $_SERVER by
 * default — this checks every place it might have ended up.
 */
function get_bearer_token(): ?string {
  $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
  if ($header === '' && !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
    $header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
  }
  if ($header === '' && function_exists('getallheaders')) {
    foreach (getallheaders() as $name => $value) {
      if (strcasecmp($name, 'Authorization') === 0) {
        $header = $value;
        break;
      }
    }
  }
  if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
    return $m[1];
  }
  return null;
}

$__verifiedClaims = null;

function require_firebase_uid(): string {
  global $__verifiedClaims;
  $token = get_bearer_token();
  if ($token === null) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Missing Authorization header']);
    exit;
  }
  try {
    $verified = firebase()->createAuth()->verifyIdToken($token);
    $__verifiedClaims = $verified->claims();
    return (string)$__verifiedClaims->get('sub');
  } catch (\Throwable $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid or expired login. Please refresh and try again.']);
    exit;
  }
}

function firebase_email(): string {
  global $__verifiedClaims;
  return $__verifiedClaims ? (string)($__verifiedClaims->get('email') ?? '') : '';
}

function require_admin(): void {
  $expected = getenv('ADMIN_SECRET');
  $given = $_SERVER['HTTP_X_ADMIN_SECRET'] ?? '';
  if (!$expected || !hash_equals($expected, $given)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authorized']);
    exit;
  }
}

function apply_admin_cors(): void {
  header('Access-Control-Allow-Origin: *');
  header('Access-Control-Allow-Headers: Content-Type, X-Admin-Secret');
  header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
  if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
  }
}

function apply_cors(): void {
  $allowed = [
    'https://bronzx.web.app',
    'https://bronzx.firebaseapp.com',
    'https://reselle.onrender.com',
  ];
  $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
  if (in_array($origin, $allowed, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
  }
  header('Access-Control-Allow-Headers: Content-Type, Authorization');
  header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
  if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
  }
}
