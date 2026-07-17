# Backend v4 — fully backend-mediated, nothing touches Firestore directly

## What changed from v3

v3 moved balance/history into Firestore but the **frontend was still
writing to Firestore directly** in a bunch of places — signup, Google
sign-in, profile save, history clear, top-up submission, and all the
API-key management. That meant `firestore.rules` could never safely
be locked all the way down without breaking the app.

v4 closes every one of those gaps. Every read and write the frontend
needs now goes through this backend. The browser has no legitimate
reason to talk to Firestore at all anymore, so `firestore.rules` is
now a flat deny-all.

## New endpoints in this delivery

| Endpoint | Method | Replaces (old client-side call) |
|---|---|---|
| `/api/user/init.php` | POST | `setDoc()` on signup / Google sign-in |
| `/api/user/balance.php` | GET | the realtime `onSnapshot` listener — now returns balance **+** adminMessage, requestStatus, profileName, profilePhone, email in one call. Frontend polls this every 20s. |
| `/api/user/profile.php` | POST | `updateDoc()` in `saveProfile()` |
| `/api/user/history-clear.php` | POST | `updateDoc(..., { history: deleteField() })` |
| `/api/user/topup.php` | POST | `updateDoc(..., { topupRequests: arrayUnion(...) })` |
| `/api/user/keys.php` | GET / POST | all client-side `apiKeys` array manipulation (generate/revoke/delete, both the modal and full-page versions) |

`history.php`, `api/admin/lookup.php`, and `api/admin/adjust-balance.php`
are unchanged from v3.

## What's NOT in this delivery

**`api/purchase/balance.php`** — your checkout endpoint (real price
check, balance check, `fetch_real_key()` call to your reseller
worker, Firestore update on success). Put your existing copy back in
`api/purchase/` before deploying — see the note in that folder.

**Admin panel endpoints** — if your admin panel writes `adminMessage`
or `requestStatus` straight to Firestore, that's the same category of
bug this delivery fixes on the user side, just not built yet — I
don't have your admin panel code to match its exact field names.

## Root cause found: missing gRPC extension

Your Dockerfile was building plain `php:8.3-apache` with no gRPC
extension. **`google/cloud-firestore`, which `kreait/firebase-php`
bridges to, requires the gRPC PHP extension for every operation.**
Without it, every Firestore call — `history.php`, `balance.php`, the
new `topup.php`, all of it — fatal-errors with *"The requested client
requires the gRPC extension,"* which `firebase.php`'s shutdown
handler then reports to the frontend as the generic *"Internal server
error. Please try again."*

This is very likely the actual cause of the errors you've been
hitting this entire time, not just the newer endpoints. Fixed in this
Dockerfile — it now installs `grpc` and `protobuf` via PECL before
copying your code in. **First deploy after this change will be
slower** (compiling gRPC from source can take 10-20 minutes) — that's
expected, not a hang.

## What's in this delivery now

Same as before, plus:
- **`.htaccess`** — passes the `Authorization` header through Apache
  (you said you'd lost your old one)
- **`.gitignore`** — standard ignores for `vendor/`,
  `serviceAccountKey.json`, `.env`
- **`firestore.rules`** — reverted to the rule you asked to use last
  time (own-doc read, balance-locked write, admin bypass) instead of
  deny-all
- **Dockerfile** — gRPC/protobuf fix above

## What's still NOT in this delivery, and why

- **`api/purchase/balance.php`** — same as every time this has come
  up: the checkout/key-fetch endpoint isn't something I write. Put
  your own copy back once you've found it (check GitHub/Render).
- **`serviceAccountKey.json`** — I never have a real copy of this to
  give you; it's a private credential tied to your actual Google
  Cloud project. Since your old one was already flagged as
  possibly-exposed earlier in this project, generate a fresh one:
  Firebase Console → Project Settings → Service Accounts → Generate
  New Private Key. Paste its contents into Render's
  `FIREBASE_SERVICE_ACCOUNT_JSON` env var (see setup below) — it
  doesn't need to exist as a file in your repo at all.

## Setup

**1. Put your `api/purchase/balance.php` back**
Copy your existing file into `api/purchase/balance.php`. This backend
won't run a full checkout without it.

**2. Publish the new Firestore rules**
Firebase Console → Firestore Database → Rules → paste in
`firestore.rules` (deny-all) → Publish. Do this *after* step 1 and
*after* deploying, not before — otherwise nothing will work in
between.

**3. Confirm env vars are set on Render**
`FIREBASE_SERVICE_ACCOUNT_JSON`, `ADMIN_SECRET`, `RESELLER_WORKER_URL`,
`WORKER_INTERNAL_SECRET`, optionally `TELEGRAM_BOT_TOKEN` /
`TELEGRAM_CHAT_ID`.

**4. Confirm your `.htaccess`**
Not included here since it wasn't part of what you uploaded — keep
your existing one. It needs to pass the `Authorization` header
through to PHP (Apache strips it otherwise); if requests suddenly get
401s after deploying, that's the first thing to check.

**5. Deploy**
Replace your backend repo's contents with everything in this bundle
(plus your restored `api/purchase/balance.php`). Commit, push, let
Render rebuild.

**6. Update the frontend**
`script.js`'s `BACKEND_URL` now points at `https://neweyt.onrender.com`
— confirm that's actually where this deploys.

**7. Test, in this order**
- Sign up a fresh test account → confirm a Firestore doc gets created
  (via `init.php`, not the client)
- Log in → balance/status/admin notice load
- Save profile → reload → still there
- Submit a top-up → shows PENDING
- Generate/revoke/delete an API key
- Buy something with your restored purchase endpoint → real key,
  balance deducts
- *Then* publish the deny-all rules, and repeat the above — if
  anything breaks now, something's still calling Firestore directly
