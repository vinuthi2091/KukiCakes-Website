/* ================================================================
   KúkiCakes — Authentication Module   (js/auth.js)
   ----------------------------------------------------------------
   Real PHP + MySQL backend authentication.
   Session managed server-side via PHP sessions (KUKI_SESS cookie).
   localStorage is used only for harmless display cache (name/email)
   to avoid an extra network round-trip for navbar rendering, but
   all protected operations always verify the server session first.
   ================================================================ */

(function () {
    'use strict';

    /* ============================================================
       CONSTANTS
       ============================================================ */
    const DISPLAY_KEY = 'kukiUserDisplay'; // safe display cache only (name, email — no secrets)
    const MERGE_KEY   = 'kukiCartMergeShown';

    /* ============================================================
       API HELPERS
       ============================================================ */

    /**
     * POST JSON body to a PHP API endpoint.
     * Returns parsed JSON or throws on network/HTTP error.
     */
    async function apiPost(endpoint, data) {
        const res = await fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',   // include session cookie
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data),
        });
        const json = await res.json();
        json._status = res.status;
        return json;
    }

    async function apiGet(endpoint) {
        const res = await fetch(endpoint, {
            method: 'GET',
            credentials: 'same-origin',
        });
        const json = await res.json();
        json._status = res.status;
        return json;
    }

    async function apiDelete(endpoint) {
        const res = await fetch(endpoint, {
            method: 'DELETE',
            credentials: 'same-origin',
        });
        const json = await res.json();
        json._status = res.status;
        return json;
    }

    /* ============================================================
       AUTH STATE — backed by server session
       ============================================================ */

    /**
     * Returns the cached display object {name, email} from
     * localStorage, or null if not set.
     * This is purely for fast navbar rendering; never used to
     * authorise any action.
     */
    function getDisplayCache() {
        try { return JSON.parse(localStorage.getItem(DISPLAY_KEY)); }
        catch { return null; }
    }

    function setDisplayCache(userData) {
        const safe = { name: userData.name || '', email: userData.email || '' };
        localStorage.setItem(DISPLAY_KEY, JSON.stringify(safe));
    }

    function clearDisplayCache() {
        localStorage.removeItem(DISPLAY_KEY);
        localStorage.removeItem(MERGE_KEY);
        localStorage.removeItem('kukiUser');
        localStorage.removeItem('kukiPendingUser');
    }

    /* ============================================================
       NAVBAR — guest / authenticated states
       ============================================================ */

    function updateNavbar(user) {
        const navGuest     = document.getElementById('navGuest');
        const navUser      = document.getElementById('navUser');
        const userAvatar   = document.getElementById('userAvatar');
        const userDispName = document.getElementById('userDisplayName');

        if (!navGuest || !navUser) return;

        if (user && user.name) {
            navGuest.hidden = true;
            navUser.hidden  = false;

            const initials = (user.name || 'K')
                .split(' ')
                .filter(Boolean)
                .map(n => n[0].toUpperCase())
                .slice(0, 2)
                .join('');

            if (userAvatar)   userAvatar.textContent   = initials;
            if (userDispName) userDispName.textContent = (user.name || '').split(' ')[0];
        } else {
            navGuest.hidden = false;
            navUser.hidden  = true;
        }
    }

    /**
     * Check the server session and update the navbar accordingly.
     * Uses display cache for instant render, then confirms with server.
     */
    async function initNavbar() {
        // 1. Instant render using cached display info (avoids flash)
        const cached = getDisplayCache();
        if (cached) updateNavbar(cached);

        // 2. Verify with the server
        try {
            const data = await apiGet('api/check-session.php');
            if (data.authenticated && data.user) {
                setDisplayCache(data.user);
                updateNavbar(data.user);
            } else {
                // Session gone — clear cache and show guest nav
                clearDisplayCache();
                updateNavbar(null);
            }
        } catch (err) {
            // Network issue — fall back to cached state silently
            console.warn('[KúkiCakes] Could not verify session:', err);
        }
    }

    /* ============================================================
       USER DROPDOWN (nav)
       ============================================================ */

    function setupUserMenuDropdown() {
        const btn      = document.getElementById('userMenuBtn');
        const dropdown = document.getElementById('userDropdown');
        if (!btn || !dropdown) return;

        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const open = dropdown.classList.toggle('open');
            btn.setAttribute('aria-expanded', String(open));
        });

        document.addEventListener('click', () => {
            dropdown.classList.remove('open');
            btn.setAttribute('aria-expanded', 'false');
        });

        dropdown.addEventListener('click', () => {
            dropdown.classList.remove('open');
            btn.setAttribute('aria-expanded', 'false');
        });
    }

    /* ============================================================
       LOGOUT
       ============================================================ */

    async function handleLogout() {
        try {
            await apiPost('api/logout.php', {});
        } catch (err) {
            console.warn('[KúkiCakes] Logout request failed:', err);
        }
        clearDisplayCache();
        updateNavbar(null);
        _toast('You have been logged out. See you soon! 👋');
        if (typeof goPage === 'function') goPage('home');
    }

    function setupLogoutButtons() {
        document.getElementById('logoutBtn')?.addEventListener('click', handleLogout);
        document.getElementById('profileLogoutBtn')?.addEventListener('click', handleLogout);
    }

    /* ============================================================
       PROTECTED PAGE GUARD
       Capture-phase intercept — runs before site.js bubble handler.
       Redirect unauthenticated users to auth-guard.
       Auth is verified via server session.
       ============================================================ */

    const PROTECTED = ['profile', 'my-orders'];

    document.addEventListener('click', async (e) => {
        const link = e.target.closest('[data-page]');
        if (!link) return;

        const page = link.dataset.page;
        if (!PROTECTED.includes(page)) return;

        // Check server session before allowing navigation
        e.stopImmediatePropagation();
        e.preventDefault();

        try {
            const data = await apiGet('api/check-session.php');
            if (!data.authenticated) {
                if (typeof goPage === 'function') goPage('auth-guard');
                return;
            }
            // Authenticated — navigate, then populate
            if (typeof goPage === 'function') goPage(page);
            if (page === 'profile')    setTimeout(populateProfilePage, 30);
            if (page === 'my-orders')  setTimeout(() => renderOrdersPage('all'), 30);
        } catch (err) {
            // Network error — deny access to be safe
            if (typeof goPage === 'function') goPage('auth-guard');
        }
    }, true);

    /* ============================================================
       CHECKOUT PROTECTION
       ============================================================ */

    function setupCheckoutProtection() {
        const btn = document.getElementById('checkoutBtn');
        if (!btn) return;

        btn.onclick = async () => {
            let cart = [];
            try { cart = JSON.parse(localStorage.getItem('kukiCart') || '[]'); } catch { }

            if (cart.length === 0) {
                _toast('Add a cake before checking out 🎂');
                return;
            }

            try {
                const data = await apiGet('api/check-session.php');
                if (!data.authenticated) {
                    if (typeof goPage === 'function') goPage('auth-guard');
                } else {
                    if (typeof goPage === 'function') goPage('checkout');
                }
            } catch (err) {
                if (typeof goPage === 'function') goPage('auth-guard');
            }
        };
    }

    /* ============================================================
       CART MERGE NOTIFICATION
       ============================================================ */

    function maybeShowCartMergeBanner(firstName) {
        if (localStorage.getItem(MERGE_KEY)) return;

        let cart = [];
        try { cart = JSON.parse(localStorage.getItem('kukiCart') || '[]'); } catch { }
        if (cart.length === 0) return;

        localStorage.setItem(MERGE_KEY, '1');
        _toast(`Welcome back, ${firstName}! Your cart items have been saved. 🛒`);
    }

    /* ============================================================
       SIGN-UP FORM
       ============================================================ */

    function setupSignupForm() {
        const form = document.getElementById('signupForm');
        if (!form) return;

        // Password show/hide toggles
        form.querySelectorAll('.password-toggle').forEach(setupToggle);

        // Live strength + requirements
        const pwInput = document.getElementById('su-password');
        pwInput?.addEventListener('input', () => {
            updateStrength(pwInput.value, 'su-strength-fill', 'su-strength-label');
            updateReqs(pwInput.value, 'req-len', 'req-upper', 'req-lower', 'req-num');
        });

        // Blur validation
        ['su-name', 'su-email', 'su-phone', 'su-password', 'su-confirm'].forEach(id => {
            const field = id.replace('su-', '');
            document.getElementById(id)?.addEventListener('blur', () => validateSignupField(field));
        });

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const ok = ['name', 'email', 'phone', 'password', 'confirm'].every(validateSignupField);
            if (!ok) return;

            const submitBtn = document.getElementById('signupSubmitBtn');
            if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Creating account…'; }

            const payload = {
                full_name:        document.getElementById('su-name').value.trim(),
                email:            document.getElementById('su-email').value.trim().toLowerCase(),
                phone:            document.getElementById('su-phone').value.trim(),
                password:         document.getElementById('su-password').value,
                confirm_password: document.getElementById('su-confirm').value,
            };

            try {
                const data = await apiPost('api/register.php', payload);

                if (data.success) {
                    // Show success state — same existing UI
                    form.style.display = 'none';
                    const successEl = document.getElementById('signupSuccess');
                    if (successEl) successEl.removeAttribute('hidden');
                } else {
                    _toast(data.message || 'Registration failed. Please try again.');
                    if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Create Account →'; }
                }
            } catch (err) {
                _toast('Registration failed. Please check your connection.');
                if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Create Account →'; }
            }
        });
    }

    function validateSignupField(field) {
        const map = {
            name:     ['su-name',     'su-name-err'],
            email:    ['su-email',    'su-email-err'],
            phone:    ['su-phone',    'su-phone-err'],
            password: ['su-password', 'su-password-err'],
            confirm:  ['su-confirm',  'su-confirm-err'],
        };
        const [inputId, errId] = map[field];
        const input = document.getElementById(inputId);
        const errEl = document.getElementById(errId);
        if (!input || !errEl) return true;

        const val = input.value.trim();
        let error = '';

        switch (field) {
            case 'name':
                if (!val)           error = 'Full name is required.';
                else if (val.length < 2) error = 'Name must be at least 2 characters.';
                break;
            case 'email':
                if (!val)           error = 'Email address is required.';
                else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val)) error = 'Please enter a valid email address.';
                break;
            case 'phone':
                if (!val)           error = 'Phone number is required.';
                else if (!/^[+\d\s\-()]{7,20}$/.test(val)) error = 'Please enter a valid phone number.';
                break;
            case 'password': {
                if (!val)               error = 'Password is required.';
                else if (val.length < 8) error = 'Password must be at least 8 characters.';
                else if (!/[A-Z]/.test(val)) error = 'Include at least one uppercase letter.';
                else if (!/[0-9]/.test(val)) error = 'Include at least one number.';
                break;
            }
            case 'confirm': {
                const pw = document.getElementById('su-password')?.value || '';
                if (!val)          error = 'Please confirm your password.';
                else if (val !== pw) error = 'Passwords do not match.';
                break;
            }
        }

        _applyFieldState(input, errEl, error);
        return !error;
    }

    /* ============================================================
       LOGIN FORM
       ============================================================ */

    function setupLoginForm() {
        const form = document.getElementById('loginForm');
        if (!form) return;

        // Password show/hide
        form.querySelector('.password-toggle')?.addEventListener('click', function () {
            _togglePasswordVisibility(this);
        });

        // Forgot password (UI only — server-side reset not in scope)
        document.getElementById('forgotPasswordBtn')?.addEventListener('click', () => {
            const msg = document.getElementById('forgotMessage');
            if (msg) msg.removeAttribute('hidden');
        });

        document.getElementById('sendResetBtn')?.addEventListener('click', () => {
            const emailVal = document.getElementById('li-email')?.value.trim();
            if (!emailVal || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailVal)) {
                _applyFieldState(
                    document.getElementById('li-email'),
                    document.getElementById('li-email-err'),
                    'Please enter a valid email address first.'
                );
                return;
            }
            _toast('If an account exists for that email, a reset link will be sent. 📧');
        });

        // Blur validation & input reset
        document.getElementById('li-email')?.addEventListener('blur',    () => validateLoginField('email'));
        document.getElementById('li-password')?.addEventListener('blur', () => validateLoginField('password'));
        document.getElementById('li-email')?.addEventListener('input', () => {
            const errEl = document.getElementById('li-password-err');
            if (errEl && errEl.textContent === 'Invalid email or password.') errEl.textContent = '';
        });
        document.getElementById('li-password')?.addEventListener('input', () => {
            const errEl = document.getElementById('li-password-err');
            if (errEl && errEl.textContent === 'Invalid email or password.') errEl.textContent = '';
            document.getElementById('li-password')?.classList.remove('is-error');
        });

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const ok = validateLoginField('email') & validateLoginField('password');
            if (!ok) return;

            const submitBtn = document.getElementById('loginSubmitBtn');
            if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Logging in…'; }

            const payload = {
                email:    document.getElementById('li-email').value.trim().toLowerCase(),
                password: document.getElementById('li-password').value,
            };

            try {
                const data = await apiPost('api/login.php', payload);

                if (data && data._status === 200 && data.success === true && data.user && data.user.customer_id) {
                    setDisplayCache(data.user);
                    updateNavbar(data.user);
                    maybeShowCartMergeBanner(data.user.name.split(' ')[0]);

                    if (typeof goPage === 'function') goPage('profile');
                    setTimeout(populateProfilePage, 60);
                } else {
                    // Failed login — clear any stale display cache and show guest nav
                    clearDisplayCache();
                    updateNavbar(null);
                    // Show generic error from server
                    const errEl = document.getElementById('li-password-err');
                    if (errEl) errEl.textContent = data.message || 'Invalid email or password.';
                    const pwInput = document.getElementById('li-password');
                    if (pwInput) pwInput.classList.add('is-error');
                    if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Log In →'; }
                }
            } catch (err) {
                // Network or JSON parse error
                clearDisplayCache();
                updateNavbar(null);
                _toast('Login failed. Please check your connection.');
                if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Log In →'; }
            }
        });
    }

    function validateLoginField(field) {
        const map = {
            email:    ['li-email',    'li-email-err'],
            password: ['li-password', 'li-password-err'],
        };
        const [inputId, errId] = map[field];
        const input = document.getElementById(inputId);
        const errEl = document.getElementById(errId);
        if (!input || !errEl) return true;

        const val = input.value.trim();
        let error = '';

        if (field === 'email') {
            if (!val)           error = 'Email address is required.';
            else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val)) error = 'Please enter a valid email address.';
        } else {
            if (!input.value)   error = 'Password is required.';
        }

        _applyFieldState(input, errEl, error);
        return !error;
    }

    /* ============================================================
       PROFILE PAGE — populate & interactions
       ============================================================ */

    async function populateProfilePage() {
        try {
            const data = await apiGet('api/profile.php');

            if (!data.success || !data.user) {
                // Not authenticated — redirect to guard
                if (typeof goPage === 'function') goPage('auth-guard');
                return;
            }

            const user = data.user;

            const initials = (user.name || 'K')
                .split(' ').filter(Boolean)
                .map(n => n[0].toUpperCase()).slice(0, 2).join('');

            _setText('profileAvatar',      initials);
            _setText('profileSidebarName', user.name  || '—');
            _setText('profileSidebarEmail',user.email || '—');
            _setText('profileName',        user.name  || '—');
            _setText('profileJoinDate',    user.joinDate || '—');
            _setText('profileEmail',       user.email || '—');
            _setText('profilePhone',       user.phone || 'Not provided');

            // Pre-fill email in contact edit form (read-only)
            const emailDisp = document.getElementById('profileEmailDisplay');
            if (emailDisp) emailDisp.value = user.email || '';

            // Mini orders (still uses demo data — orders DB integration
            // requires orders to be linked to authenticated users)
            renderMiniOrders();

        } catch (err) {
            console.error('[KúkiCakes] Could not load profile:', err);
            _toast('Could not load profile. Please try again.');
        }
    }

    function setupProfilePage() {
        // Panel switching via sidebar buttons
        document.querySelectorAll('.sidebar-nav-item[data-panel]').forEach(btn => {
            btn.addEventListener('click', () => {
                const panel = btn.dataset.panel;

                document.querySelectorAll('.sidebar-nav-item').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');

                document.querySelectorAll('.profile-panel').forEach(p => p.classList.remove('active'));
                document.getElementById(`panel-${panel}`)?.classList.add('active');

                if (panel === 'orders') {
                    if (typeof goPage === 'function') goPage('my-orders');
                    setTimeout(() => renderOrdersPage('all'), 50);
                }

                // Load addresses on-demand
                if (panel === 'shipping') loadAndRenderAddresses('shipping');
                if (panel === 'billing')  loadAndRenderAddresses('billing');
            });
        });

        setupEditableSection('personal', 'personalDisplay', 'personalEdit', 'personalEditBtn', 'personalSaveBtn', 'personalCancelBtn', savePersInfo);
        setupEditableSection('contact',  'contactDisplay',  'contactEdit',  'contactEditBtn',  'contactSaveBtn',  'contactCancelBtn',  saveContactInfo);

        setupChangePasswordForm();
        setupAddressManagement();

        // Populate profile if already on profile page
        const isOnProfile = document.getElementById('profile-page')?.classList.contains('active');
        if (isOnProfile) populateProfilePage();
    }

    function setupEditableSection(prefix, displayId, editId, editBtnId, saveBtnId, cancelBtnId, saveFn) {
        const displayEl = document.getElementById(displayId);
        const editEl    = document.getElementById(editId);
        const editBtn   = document.getElementById(editBtnId);
        const saveBtn   = document.getElementById(saveBtnId);
        const cancelBtn = document.getElementById(cancelBtnId);

        if (!editBtn || !saveBtn || !cancelBtn) return;

        editBtn.addEventListener('click', () => {
            displayEl?.classList.add('hidden');
            editEl?.classList.remove('hidden');
            editBtn.style.display = 'none';
            // Pre-fill edit inputs from current displayed values
            if (prefix === 'personal') {
                const input = document.getElementById('editNameInput');
                if (input) input.value = document.getElementById('profileName')?.textContent || '';
            }
            if (prefix === 'contact') {
                const input = document.getElementById('editPhoneInput');
                if (input) input.value = document.getElementById('profilePhone')?.textContent || '';
                const emailDisp = document.getElementById('profileEmailDisplay');
                const cachedEmail = getDisplayCache()?.email || '';
                if (emailDisp) emailDisp.value = cachedEmail;
            }
        });

        cancelBtn.addEventListener('click', () => {
            displayEl?.classList.remove('hidden');
            editEl?.classList.add('hidden');
            editBtn.style.display = '';
        });

        saveBtn.addEventListener('click', async () => {
            const ok = await saveFn();
            if (!ok) return;
            displayEl?.classList.remove('hidden');
            editEl?.classList.add('hidden');
            editBtn.style.display = '';
        });
    }

    async function savePersInfo() {
        const input  = document.getElementById('editNameInput');
        const errEl  = document.getElementById('editNameErr');
        const newName = input?.value.trim();

        if (!newName) {
            _applyFieldState(input, errEl, 'Full name is required.');
            return false;
        }
        _applyFieldState(input, errEl, '');

        try {
            const data = await apiPost('api/update-profile.php', {
                full_name: newName,
                phone: document.getElementById('profilePhone')?.textContent || '',
            });

            if (!data.success) {
                _toast(data.message || 'Update failed.');
                return false;
            }

            const user = data.user;
            _setText('profileName',        user.name);
            _setText('profileSidebarName', user.name);
            const initials = user.name.split(' ').filter(Boolean).map(n => n[0].toUpperCase()).slice(0, 2).join('');
            _setText('profileAvatar',  initials);
            _setText('userAvatar',     initials);
            _setText('userDisplayName', user.name.split(' ')[0]);
            setDisplayCache(user);
            _toast('Changes saved ✓');
            return true;
        } catch (err) {
            _toast('Update failed. Please check your connection.');
            return false;
        }
    }

    async function saveContactInfo() {
        const input    = document.getElementById('editPhoneInput');
        const errEl    = document.getElementById('editPhoneErr');
        const newPhone = input?.value.trim();

        if (!newPhone) {
            _applyFieldState(input, errEl, 'Phone number is required.');
            return false;
        }
        if (!/^[+\d\s\-()]{7,20}$/.test(newPhone)) {
            _applyFieldState(input, errEl, 'Please enter a valid phone number.');
            return false;
        }
        _applyFieldState(input, errEl, '');

        try {
            const data = await apiPost('api/update-profile.php', {
                full_name: document.getElementById('profileName')?.textContent || '',
                phone:     newPhone,
            });

            if (!data.success) {
                _toast(data.message || 'Update failed.');
                return false;
            }

            _setText('profilePhone', data.user.phone || 'Not provided');
            _toast('Changes saved ✓');
            return true;
        } catch (err) {
            _toast('Update failed. Please check your connection.');
            return false;
        }
    }

    /* ============================================================
       CHANGE PASSWORD FORM
       ============================================================ */

    function setupChangePasswordForm() {
        const form = document.getElementById('changePwForm');
        if (!form) return;

        form.querySelectorAll('.password-toggle').forEach(setupToggle);

        document.getElementById('cp-new')?.addEventListener('input', function () {
            updateStrength(this.value, 'cp-strength-fill', 'cp-strength-label');
            updateReqs(this.value, 'cp-req-len', 'cp-req-upper', 'cp-req-lower', 'cp-req-num');
        });

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            let ok = true;

            const current = document.getElementById('cp-current');
            const newPw   = document.getElementById('cp-new');
            const confirm = document.getElementById('cp-confirm');

            if (!current?.value) {
                _applyFieldState(current, document.getElementById('cp-current-err'), 'Current password is required.');
                ok = false;
            } else {
                _applyFieldState(current, document.getElementById('cp-current-err'), '');
            }

            if (!newPw?.value || newPw.value.length < 8) {
                _applyFieldState(newPw, document.getElementById('cp-new-err'), 'New password must be at least 8 characters.');
                ok = false;
            } else {
                _applyFieldState(newPw, document.getElementById('cp-new-err'), '');
            }

            if (confirm?.value !== newPw?.value) {
                _applyFieldState(confirm, document.getElementById('cp-confirm-err'), 'Passwords do not match.');
                ok = false;
            } else {
                _applyFieldState(confirm, document.getElementById('cp-confirm-err'), '');
            }

            if (!ok) return;

            const submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Changing…'; }

            try {
                const data = await apiPost('api/change-password.php', {
                    current_password: current.value,
                    new_password:     newPw.value,
                    confirm_password: confirm.value,
                });

                if (data.success) {
                    const successEl = document.getElementById('changePwSuccess');
                    if (successEl) {
                        successEl.textContent = '✓ Password changed successfully!';
                        successEl.classList.add('show');
                    }
                    form.reset();
                    document.getElementById('cp-strength-fill').className = 'strength-fill';
                    document.getElementById('cp-strength-label').textContent = '';
                    document.querySelectorAll('#cp-pw-reqs li').forEach(li => li.classList.remove('met'));
                    setTimeout(() => successEl?.classList.remove('show'), 5000);
                } else {
                    if (data._status === 401) {
                        _applyFieldState(current, document.getElementById('cp-current-err'), data.message);
                    } else {
                        _toast(data.message || 'Password change failed.');
                    }
                }
            } catch (err) {
                _toast('Password change failed. Please check your connection.');
            } finally {
                if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Change Password'; }
            }
        });
    }

    /* ============================================================
       ADDRESS MANAGEMENT (Shipping & Billing)
       Connected to real MySQL via /api/addresses.php
       ============================================================ */

    function setupAddressManagement() {
        // Addresses are loaded on-demand when the panel is activated
        setupAddressModal();
    }

    async function loadAndRenderAddresses(type) {
        const grid = document.getElementById(`${type}AddrGrid`);
        if (!grid) return;

        grid.innerHTML = '<p style="color:var(--muted);padding:1rem 0">Loading…</p>';

        try {
            const data = await apiGet(`api/addresses.php?type=${type}`);
            if (!data.success) {
                grid.innerHTML = '<p style="color:var(--muted)">Could not load addresses.</p>';
                return;
            }
            renderAddresses(type, data.addresses || []);
        } catch (err) {
            grid.innerHTML = '<p style="color:var(--muted)">Could not load addresses.</p>';
        }
    }

    function renderAddresses(type, list) {
        const grid = document.getElementById(`${type}AddrGrid`);
        if (!grid) return;

        grid.innerHTML = list.map(addr => `
            <div class="address-card${addr.is_default ? ' is-default' : ''}">
                ${addr.is_default ? '<span class="address-default-badge">✦ Default</span>' : ''}
                <div class="address-name">${_esc(addr.full_name)}</div>
                <div class="address-line">
                    ${_esc(addr.line1)}<br>
                    ${addr.line2 ? _esc(addr.line2) + '<br>' : ''}
                    ${_esc(addr.city)}${addr.postal ? ', ' + _esc(addr.postal) : ''}<br>
                    ${addr.phone ? _esc(addr.phone) : ''}
                </div>
                <div class="address-actions">
                    ${!addr.is_default
                        ? `<button class="address-btn" onclick="kukiSetDefault('${type}',${addr.address_id})">Set Default</button>`
                        : ''}
                    <button class="address-btn" onclick="kukiEditAddr('${type}',${addr.address_id})">Edit</button>
                    <button class="address-btn address-btn-delete" onclick="kukiDeleteAddr('${type}',${addr.address_id})">Delete</button>
                </div>
            </div>
        `).join('') + `
            <button class="add-address-card" data-addr-type="${type}">
                <span class="add-addr-icon">＋</span>
                <span>Add New Address</span>
            </button>
        `;

        grid.querySelector(`[data-addr-type="${type}"]`)?.addEventListener('click', () => {
            openAddressModal(type, null);
        });
    }

    // Global helpers (called from inline onclick)
    window.kukiSetDefault = async function (type, id) {
        try {
            await apiPost('api/addresses.php', {
                address_id: id, type,
                // Fetch current data to re-send
                full_name: '', line1: '', city: '', is_default: true,
            });
        } catch (err) { /* ignore */ }
        // Re-load to get the server-confirmed state
        // First fetch current data for the address being set as default
        try {
            const listData = await apiGet(`api/addresses.php?type=${type}`);
            const addr = (listData.addresses || []).find(a => a.address_id === id);
            if (addr) {
                await apiPost('api/addresses.php', {
                    address_id: id, type,
                    full_name: addr.full_name, line1: addr.line1, line2: addr.line2 || '',
                    city: addr.city, postal: addr.postal || '', phone: addr.phone || '',
                    is_default: true,
                });
            }
        } catch (err) { /* ignore */ }
        loadAndRenderAddresses(type);
        _toast('Default address updated ✓');
    };

    window.kukiEditAddr = async function (type, id) {
        try {
            const data = await apiGet(`api/addresses.php?type=${type}`);
            const addr = (data.addresses || []).find(a => a.address_id === id);
            if (addr) openAddressModal(type, addr);
        } catch (err) {
            _toast('Could not load address data.');
        }
    };

    window.kukiDeleteAddr = async function (type, id) {
        if (!confirm('Remove this address?')) return;
        try {
            await apiDelete(`api/addresses.php?id=${id}`);
            loadAndRenderAddresses(type);
            _toast('Address removed.');
        } catch (err) {
            _toast('Could not delete address.');
        }
    };

    // Modal state
    let _modalType = null;
    let _modalAddrId = null;

    function openAddressModal(type, addr) {
        _modalType   = type;
        _modalAddrId = addr ? addr.address_id : null;

        const overlay = document.getElementById('addressModalOverlay');
        if (!overlay) return;

        _setText('addrModalTitle', addr ? 'Edit Address' : 'Add New Address');
        _setVal('addrName',   addr?.full_name || '');
        _setVal('addrLine1',  addr?.line1     || '');
        _setVal('addrLine2',  addr?.line2     || '');
        _setVal('addrCity',   addr?.city      || '');
        _setVal('addrPostal', addr?.postal    || '');
        _setVal('addrPhone',  addr?.phone     || '');

        overlay.classList.add('open');
        document.getElementById('addrName')?.focus();
    }

    function closeAddressModal() {
        document.getElementById('addressModalOverlay')?.classList.remove('open');
        _modalType   = null;
        _modalAddrId = null;
    }

    function setupAddressModal() {
        document.getElementById('addrModalClose')?.addEventListener('click',  closeAddressModal);
        document.getElementById('addrModalCancel')?.addEventListener('click', closeAddressModal);

        document.getElementById('addressModalOverlay')?.addEventListener('click', (e) => {
            if (e.target === e.currentTarget) closeAddressModal();
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeAddressModal();
        });

        document.getElementById('addrSaveBtn')?.addEventListener('click', async () => {
            const name  = document.getElementById('addrName')?.value.trim();
            const line1 = document.getElementById('addrLine1')?.value.trim();
            const city  = document.getElementById('addrCity')?.value.trim();

            if (!name || !line1 || !city) {
                _toast('Please fill in Name, Address, and City.');
                return;
            }

            const payload = {
                type:       _modalType,
                address_id: _modalAddrId || undefined,
                full_name:  name,
                line1,
                line2:   document.getElementById('addrLine2')?.value.trim()  || '',
                city,
                postal:  document.getElementById('addrPostal')?.value.trim() || '',
                phone:   document.getElementById('addrPhone')?.value.trim()  || '',
            };

            try {
                const data = await apiPost('api/addresses.php', payload);
                if (!data.success) {
                    _toast(data.message || 'Could not save address.');
                    return;
                }
                loadAndRenderAddresses(_modalType);
                closeAddressModal();
                _toast('Address saved ✓');
            } catch (err) {
                _toast('Could not save address. Please try again.');
            }
        });
    }

    /* ============================================================
       MY ORDERS — dummy display data
       (Orders are not yet linked to auth users in the DB orders table)
       ============================================================ */

    const ORDERS = [
        {
            id: 'KC-10234', date: '1 Sep 2026', cake: 'Golden Pearl Anniversary Cake',
            img: 'https://images.unsplash.com/photo-1578985545062-69928b1d9587?auto=format&fit=crop&w=160&q=80',
            qty: 1, total: 10500, deliveryDate: '5 Sep 2026', status: 'delivered',
            weight: '1 kg', flavor: 'Vanilla Bean', address: '42 Galle Road, Colombo 03',
        },
        {
            id: 'KC-10298', date: '8 Sep 2026', cake: 'Blush Rosette Mirror 21st Cake',
            img: 'https://images.unsplash.com/photo-1535141192574-5d4897c12636?auto=format&fit=crop&w=160&q=80',
            qty: 1, total: 9000, deliveryDate: '12 Sep 2026', status: 'confirmed',
            weight: '1.5 kg', flavor: 'Red Velvet', address: '12 Dharmapala Mawatha, Colombo 07',
        },
        {
            id: 'KC-10312', date: '10 Sep 2026', cake: 'Chocolate Fudge Cupcake Box × 12',
            img: 'https://images.unsplash.com/photo-1535254973040-607b474cb50d?auto=format&fit=crop&w=160&q=80',
            qty: 12, total: 6600, deliveryDate: '14 Sep 2026', status: 'preparing',
            weight: '—', flavor: 'Chocolate', address: '42 Galle Road, Colombo 03',
        },
        {
            id: 'KC-10325', date: '12 Sep 2026', cake: 'White Wedding Classic Cake',
            img: 'https://images.unsplash.com/photo-1519915028121-7d3463d20b13?auto=format&fit=crop&w=160&q=80',
            qty: 1, total: 16000, deliveryDate: '16 Sep 2026', status: 'pending',
            weight: '2 kg', flavor: 'Vanilla Bean', address: '78 Flower Road, Colombo 07',
        },
        {
            id: 'KC-10271', date: '25 Aug 2026', cake: 'Blush Engagement Cake',
            img: 'https://images.unsplash.com/photo-1565958011703-44f9829ba187?auto=format&fit=crop&w=160&q=80',
            qty: 1, total: 11500, deliveryDate: '28 Aug 2026', status: 'delivering',
            weight: '1.5 kg', flavor: 'Butter Cake', address: '12 Dharmapala Mawatha, Colombo 07',
        },
        {
            id: 'KC-10188', date: '18 Aug 2026', cake: 'Disco Nights 21st Birthday Cake',
            img: 'https://images.unsplash.com/photo-1571115177098-24ec42ed204d?auto=format&fit=crop&w=160&q=80',
            qty: 1, total: 9000, deliveryDate: '21 Aug 2026', status: 'cancelled',
            weight: '1 kg', flavor: 'Coffee', address: '7 Bagatalle Road, Colombo 03',
        },
    ];

    const STATUS_LABEL = {
        pending: 'Pending', confirmed: 'Confirmed', preparing: 'Preparing',
        delivering: 'Out for Delivery', delivered: 'Delivered', cancelled: 'Cancelled',
    };

    function renderOrdersPage(filterStatus) {
        const list  = document.getElementById('ordersList');
        const empty = document.getElementById('ordersEmpty');
        if (!list) return;

        const filtered = (filterStatus === 'all')
            ? ORDERS
            : ORDERS.filter(o => o.status === filterStatus);

        if (filtered.length === 0) {
            list.innerHTML = '';
            empty?.removeAttribute('hidden');
        } else {
            empty?.setAttribute('hidden', '');
            list.innerHTML = filtered.map(o => `
                <article class="order-card" id="ocard-${_esc(o.id)}">
                    <div class="order-card-header">
                        <div>
                            <div class="order-number">Order #${_esc(o.id)}</div>
                            <div class="order-date">Ordered ${_esc(o.date)}</div>
                        </div>
                        <span class="order-status-badge status--${_esc(o.status)}">${_esc(STATUS_LABEL[o.status] || o.status)}</span>
                    </div>
                    <div class="order-card-body">
                        <img class="order-cake-img" src="${_esc(o.img)}" alt="${_esc(o.cake)}" loading="lazy">
                        <div class="order-cake-info">
                            <h4>${_esc(o.cake)}</h4>
                            <p>Weight: ${_esc(o.weight)} &nbsp;·&nbsp; Flavour: ${_esc(o.flavor)} &nbsp;·&nbsp; Qty: ${o.qty}</p>
                        </div>
                    </div>
                    <div class="order-card-footer">
                        <div>
                            <div class="order-total">Rs. ${o.total.toLocaleString()}</div>
                            <div class="order-delivery-date">📅 Delivery: ${_esc(o.deliveryDate)}</div>
                        </div>
                        <button class="order-view-btn" onclick="kukiToggleDetails('${_esc(o.id)}')">View Details ↓</button>
                    </div>
                    <dl class="order-details-content" id="odetails-${_esc(o.id)}" style="display:none">
                        <dt>Order Number</dt><dd>#${_esc(o.id)}</dd>
                        <dt>Order Date</dt>  <dd>${_esc(o.date)}</dd>
                        <dt>Delivery Date</dt><dd>${_esc(o.deliveryDate)}</dd>
                        <dt>Delivery Address</dt><dd>${_esc(o.address)}</dd>
                        <dt>Flavour</dt>     <dd>${_esc(o.flavor)}</dd>
                        <dt>Weight</dt>      <dd>${_esc(o.weight)}</dd>
                    </dl>
                </article>
            `).join('');
        }
    }

    window.kukiToggleDetails = function (orderId) {
        const details = document.getElementById(`odetails-${orderId}`);
        const btn     = document.getElementById(`ocard-${orderId}`)?.querySelector('.order-view-btn');
        if (!details) return;
        const hidden = details.style.display === 'none';
        details.style.display = hidden ? 'grid' : 'none';
        if (btn) btn.textContent = hidden ? 'Hide Details ↑' : 'View Details ↓';
    };

    function setupMyOrdersPage() {
        document.querySelectorAll('.orders-filter-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.orders-filter-btn').forEach(b => {
                    b.classList.remove('active');
                    b.setAttribute('aria-selected', 'false');
                });
                btn.classList.add('active');
                btn.setAttribute('aria-selected', 'true');
                renderOrdersPage(btn.dataset.status);
            });
        });
        renderOrdersPage('all');
    }

    function renderMiniOrders() {
        const container = document.getElementById('profileOrdersMini');
        if (!container) return;
        const recent = ORDERS.slice(0, 3);
        container.innerHTML = recent.map(o => `
            <div class="profile-order-mini">
                <div class="profile-order-mini-info">
                    <b>${_esc(o.cake)}</b>
                    <span>${_esc(o.date)} &nbsp;·&nbsp; #${_esc(o.id)}</span>
                </div>
                <div class="profile-order-mini-right">
                    <div class="profile-order-mini-price">Rs. ${o.total.toLocaleString()}</div>
                    <span class="order-status-badge status--${_esc(o.status)}" style="font-size:10px;padding:3px 8px">${_esc(STATUS_LABEL[o.status])}</span>
                </div>
            </div>
        `).join('');
    }

    /* ============================================================
       CART-MERGE BANNER dismiss
       ============================================================ */
    function setupCartMergeDismiss() {
        document.getElementById('cartMergeDismiss')?.addEventListener('click', () => {
            document.getElementById('cart-merge-banner')?.classList.remove('show');
        });
    }

    /* ============================================================
       PRIVATE HELPERS
       ============================================================ */

    function _toast(msg) {
        if (typeof showToast === 'function') { showToast(msg); return; }
        const el = document.querySelector('.toast');
        if (!el) return;
        el.textContent = msg;
        el.classList.add('show');
        setTimeout(() => el.classList.remove('show'), 3500);
    }

    function _applyFieldState(input, errEl, error) {
        if (!input || !errEl) return;
        errEl.textContent = error;
        input.classList.toggle('is-error', !!error);
        input.classList.toggle('is-ok', !error && input.value.length > 0);
    }

    function _setText(id, text) {
        const el = document.getElementById(id);
        if (el) el.textContent = text;
    }

    function _setVal(id, val) {
        const el = document.getElementById(id);
        if (el) el.value = val;
    }

    function _esc(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function setupToggle(btn) {
        btn.addEventListener('click', function () {
            _togglePasswordVisibility(this);
        });
    }

    function _togglePasswordVisibility(btn) {
        const input = btn.previousElementSibling;
        if (!input) return;
        const isText = input.type === 'text';
        input.type = isText ? 'password' : 'text';
        btn.textContent = isText ? '👁' : '🙈';
        btn.setAttribute('aria-label', isText ? 'Show password' : 'Hide password');
    }

    function updateStrength(pw, fillId, labelId) {
        const fill  = document.getElementById(fillId);
        const label = document.getElementById(labelId);
        if (!fill || !label) return;

        if (!pw) { fill.className = 'strength-fill'; label.textContent = ''; label.className = 'strength-label'; return; }

        let score = 0;
        if (pw.length >= 8)            score++;
        if (/[A-Z]/.test(pw) && /[a-z]/.test(pw)) score++;
        if (/[0-9]/.test(pw))          score++;
        if (/[^A-Za-z0-9]/.test(pw))  score++;

        const level = score <= 1 ? 'weak' : score <= 3 ? 'moderate' : 'strong';
        const text  = { weak: 'Weak', moderate: 'Moderate', strong: 'Strong' }[level];

        fill.className  = `strength-fill ${level}`;
        label.className = `strength-label ${level}`;
        label.textContent = text;
    }

    function updateReqs(pw, lenId, upperId, lowerId, numId) {
        const toggle = (id, met) => document.getElementById(id)?.classList.toggle('met', met);
        toggle(lenId,   pw.length >= 8);
        toggle(upperId, /[A-Z]/.test(pw));
        toggle(lowerId, /[a-z]/.test(pw));
        toggle(numId,   /[0-9]/.test(pw));
    }

    /* ============================================================
       INITIALIZATION
       ============================================================ */

    function initAuth() {
        // Clean up any legacy mock auth keys from previous browser sessions
        try {
            localStorage.removeItem('kukiUser');
            localStorage.removeItem('kukiPendingUser');
        } catch (_) {}

        initNavbar();             // check server session and update navbar
        setupUserMenuDropdown();
        setupLogoutButtons();
        setupSignupForm();
        setupLoginForm();
        setupProfilePage();
        setupMyOrdersPage();
        setupCheckoutProtection();
        setupCartMergeDismiss();

        // Handle direct deep-link to profile (e.g. hash #profile)
        const hash = location.hash.slice(1);
        if (hash === 'profile') setTimeout(populateProfilePage, 120);
    }

    initAuth();

})();
