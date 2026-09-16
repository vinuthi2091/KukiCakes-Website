/* ================================================================
   KúkiCakes — Frontend Authentication Module   (js/auth.js)
   ----------------------------------------------------------------
   FRONTEND SIMULATION ONLY — localStorage-based mock auth.
   No real backend, no real sessions, no password hashing.
   All localStorage calls are isolated in getUser / setUser /
   clearUser so they can be replaced with API calls later.

   Replace TODO: API stubs are marked with  ← REPLACE WITH API
   ================================================================ */

(function () {
    'use strict';

    /* ============================================================
       CONSTANTS
       ============================================================ */
    const STORAGE_KEY = 'kukiUser';
    const PENDING_KEY = 'kukiPendingUser';   // temp registration data
    const MERGE_KEY = 'kukiCartMergeShown';

    /* ============================================================
       AUTH STATE HELPERS
       Ready to replace getUser / setUser / clearUser with real
       API calls (fetch POST /api/login, GET /api/profile, etc.)
       ============================================================ */

    function getUser() {
        // ← REPLACE WITH API: validate session/token server-side
        try { return JSON.parse(localStorage.getItem(STORAGE_KEY)); }
        catch { return null; }
    }

    function setUser(userData) {
        // ← REPLACE WITH API: store session cookie/JWT instead
        localStorage.setItem(STORAGE_KEY, JSON.stringify(userData));
        updateNavbar();
    }

    function clearUser() {
        // ← REPLACE WITH API: invalidate session/token server-side
        localStorage.removeItem(STORAGE_KEY);
        localStorage.removeItem(MERGE_KEY);
        updateNavbar();
    }

    // Expose for potential use from other scripts / future API layer
    window.kukiAuth = { getUser, setUser, clearUser };

    /* ============================================================
       NAVBAR — guest / authenticated states
       ============================================================ */

    function updateNavbar() {
        const user = getUser();
        const navGuest = document.getElementById('navGuest');
        const navUser  = document.getElementById('navUser');
        const userAvatar   = document.getElementById('userAvatar');
        const userDispName = document.getElementById('userDisplayName');

        if (!navGuest || !navUser) return;

        if (user) {
            // Use .hidden property — correctly removes the HTML `hidden` attribute
            // (style.display = '' alone does NOT remove the hidden attribute)
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

    /* ============================================================
       USER DROPDOWN (nav)
       ============================================================ */

    function setupUserMenuDropdown() {
        const btn = document.getElementById('userMenuBtn');
        const dropdown = document.getElementById('userDropdown');
        if (!btn || !dropdown) return;

        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const open = dropdown.classList.toggle('open');
            btn.setAttribute('aria-expanded', String(open));
        });

        // Close on outside click
        document.addEventListener('click', () => {
            dropdown.classList.remove('open');
            btn.setAttribute('aria-expanded', 'false');
        });

        // Close when a menu item is chosen
        dropdown.addEventListener('click', () => {
            dropdown.classList.remove('open');
            btn.setAttribute('aria-expanded', 'false');
        });
    }

    /* ============================================================
       LOGOUT
       ============================================================ */

    function handleLogout() {
        clearUser();
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
       ============================================================ */

    const PROTECTED = ['profile', 'my-orders'];

    document.addEventListener('click', (e) => {
        const link = e.target.closest('[data-page]');
        if (!link) return;

        const page = link.dataset.page;

        if (PROTECTED.includes(page)) {
            if (!getUser()) {
                e.stopImmediatePropagation();
                e.preventDefault();
                if (typeof goPage === 'function') goPage('auth-guard');
                return;
            }
            // User is logged in — let site.js handle navigation, then populate data
            if (page === 'profile') setTimeout(populateProfilePage, 30);
            if (page === 'my-orders') setTimeout(() => renderOrdersPage('all'), 30);
        }
    }, true); // capture phase

    /* ============================================================
       CHECKOUT PROTECTION
       Overrides the checkout button click set in site.js.
       Runs after site.js, so this .onclick wins.
       ============================================================ */

    function setupCheckoutProtection() {
        const btn = document.getElementById('checkoutBtn');
        if (!btn) return;

        btn.onclick = () => {
            // Re-read cart from localStorage (site.js stores it there)
            let cart = [];
            try { cart = JSON.parse(localStorage.getItem('kukiCart') || '[]'); } catch { }

            if (cart.length === 0) {
                _toast('Add a cake before checking out 🎂');
                return;
            }
            if (!getUser()) {
                if (typeof goPage === 'function') goPage('auth-guard');
            } else {
                if (typeof goPage === 'function') goPage('checkout');
            }
        };
    }

    /* ============================================================
       CART MERGE NOTIFICATION
       Show once per login when the cart is non-empty.
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

        // Live strength + requirements for password field
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

        form.addEventListener('submit', (e) => {
            e.preventDefault();
            const ok = ['name', 'email', 'phone', 'password', 'confirm'].every(validateSignupField);
            if (!ok) return;

            // ← REPLACE WITH API: POST /api/register
            const userData = {
                name: document.getElementById('su-name').value.trim(),
                email: document.getElementById('su-email').value.trim().toLowerCase(),
                phone: document.getElementById('su-phone').value.trim(),
                joinDate: _fmtDate(new Date()),
            };

            // Temporarily store so login page can pre-fill
            localStorage.setItem(PENDING_KEY, JSON.stringify(userData));

            // Show success state
            form.style.display = 'none';
            const successEl = document.getElementById('signupSuccess');
            if (successEl) successEl.removeAttribute('hidden');
        });
    }

    function validateSignupField(field) {
        const map = {
            name: ['su-name', 'su-name-err'],
            email: ['su-email', 'su-email-err'],
            phone: ['su-phone', 'su-phone-err'],
            password: ['su-password', 'su-password-err'],
            confirm: ['su-confirm', 'su-confirm-err'],
        };
        const [inputId, errId] = map[field];
        const input = document.getElementById(inputId);
        const errEl = document.getElementById(errId);
        if (!input || !errEl) return true;

        const val = input.value.trim();
        let error = '';

        switch (field) {
            case 'name':
                if (!val) error = 'Full name is required.';
                else if (val.length < 2) error = 'Name must be at least 2 characters.';
                break;
            case 'email':
                if (!val) error = 'Email address is required.';
                else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val)) error = 'Please enter a valid email address.';
                break;
            case 'phone':
                if (!val) error = 'Phone number is required.';
                else if (!/^[+\d\s\-()]{7,20}$/.test(val)) error = 'Please enter a valid phone number.';
                break;
            case 'password': {
                if (!val) error = 'Password is required.';
                else if (val.length < 8) error = 'Password must be at least 8 characters.';
                else if (!/[A-Z]/.test(val)) error = 'Include at least one uppercase letter.';
                else if (!/[0-9]/.test(val)) error = 'Include at least one number.';
                break;
            }
            case 'confirm': {
                const pw = document.getElementById('su-password')?.value || '';
                if (!val) error = 'Please confirm your password.';
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

        // Forgot password
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
            // ← REPLACE WITH API: POST /api/forgot-password
            _toast('Reset link sent! (UI simulation only) 📧');
        });

        // Blur validation
        document.getElementById('li-email')?.addEventListener('blur', () => validateLoginField('email'));
        document.getElementById('li-password')?.addEventListener('blur', () => validateLoginField('password'));

        form.addEventListener('submit', (e) => {
            e.preventDefault();
            const ok = validateLoginField('email') & validateLoginField('password');
            if (!ok) return;

            const emailVal = document.getElementById('li-email').value.trim().toLowerCase();

            // ← REPLACE WITH API: POST /api/login  { email, password }
            // Check if a pending registration exists for this email
            let userData = null;
            try { userData = JSON.parse(localStorage.getItem(PENDING_KEY)); } catch { }

            if (!userData || userData.email !== emailVal) {
                // Generate a plausible display name from the email
                userData = {
                    name: _nameFromEmail(emailVal),
                    email: emailVal,
                    phone: '+94 77 000 0000',
                    joinDate: _fmtDate(new Date()),
                };
            }

            setUser(userData);
            maybeShowCartMergeBanner(userData.name.split(' ')[0]);

            if (typeof goPage === 'function') goPage('profile');
            setTimeout(populateProfilePage, 60);
        });
    }

    function validateLoginField(field) {
        const map = {
            email: ['li-email', 'li-email-err'],
            password: ['li-password', 'li-password-err'],
        };
        const [inputId, errId] = map[field];
        const input = document.getElementById(inputId);
        const errEl = document.getElementById(errId);
        if (!input || !errEl) return true;

        const val = input.value.trim();
        let error = '';

        if (field === 'email') {
            if (!val) error = 'Email address is required.';
            else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val)) error = 'Please enter a valid email address.';
        } else {
            if (!input.value) error = 'Password is required.';
        }

        _applyFieldState(input, errEl, error);
        return !error;
    }

    /* ============================================================
       PROFILE PAGE — populate & interactions
       ============================================================ */

    function populateProfilePage() {
        const user = getUser();
        if (!user) return;

        const initials = (user.name || 'K')
            .split(' ').filter(Boolean)
            .map(n => n[0].toUpperCase()).slice(0, 2).join('');

        _setText('profileAvatar', initials);
        _setText('profileSidebarName', user.name || '—');
        _setText('profileSidebarEmail', user.email || '—');
        _setText('profileName', user.name || '—');
        _setText('profileJoinDate', user.joinDate || '—');
        _setText('profileEmail', user.email || '—');
        _setText('profilePhone', user.phone || 'Not provided');

        // Pre-fill email in contact edit form (read-only)
        const emailDisp = document.getElementById('profileEmailDisplay');
        if (emailDisp) emailDisp.value = user.email || '';

        // Mini orders list
        renderMiniOrders();
    }

    function setupProfilePage() {
        // Panel switching via sidebar buttons
        document.querySelectorAll('.sidebar-nav-item[data-panel]').forEach(btn => {
            btn.addEventListener('click', () => {
                const panel = btn.dataset.panel;

                // Update active sidebar item
                document.querySelectorAll('.sidebar-nav-item').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');

                // Show matching panel
                document.querySelectorAll('.profile-panel').forEach(p => p.classList.remove('active'));
                document.getElementById(`panel-${panel}`)?.classList.add('active');

                // Special: orders panel → go to full orders page
                if (panel === 'orders') {
                    if (typeof goPage === 'function') goPage('my-orders');
                    setTimeout(() => renderOrdersPage('all'), 50);
                }
            });
        });

        setupEditableSection('personal', 'personalDisplay', 'personalEdit', 'personalEditBtn', 'personalSaveBtn', 'personalCancelBtn', savePersInfo);
        setupEditableSection('contact', 'contactDisplay', 'contactEdit', 'contactEditBtn', 'contactSaveBtn', 'contactCancelBtn', saveContactInfo);

        setupChangePasswordForm();
        setupAddressManagement();

        if (getUser()) populateProfilePage();
    }

    function setupEditableSection(prefix, displayId, editId, editBtnId, saveBtnId, cancelBtnId, saveFn) {
        const displayEl = document.getElementById(displayId);
        const editEl = document.getElementById(editId);
        const editBtn = document.getElementById(editBtnId);
        const saveBtn = document.getElementById(saveBtnId);
        const cancelBtn = document.getElementById(cancelBtnId);

        if (!editBtn || !saveBtn || !cancelBtn) return;

        editBtn.addEventListener('click', () => {
            displayEl?.classList.add('hidden');
            editEl?.classList.remove('hidden');
            editBtn.style.display = 'none';
            // Pre-fill edit inputs
            if (prefix === 'personal') {
                const input = document.getElementById('editNameInput');
                if (input) input.value = document.getElementById('profileName')?.textContent || '';
            }
            if (prefix === 'contact') {
                const input = document.getElementById('editPhoneInput');
                if (input) input.value = document.getElementById('profilePhone')?.textContent || '';
                const emailDisp = document.getElementById('profileEmailDisplay');
                if (emailDisp) emailDisp.value = getUser()?.email || '';
            }
        });

        cancelBtn.addEventListener('click', () => {
            displayEl?.classList.remove('hidden');
            editEl?.classList.add('hidden');
            editBtn.style.display = '';
        });

        saveBtn.addEventListener('click', () => {
            const ok = saveFn();
            if (!ok) return;
            displayEl?.classList.remove('hidden');
            editEl?.classList.add('hidden');
            editBtn.style.display = '';
            _toast('Changes saved ✓');
        });
    }

    function savePersInfo() {
        const input = document.getElementById('editNameInput');
        const errEl = document.getElementById('editNameErr');
        const newName = input?.value.trim();
        if (!newName) {
            _applyFieldState(input, errEl, 'Full name is required.');
            return false;
        }
        _applyFieldState(input, errEl, '');
        const user = getUser();
        if (!user) return false;
        user.name = newName;
        setUser(user);
        _setText('profileName', newName);
        _setText('profileSidebarName', newName);
        _setText('profileAvatar', newName.split(' ').filter(Boolean).map(n => n[0].toUpperCase()).slice(0, 2).join(''));
        _setText('userAvatar', newName.split(' ').filter(Boolean).map(n => n[0].toUpperCase()).slice(0, 2).join(''));
        _setText('userDisplayName', newName.split(' ')[0]);
        return true;
    }

    function saveContactInfo() {
        const input = document.getElementById('editPhoneInput');
        const errEl = document.getElementById('editPhoneErr');
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
        const user = getUser();
        if (!user) return false;
        user.phone = newPhone;
        setUser(user);
        _setText('profilePhone', newPhone);
        return true;
    }

    /* ============================================================
       CHANGE PASSWORD FORM
       ============================================================ */

    function setupChangePasswordForm() {
        const form = document.getElementById('changePwForm');
        if (!form) return;

        // Show/hide toggles
        form.querySelectorAll('.password-toggle').forEach(setupToggle);

        // Strength for new password
        document.getElementById('cp-new')?.addEventListener('input', function () {
            updateStrength(this.value, 'cp-strength-fill', 'cp-strength-label');
            updateReqs(this.value, 'cp-req-len', 'cp-req-upper', 'cp-req-lower', 'cp-req-num');
        });

        form.addEventListener('submit', (e) => {
            e.preventDefault();
            let ok = true;

            const current = document.getElementById('cp-current');
            const newPw = document.getElementById('cp-new');
            const confirm = document.getElementById('cp-confirm');

            if (!current?.value) { _applyFieldState(current, document.getElementById('cp-current-err'), 'Current password is required.'); ok = false; }
            else _applyFieldState(current, document.getElementById('cp-current-err'), '');

            if (!newPw?.value || newPw.value.length < 8) {
                _applyFieldState(newPw, document.getElementById('cp-new-err'), 'New password must be at least 8 characters.');
                ok = false;
            } else _applyFieldState(newPw, document.getElementById('cp-new-err'), '');

            if (confirm?.value !== newPw?.value) {
                _applyFieldState(confirm, document.getElementById('cp-confirm-err'), 'Passwords do not match.');
                ok = false;
            } else _applyFieldState(confirm, document.getElementById('cp-confirm-err'), '');

            if (!ok) return;

            // ← REPLACE WITH API: POST /api/change-password
            const successEl = document.getElementById('changePwSuccess');
            if (successEl) successEl.classList.add('show');
            form.reset();
            document.getElementById('cp-strength-fill').className = 'strength-fill';
            document.getElementById('cp-strength-label').textContent = '';
            document.querySelectorAll('#cp-pw-reqs li').forEach(li => li.classList.remove('met'));
            setTimeout(() => successEl?.classList.remove('show'), 5000);
        });
    }

    /* ============================================================
       ADDRESS MANAGEMENT (Shipping & Billing)
       ============================================================ */

    // Dummy seed data (frontend-only; replace with API calls)
    const ADDR_DB = {
        shipping: [
            { id: 1, name: 'Amaya Perera', line1: '42 Galle Road', line2: 'Colombo 03', city: 'Colombo', postal: '00300', phone: '+94 77 123 4567', isDefault: true },
        ],
        billing: [
            { id: 1, name: 'Amaya Perera', line1: '42 Galle Road', line2: 'Colombo 03', city: 'Colombo', postal: '00300', phone: '+94 77 123 4567', isDefault: true },
        ],
    };

    function setupAddressManagement() {
        renderAddresses('shipping');
        renderAddresses('billing');
        setupAddressModal();
    }

    function renderAddresses(type) {
        const grid = document.getElementById(`${type}AddrGrid`);
        if (!grid) return;

        const list = ADDR_DB[type] || [];
        grid.innerHTML = list.map(addr => `
            <div class="address-card${addr.isDefault ? ' is-default' : ''}">
                ${addr.isDefault ? '<span class="address-default-badge">✦ Default</span>' : ''}
                <div class="address-name">${_esc(addr.name)}</div>
                <div class="address-line">
                    ${_esc(addr.line1)}<br>
                    ${addr.line2 ? _esc(addr.line2) + '<br>' : ''}
                    ${_esc(addr.city)}${addr.postal ? ', ' + _esc(addr.postal) : ''}<br>
                    ${_esc(addr.phone)}
                </div>
                <div class="address-actions">
                    ${!addr.isDefault
                ? `<button class="address-btn" onclick="kukiSetDefault('${type}',${addr.id})">Set Default</button>`
                : ''}
                    <button class="address-btn" onclick="kukiEditAddr('${type}',${addr.id})">Edit</button>
                    <button class="address-btn address-btn-delete" onclick="kukiDeleteAddr('${type}',${addr.id})">Delete</button>
                </div>
            </div>
        `).join('') + `
            <button class="add-address-card" data-addr-type="${type}">
                <span class="add-addr-icon">＋</span>
                <span>Add New Address</span>
            </button>
        `;

        // Bind the add-address button created in innerHTML
        grid.querySelector(`[data-addr-type="${type}"]`)?.addEventListener('click', () => {
            openAddressModal(type, null);
        });
    }

    // Global helpers (called from inline onclick)
    window.kukiSetDefault = function (type, id) {
        ADDR_DB[type]?.forEach(a => a.isDefault = (a.id === id));
        renderAddresses(type);
        _toast('Default address updated ✓');
    };
    window.kukiEditAddr = function (type, id) {
        const addr = ADDR_DB[type]?.find(a => a.id === id);
        if (addr) openAddressModal(type, addr);
    };
    window.kukiDeleteAddr = function (type, id) {
        if (!confirm('Remove this address?')) return;
        ADDR_DB[type] = (ADDR_DB[type] || []).filter(a => a.id !== id);
        renderAddresses(type);
        _toast('Address removed.');
    };

    // Modal state
    let _modalType = null;
    let _modalId = null;

    function openAddressModal(type, addr) {
        _modalType = type;
        _modalId = addr ? addr.id : null;

        const overlay = document.getElementById('addressModalOverlay');
        if (!overlay) return;

        _setText('addrModalTitle', addr ? 'Edit Address' : 'Add New Address');
        _setVal('addrName', addr?.name || '');
        _setVal('addrLine1', addr?.line1 || '');
        _setVal('addrLine2', addr?.line2 || '');
        _setVal('addrCity', addr?.city || '');
        _setVal('addrPostal', addr?.postal || '');
        _setVal('addrPhone', addr?.phone || '');

        overlay.classList.add('open');
        document.getElementById('addrName')?.focus();
    }

    function closeAddressModal() {
        document.getElementById('addressModalOverlay')?.classList.remove('open');
        _modalType = null;
        _modalId = null;
    }

    function setupAddressModal() {
        document.getElementById('addrModalClose')?.addEventListener('click', closeAddressModal);
        document.getElementById('addrModalCancel')?.addEventListener('click', closeAddressModal);

        // Close on backdrop click
        document.getElementById('addressModalOverlay')?.addEventListener('click', (e) => {
            if (e.target === e.currentTarget) closeAddressModal();
        });

        // Escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeAddressModal();
        });

        document.getElementById('addrSaveBtn')?.addEventListener('click', () => {
            const name = document.getElementById('addrName')?.value.trim();
            const line1 = document.getElementById('addrLine1')?.value.trim();
            const city = document.getElementById('addrCity')?.value.trim();

            if (!name || !line1 || !city) {
                _toast('Please fill in Name, Address, and City.');
                return;
            }

            const type = _modalType;
            if (!type) return;

            const newAddr = {
                id: _modalId || Date.now(),
                name,
                line1,
                line2: document.getElementById('addrLine2')?.value.trim() || '',
                city,
                postal: document.getElementById('addrPostal')?.value.trim() || '',
                phone: document.getElementById('addrPhone')?.value.trim() || '',
                isDefault: ADDR_DB[type].length === 0,
            };

            if (_modalId) {
                const idx = ADDR_DB[type].findIndex(a => a.id === _modalId);
                if (idx >= 0) ADDR_DB[type][idx] = { ...ADDR_DB[type][idx], ...newAddr };
            } else {
                ADDR_DB[type].push(newAddr);
            }

            renderAddresses(type);
            closeAddressModal();
            _toast('Address saved ✓');
        });
    }

    /* ============================================================
       MY ORDERS — dummy data + rendering
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
        const list = document.getElementById('ordersList');
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
        const btn = document.getElementById(`ocard-${orderId}`)?.querySelector('.order-view-btn');
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

    // Mini orders in the profile panel
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
        // Re-use site.js showToast if available, otherwise fallback
        if (typeof showToast === 'function') { showToast(msg); return; }
        // Minimal fallback
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

    function _fmtDate(d) {
        return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
    }

    function _nameFromEmail(email) {
        return (email.split('@')[0] || 'User')
            .split(/[._\-]/)
            .map(p => p.charAt(0).toUpperCase() + p.slice(1))
            .join(' ');
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
        const fill = document.getElementById(fillId);
        const label = document.getElementById(labelId);
        if (!fill || !label) return;

        if (!pw) { fill.className = 'strength-fill'; label.textContent = ''; label.className = 'strength-label'; return; }

        let score = 0;
        if (pw.length >= 8) score++;
        if (/[A-Z]/.test(pw) && /[a-z]/.test(pw)) score++;
        if (/[0-9]/.test(pw)) score++;
        if (/[^A-Za-z0-9]/.test(pw)) score++;

        const level = score <= 1 ? 'weak' : score <= 3 ? 'moderate' : 'strong';
        const text = { weak: 'Weak', moderate: 'Moderate', strong: 'Strong' }[level];

        fill.className = `strength-fill ${level}`;
        label.className = `strength-label ${level}`;
        label.textContent = text;
    }

    function updateReqs(pw, lenId, upperId, lowerId, numId) {
        const toggle = (id, met) => document.getElementById(id)?.classList.toggle('met', met);
        toggle(lenId, pw.length >= 8);
        toggle(upperId, /[A-Z]/.test(pw));
        toggle(lowerId, /[a-z]/.test(pw));
        toggle(numId, /[0-9]/.test(pw));
    }

    /* ============================================================
       INITIALIZATION
       ============================================================ */

    function initAuth() {
        updateNavbar();
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
        if (hash === 'profile' && getUser()) setTimeout(populateProfilePage, 120);
    }

    initAuth();


})();
