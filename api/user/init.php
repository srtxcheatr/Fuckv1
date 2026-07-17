<?php
// JSON रेस्पोन्सका लागि Header सेट गर्ने
header('Content-Type: application/json');

require_once __DIR__ . '/../../vendor/autoload.php';

use Kreait\Firebase\Factory;

// १. Firebase Connection & Environment Setup
$serviceAccountJson = getenv('FIREBASE_SERVICE_ACCOUNT_JSON');

if (!$serviceAccountJson) {
    die(json_encode(["error" => "Firebase configuration missing on server."]));
}

$serviceAccount = json_decode($serviceAccountJson, true);

// Auth र Firestore को Setup
$factory = (new Factory)->withServiceAccount($serviceAccount);
$auth = $factory->createAuth();
$firestore = $factory->createFirestore();
$db = $firestore->database();

// २. Request बाट User को Authorization Bearer Token तान्ने र UID निकाल्ने
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    $idToken = $matches[1];
    try {
        // Client-side ले पठाएको Token Verify गर्ने
        $verifiedIdToken = $auth->verifyIdToken($idToken);
        $uid = $verifiedIdToken->claims()->get('sub');
        $email = $verifiedIdToken->claims()->get('email') ?? '';
    } catch (\Exception $e) {
        die(json_encode(["error" => "Invalid or expired token.", "details" => $e->getMessage()]));
    }
} else {
    die(json_encode(["error" => "Authorization token required."]));
}

// ३. Firestore मा User Check गर्ने र नभए Create/Update गर्ने
try {
    $userRef = $db->collection('users')->document($uid);
    $snap = $userRef->snapshot();

    if (!$snap->exists()) {
        // नयाँ युजर भए Firestore मा डाटा सेट गर्ने
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
        // ईमेल अपडेट भएको भए मात्र अपडेट गर्ने
        $userRef->set(['email' => $email], ['merge' => true]);
    }

    echo json_encode(['success' => true]);

} catch (\Exception $e) {
    echo json_encode(["error" => "Database operation failed.", "details" => $e->getMessage()]);
}
?>
