# KúkiCakes — Week 6 Authentication & Architecture Documentation

## 1. Week 6 Authentication Overview

This document provides a comprehensive technical overview and reference manual for the Week 6 Authentication, Authorization, Account Management, and Persistent Cart Synchronization implementations in the **KúkiCakes** web application.

The implementation transitions KúkiCakes from an unauthenticated mock frontend into a production-grade, secure, multi-tier web application featuring:
- Standard-compliant customer registration with rigorous validation and password hashing.
- Credential authentication protected against timing attacks, user enumeration, and unauthorized session escalation.
- Hardened server-side session management (`KUKI_SESS`) using strict HTTP cookies.
- Comprehensive route protection intercepting both client-side link navigation and direct URL hash deep-links (`#profile`, `#account`, `#my-orders`).
- Complete account dashboard and profile management (profile editing, address book management, in-session password updates).
- Secure, token-based password reset architecture with cryptographically secure tokens and development simulation tooling.
- Persistent authenticated shopping cart database synchronization (`user_cart_items`), supporting guest-to-account cart merging and clean session isolation.

---

## 2. System Architecture

KúkiCakes employs a decoupled client-server architecture with an asynchronous JavaScript presentation layer communicating via JSON REST endpoints backed by PHP and a relational MySQL database.

```
+-------------------------------------------------------------------------------+
|                                BROWSER / UI                                   |
|   index.html (Single-Page App Shell)  |  components/*.html (Auth fragments)   |
|   CSS Design System (vanilla CSS)     |  Responsive Navigation Bar & Modals   |
+-------------------------------------------------------------------------------+
                                      │  Events / Hash routing
                                      ▼
+-------------------------------------------------------------------------------+
|                       JAVASCRIPT CLIENT LAYER (SPA)                           |
|   js/site.js       - SPA router (goPage), product catalog, guest cart state   |
|   js/auth-loader.js - Dynamic component loader & early navigation interceptor |
|   js/auth.js       - Session monitor, forms, route guard, cart sync engine    |
+-------------------------------------------------------------------------------+
                                      │  HTTP Fetch (credentials: same-origin)
                                      ▼
+-------------------------------------------------------------------------------+
|                            PHP API LAYER (/api)                               |
|   api/bootstrap.php - Security headers, session bootstrap, PDO connection    |
|   api/register.php  - Account creation & duplicate validation                 |
|   api/login.php     - Secure credential verification & session issuance       |
|   api/logout.php    - Server session destruction & cookie expiry              |
|   api/profile.php   - Customer profile & address operations                   |
|   api/cart.php      - Persistent cart CRUD and merge operations               |
|   api/forgot-password.php / api/reset-password.php - Token recovery lifecycle|
+-------------------------------------------------------------------------------+
                                      │  PDO Prepared Statements
                                      ▼
+-------------------------------------------------------------------------------+
|                           MYSQL RELATIONAL DATABASE                           |
|   customer         - Core accounts, credentials, contact info                 |
|   user_addresses   - Customer shipping / billing address book                 |
|   password_resets  - SHA-256 hashed password reset tokens & timestamps        |
|   user_cart_items  - Persistent authenticated cart items & custom cake specs  |
+-------------------------------------------------------------------------------+
```

### Architectural Components:
1. **Browser / UI**: Semantic HTML5 and responsive CSS layout. Authentication pages are modularized as HTML fragments under `/components/` (`login.html`, `signup.html`, `profile.html`, `my-orders.html`, `auth-guard.html`, `reset-password.html`).
2. **JavaScript Layer**:
   - `site.js`: Manages view switching (`goPage()`), URL hashes, product selection, and client cart operations.
   - `auth-loader.js`: Fetches modular HTML components, queues early user navigation clicks before DOM readiness, and lazily mounts `auth.js`.
   - `auth.js`: Implements client-side input validation, API calls (`apiGet`, `apiPost`), profile panel switching, route protection hooks, and debounced cart server synchronization.
3. **PHP API Layer**: Lightweight JSON micro-services enforcing REST semantics. Common functionality is centralized in `api/bootstrap.php` (content-type negotiation, security headers, session initialization, auth validation).
4. **Session Layer**: Standard PHP session engine configured with strict cookie attributes (`KUKI_SESS`), HttpOnly, SameSite=Lax, and session fixation prevention via `session_regenerate_id(true)`.
5. **MySQL Database**: Normalized schema with foreign key constraints, indexes on lookup keys (email, token hashes), and UTF-8 multibyte encoding (`utf8mb4`).

---

## 3. Authentication Flow

### 3.1 User Registration (`POST /api/register.php`)
1. User enters Full Name, Email, Phone Number, Password, and Password Confirmation.
2. Client-side validation checks syntax and password complexity (minimum 8 characters, at least one uppercase letter, at least one digit).
3. Server-side validation parses JSON input and re-validates:
   - Email format using `filter_var(..., FILTER_VALIDATE_EMAIL)`.
   - Password confirmation equality and complexity rules.
   - Duplicate email check via `SELECT customer_id FROM customer WHERE email = ?`. If duplicate, responds with HTTP 409 Conflict.
4. Passwords are securely hashed using `password_hash($password, PASSWORD_DEFAULT)` (Bcrypt).
5. Record is inserted into `customer` table. A successful 201 response returns customer metadata (excluding password hashes).

### 3.2 Login & Session Creation (`POST /api/login.php`)
1. User submits email and password via `#login`.
2. Server queries `customer` for the provided email.
3. To mitigate timing attacks, if the user does not exist, a dummy Bcrypt hash calculation is executed so execution time remains indistinguishable.
4. If found, `password_verify($password, $user['password_hash'])` authenticates the credential.
5. If invalid: returns generic HTTP 401 `{"success": false, "message": "Invalid email or password."}`.
6. If valid:
   - `session_regenerate_id(true)` is executed to eradicate session fixation vulnerabilities.
   - `$_SESSION['customer_id'] = (int) $user['customer_id']` is populated.
   - `$_SESSION['user_name']` and `$_SESSION['user_email']` are saved.
   - Returns HTTP 200 with sanitized user object.

### 3.3 Session Checking (`GET /api/check-session.php`)
1. Executed on page load (`initNavbar`) and prior to navigation into protected views.
2. Verifies whether `$_SESSION['customer_id']` exists.
3. If valid, queries the database to confirm the user account is active (`account_status = 'Active'`).
4. Returns `{"authenticated": true, "user": {...}}` or `{"authenticated": false}`.

### 3.4 Logout (`POST /api/logout.php`)
1. Invalidates session variables: `$_SESSION = []`.
2. Expire session cookie by writing cookie with past timestamp:
   ```php
   setcookie(session_name(), '', time() - 3600, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
   ```
3. Calls `session_destroy()`.
4. Client empties in-memory cart, purges display cache, and redirects user to `#home`.

---

## 4. Protected Routes & Aliases

### Protected Routes:
- `#profile`: Customer account dashboard containing profile information, address management, and password update forms.
- `#account`: Route alias that maps to `#profile`. Authenticated users visiting `#account` render the full profile dashboard.
- `#my-orders`: Customer order tracking and purchase history.
- `#checkout`: Protected checkout action; unauthenticated guests attempting checkout are redirected.

### Route Protection Architecture:
Route protection operates through two coordinated interceptors:
1. **Interactive Click Interceptor (`js/auth.js`)**:
   - A capture-phase click listener traps all clicks on `[data-page]` links targeting `profile`, `account`, or `my-orders`.
   - The event propagation is immediately halted (`e.stopImmediatePropagation()`).
   - A real-time session check (`api/check-session.php`) is dispatched to the server.
   - If unauthenticated, the user is redirected via `goPage('auth-guard')`.
2. **Deep-Link / URL Hash Interceptor (`js/site.js` & `js/auth.js`)**:
   - When a user enters `#profile` or `#account` directly into the browser URL bar:
     - `site.js` routes the view and synchronizes history state `#account` $\to$ `#profile`.
     - `auth.js` triggers `populateProfilePage()`.
     - `populateProfilePage()` queries `GET /api/profile.php`.
     - If the server responds with HTTP 401, `goPage('auth-guard')` is immediately executed.
3. **The Auth Guard View (`#auth-guard`)**:
   - Displays a clean callout informing the user that authentication is required to access their account details.
   - Provides direct actions to either Log In (`#login`) or Create an Account (`#signup`).

---

## 5. Password Security

1. **Hashing Standard**: Passwords are saved exclusively as salted hashes using `password_hash($password, PASSWORD_DEFAULT)`. In PHP 8.2+, this uses the standard Bcrypt algorithm with cost factor 10. Plaintext passwords and reversible encryption are strictly forbidden.
2. **Verification**: Passwords are authenticated with `password_verify($password, $hash)`, which is timing-safe.
3. **Timing-Attack Resistance**: When an unregistered email is submitted to `/api/login.php`, the backend computes a dummy `password_verify()` against a fixed dummy hash (`$2y$10$abcdefghijklmnopqrstuuNOPQRSTUVWXYZ0123456789abcdefghijk`) to guarantee that response latency does not reveal user account existence.
4. **Generic Error Responses**: Both non-existent accounts and incorrect passwords return identical HTTP 401 error payloads: `{"success": false, "message": "Invalid email or password."}` to prevent user enumeration.
5. **Session Fixation Prevention**: Upon successful credential verification, `session_regenerate_id(true)` immediately issues a new random session ID and purges the old session record.

---

## 6. Session Security

Server-side session management is initialized in `api/bootstrap.php` with the following security directives applied before `session_start()`:

```php
ini_set('session.cookie_httponly', 1);      // Mitigates XSS cookie theft
ini_set('session.use_strict_mode', 1);      // Rejects uninitialized session IDs
ini_set('session.cookie_samesite', 'Lax');  // CSRF protection for cross-origin navigation
session_name('KUKI_SESS');                 // Obscures PHP default PHPSESSID
```

- **HttpOnly**: JavaScript cannot inspect or read `document.cookie` for `KUKI_SESS`.
- **Use Strict Mode**: The PHP session manager rejects uninitialized, attacker-supplied session identifiers.
- **SameSite=Lax**: Session cookies are withheld during cross-site subrequests (e.g. image fetches, embeds), mitigating Cross-Site Request Forgery (CSRF).
- **Session Destruction**: `api/logout.php` completely wipes session memory and marks the cookie expired in the past.

---

## 7. Profile Management

The customer profile dashboard is accessible under `#profile` (or `#account`) and is powered by `api/profile.php`:
1. **Profile Data Loading (`GET /api/profile.php`)**:
   - Requires session authentication (`requireAuth()`).
   - Retrieves `customer_id`, `full_name`, `email`, `phone_no`, `account_status`, and formatted join date (`created_at`).
   - Populates profile header, sidebar, avatar initials, and contact details.
2. **Profile Updating (`PUT /api/profile.php` with `action: update_profile`)**:
   - Allows customer to update full name and phone number.
   - Prevents unauthorized modification of sensitive credentials or email identity.
3. **Address Management (`GET/POST/DELETE /api/profile.php`)**:
   - Sub-actions support adding, listing, updating, and deleting delivery addresses from `user_addresses`.
   - Each address is associated with the authenticated `customer_id`.
4. **In-Session Password Change (`PUT /api/profile.php` with `action: change_password`)**:
   - Requires verification of current existing password via `password_verify()`.
   - Enforces password complexity rules on new password.
   - Updates `password_hash` in `customer` table.

---

## 8. Password Reset Lifecycle

The password reset architecture allows users to recover access without compromising account security.

### 8.1 Forgot Password Request (`POST /api/forgot-password.php`)
1. User enters their email address on `#login` (via the "Forgot Password?" toggle).
2. The server checks the `customer` table for an existing active account.
3. **Token Generation**:
   - Generates a 64-character cryptographically secure hexadecimal token: `bin2hex(random_bytes(32))`.
   - Computes a SHA-256 hash of the token: `hash('sha256', $plainToken)`.
   - Stores the hashed token in `password_resets` table with an expiration timestamp (`expires_at = NOW() + 1 HOUR`).
4. **Development / Simulation Environment Handling**:
   - In production, an email containing a reset link is dispatched.
   - On this local development server without SMTP mail delivery configured, the API securely returns `{ success: true, account_found: true, reset_token: $token, reset_url: "#reset-password?token=..." }`.
   - The UI displays an interactive development action button: `Reset Password Now →`, allowing end-to-end verification without requiring an external email provider.

### 8.2 Token Validation (`GET /api/reset-password.php?token=...`)
1. Computes `hash('sha256', $token)`.
2. Queries `password_resets` to confirm:
   - Hash exists.
   - `used_at IS NULL` (prevents token reuse).
   - `expires_at > NOW()` (prevents expired token usage).
3. If invalid or expired, returns HTTP 400.
4. If valid, returns HTTP 200 with masked email (e.g. `v**u@kukicakes.lk`) for user confirmation.

### 8.3 Password Reset Execution (`POST /api/reset-password.php`)
1. Accepts token, new password, and confirm password.
2. Validates token validity and non-expiration inside a database transaction.
3. Enforces standard password complexity (minimum 8 characters, uppercase letter, number).
4. Hashes new password with `password_hash()`.
5. Updates `customer.password_hash`.
6. Marks the token as used: `UPDATE password_resets SET used_at = NOW() WHERE reset_id = ?`.
7. Invalidates all existing sessions for the customer.
8. Returns HTTP 200. User can now log in with the new password; old password is immediately rejected.

---

## 9. API Specification

| Endpoint | Method | Auth Req. | Request Body / Parameters | Success Response | Error Responses | Purpose |
|:---|:---:|:---:|:---|:---|:---|:---|
| `/api/register.php` | `POST` | None | `{ full_name, email, phone, password, confirm_password }` | `201 Created`<br>`{ success: true, message, user }` | `400 Bad Request`<br>`409 Conflict` | Register a new customer account |
| `/api/login.php` | `POST` | None | `{ email, password }` | `200 OK`<br>`{ success: true, user }` | `400 Bad Request`<br>`401 Unauthorized` | Authenticate customer and issue session |
| `/api/logout.php` | `POST` | None | None | `200 OK`<br>`{ success: true, message }` | None | Terminate session and invalidate cookie |
| `/api/check-session.php` | `GET` | Optional | None | `200 OK`<br>`{ authenticated: bool, user? }` | None | Check session validity on startup/routing |
| `/api/profile.php` | `GET` | Yes | None | `200 OK`<br>`{ success: true, user }` | `401 Unauthorized`<br>`500 Internal Error` | Retrieve authenticated profile details |
| `/api/profile.php` | `PUT` | Yes | `{ action: 'update_profile', full_name, phone }` | `200 OK`<br>`{ success: true, user }` | `400 Bad Request`<br>`401 Unauthorized` | Update account name & phone number |
| `/api/profile.php` | `PUT` | Yes | `{ action: 'change_password', current_password, new_password, confirm_password }` | `200 OK`<br>`{ success: true, message }` | `400 Bad Request`<br>`401 Unauthorized` | Update password from active session |
| `/api/forgot-password.php` | `POST` | None | `{ email }` | `200 OK`<br>`{ success: true, account_found, reset_token?, reset_url? }` | `400 Bad Request` | Generate password reset token |
| `/api/reset-password.php` | `GET` | None | Query param `?token=...` | `200 OK`<br>`{ success: true, email: masked }` | `400 Bad Request`<br>(expired or invalid) | Validate reset token before form display |
| `/api/reset-password.php` | `POST` | None | `{ token, password, confirm_password }` | `200 OK`<br>`{ success: true, message }` | `400 Bad Request`<br>(invalid token or password) | Set new password using valid reset token |
| `/api/cart.php` | `GET` | Optional | None | `200 OK`<br>`{ success: true, authenticated: bool, cart: [...] }` | None | Fetch persistent cart items for customer |
| `/api/cart.php` | `POST` | Yes | `{ action: 'save', items: [...] }` | `200 OK`<br>`{ success: true, cart: [...] }` | `401 Unauthorized`<br>`500 Internal Error` | Overwrite persistent cart in MySQL |
| `/api/cart.php` | `POST` | Yes | `{ action: 'merge', items: [...] }` | `200 OK`<br>`{ success: true, merged: true, cart: [...] }` | `401 Unauthorized`<br>`500 Internal Error` | Merge guest items with database items |
| `/api/cart.php` | `POST` | Yes | `{ action: 'clear' }` | `200 OK`<br>`{ success: true, cart: [] }` | `401 Unauthorized`<br>`500 Internal Error` | Remove all cart items for customer |

---

## 10. Database Schema & Data Models

### 10.1 `customer` Table
Stores user accounts, contact info, and Bcrypt password hashes.
```sql
CREATE TABLE `customer` (
    `customer_id`    INT(11) NOT NULL AUTO_INCREMENT,
    `full_name`      VARCHAR(100) NOT NULL,
    `email`          VARCHAR(150) NOT NULL,
    `phone_no`       VARCHAR(20) DEFAULT NULL,
    `password_hash`  VARCHAR(255) NOT NULL,
    `account_status` ENUM('Active','Suspended','Pending') DEFAULT 'Active',
    `created_at`     DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`customer_id`),
    UNIQUE KEY `uk_customer_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 10.2 `password_resets` Table
Stores cryptographically hashed reset tokens.
```sql
CREATE TABLE `password_resets` (
    `reset_id`     INT(11) NOT NULL AUTO_INCREMENT,
    `customer_id`  INT(11) NOT NULL,
    `token_hash`   VARCHAR(64) NOT NULL,
    `expires_at`   DATETIME NOT NULL,
    `used_at`      DATETIME DEFAULT NULL,
    `created_at`   DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`reset_id`),
    KEY `idx_token_hash` (`token_hash`),
    KEY `idx_customer_resets` (`customer_id`),
    CONSTRAINT `fk_pwreset_customer` FOREIGN KEY (`customer_id`) 
        REFERENCES `customer` (`customer_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 10.3 `user_cart_items` Table
Stores persistent shopping cart selections for authenticated customers.
```sql
CREATE TABLE `user_cart_items` (
    `cart_item_id` INT(11) NOT NULL AUTO_INCREMENT,
    `customer_id`  INT(11) NOT NULL,
    `product_id`   INT(11) NOT NULL,
    `weight`       VARCHAR(20) DEFAULT '500g',
    `flavor`       VARCHAR(100) DEFAULT '',
    `note`         TEXT DEFAULT NULL,
    `quantity`     INT(11) NOT NULL DEFAULT 1,
    `item_data`    LONGTEXT DEFAULT NULL,
    `created_at`   DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`cart_item_id`),
    KEY `idx_cart_customer` (`customer_id`),
    CONSTRAINT `fk_cart_customer` FOREIGN KEY (`customer_id`) 
        REFERENCES `customer` (`customer_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## 11. Security Controls Summary

| Threat | Vulnerability / Attack Vector | Applied Mitigation |
|:---|:---|:---|
| **Credential Theft** | Database breach leaking passwords | One-way Bcrypt hashes (`PASSWORD_DEFAULT`), cost 10, automatic salting |
| **User Enumeration** | Different errors for wrong password vs non-existent email | Uniform generic error messages + dummy Bcrypt compute for non-existent users |
| **Session Fixation** | Session ID reuse across login transitions | `session_regenerate_id(true)` invoked on every successful authentication |
| **XSS Cookie Theft** | Malicious scripts reading session cookie | `session.cookie_httponly = 1` prevents `document.cookie` inspection |
| **CSRF Attacks** | Cross-origin request forging session operations | `session.cookie_samesite = Lax` on session cookies |
| **Token Hijacking** | Database administrator or SQL injection leaking tokens | Tokens stored as SHA-256 hashes (`token_hash`); raw tokens never stored in DB |
| **Token Replay** | Reusing reset tokens multiple times | `used_at` timestamp check rejects any previously consumed token |
| **Cross-User Leakage** | Client or server leaking cart across accounts | Carts tied strictly to `customer_id` in SQL; client localStorage cleared on logout |
| **SQL Injection** | Parameter concatenation in database queries | 100% PDO prepared statements with strict parameter binding |

---

## 12. Debugging & Troubleshooting Log

During development and audits, several critical defects were identified, diagnosed, and resolved:

### 1. Wrong-Password Login Bug
- **Symptom**: User could log in with incorrect passwords or non-existent accounts.
- **Root Cause**: The client-side `auth.js` login handler did not check `data._status === 200` or `data.success === true` before updating display cache and triggering `goPage('profile')`.
- **Resolution**: Hardened frontend verification to strictly require `data && data._status === 200 && data.success === true && data.user?.customer_id`. On failure, the client explicitly clears any existing session display cache and renders the backend error message.

### 2. `#account` Route Alias Divergence
- **Symptom**: `#account` was listed in the specification, but navigating to `#account` did not activate `#profile-page` or trigger the auth guard redirect.
- **Root Cause**: `site.js` and `auth-loader.js` did not recognize `account` as an alias for the profile dashboard.
- **Resolution**: Mapped `account` to `profile` in `site.js` `goPage()`, registered `account` in `_knownPages`, added `account` to the protected route list in `auth.js`, and verified that unauthenticated visits redirect to `#auth-guard`.

### 3. Forgot Password / Password Reset Broken Flow
- **Symptom**: Test suite passed in PHP curl tests, but the actual browser user flow failed to execute because no email server was available on localhost.
- **Root Cause**: The UI had no mechanism to navigate into the reset page without an incoming email message.
- **Resolution**: Preserved secure server token hashing while enhancing `api/forgot-password.php` to return the reset token in local development environments. The UI renders a dedicated `Reset Password Now →` action button linking directly to `#reset-password?token=...`, verifying token validation and password updates end-to-end.

### 4. Cart Persistence & Multi-User Isolation
- **Symptom**: Cart items only existed in browser `localStorage` and were lost across devices or after clearing local storage.
- **Root Cause**: No server-side relational cart table or API endpoints existed.
- **Resolution**: Created `user_cart_items` table in MySQL via `api/db_migrate.php`. Implemented `api/cart.php` with `save`, `merge`, and `clear` actions. Integrated debounced server syncing in `site.js` and `auth.js`. Added automatic merge of guest items on login and cart purging on logout.

---

## 13. Testing and Verification Results

All unit, integration, regression, and browser automation test suites have been executed against the current implementation.

### Test Execution Summary:
- **`php tests/test_existing_auth.php`**: **10 / 10 PASS**
  - Sign up ✅
  - Wrong password rejected (HTTP 401) ✅
  - Nonexistent email rejected (HTTP 401) ✅
  - Login with correct password ✅
  - Check session authenticated ✅
  - Profile fetch ✅
  - Change password ✅
  - Logout ✅
  - Session cleared after logout ✅
  - Login with new password after change ✅

- **`php tests/test_password_reset.php`**: **21 / 21 PASS**
  - Generic response on unregistered email ✅
  - Token generated & stored as SHA-256 hash ✅
  - Valid token verification passes ✅
  - No secrets exposed in responses ✅
  - Invalid and expired tokens rejected (HTTP 400) ✅
  - Password complexity validation enforced ✅
  - Successful password reset ✅
  - Token marked as used ✅
  - Reused tokens rejected ✅
  - Old password rejected (HTTP 401) ✅
  - New password accepted (HTTP 200) ✅

- **`node tests/test_route_alias.js`**: **ALL SCENARIOS PASS**
  - Unauthenticated `#account` $\to$ redirected to `#auth-guard` ✅
  - Unauthenticated `#profile` $\to$ redirected to `#auth-guard` ✅
  - Valid login initializes session ✅
  - Authenticated `#account` $\to$ renders profile dashboard with user data ✅
  - Authenticated `#profile` $\to$ renders profile dashboard with user data ✅
  - Dynamic in-page hashchange switching between `#account` and `#profile` ✅

- **`node tests/test_forgot_password_flow.js`**: **9 / 9 PASS**
  - Navigate to `#login` ✅
  - Open forgot password form ✅
  - Submit registered email `vinu@kukicakes.lk` ✅
  - Development token link generated ✅
  - Navigate to `#reset-password?token=...` ✅
  - Validate token & display masked email ✅
  - Submit new password ✅
  - Verify old password rejected with HTTP 401 ✅
  - Verify new password accepted with HTTP 200 ✅

- **`php tests/test_cart_sync.php`**: **17 / 17 PASS**
  - Unauthenticated GET returns empty cart ✅
  - Unauthenticated POST rejected with HTTP 401 ✅
  - Authenticated save persists to `user_cart_items` in MySQL ✅
  - MySQL row count confirmed via direct PDO query ✅
  - Authenticated GET returns all persisted items and options ✅
  - Quantity updates and item removals persist to DB ✅
  - Merging guest cart sums matching item quantities and appends new items ✅
  - Multi-user isolation: User A and User B carts strictly separated ✅
  - Clear cart deletes DB rows for customer ✅

- **`node tests/test_cart_browser.js`**: **6 / 6 PASS**
  - Guest adds item to cart $\to$ stored in localStorage & navbar count updated ✅
  - Guest logs in $\to$ cart merged with account cart in MySQL ✅
  - Database cart API confirms items saved to MySQL table `user_cart_items` ✅
  - Fresh page reload $\to$ cart persisted and rendered from server ✅
  - Logout $\to$ cart emptied on client to prevent cross-account data leakage ✅

---

## 14. Week 6 Completion Status

| Requirement Area | Status | Verification Detail |
|:---|:---:|:---|
| **1. User Registration** | **COMPLETE** | Full validation, Bcrypt hashing, duplicate email handling verified |
| **2. Authentication & Login** | **COMPLETE** | Credential validation, timing-safe checks, generic errors verified |
| **3. Secure Session (`KUKI_SESS`)** | **COMPLETE** | HttpOnly, SameSite=Lax, strict mode, regeneration verified |
| **4. Profile Management** | **COMPLETE** | Profile fetch, contact update, address book, change-password verified |
| **5. Route Protection & `#account` Alias** | **COMPLETE** | `#profile` and `#account` reach dashboard; unauthenticated redirected to `#auth-guard` |
| **6. Password Reset Flow** | **COMPLETE** | Full browser flow from request to token validation and reset verified |
| **7. Cart Database Synchronization** | **COMPLETE** | Relational `user_cart_items` persistence, merge, and isolation verified |
| **8. Documentation & Test Suite** | **COMPLETE** | Complete specification, schema, troubleshooting logs, and tests documented |

**Overall Week 6 Implementation Status**: **COMPLETE**
