const http = require('http');
const { spawn } = require('child_process');
const os = require('os');
const path = require('path');

const PORT = 9666;
const CHROME_PATH = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';

function sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

function getJson(url) {
    return new Promise((resolve, reject) => {
        http.get(url, res => {
            let d = '';
            res.on('data', c => d += c);
            res.on('end', () => {
                try { resolve(JSON.parse(d)); } catch (e) { reject(e); }
            });
        }).on('error', reject);
    });
}

class CDP {
    constructor(wsUrl) {
        this.ws = new WebSocket(wsUrl);
        this.id = 0;
        this.callbacks = new Map();
        this.events = new Map();

        this.ws.onmessage = (e) => {
            const msg = JSON.parse(e.data);
            if (msg.id && this.callbacks.has(msg.id)) {
                const cb = this.callbacks.get(msg.id);
                this.callbacks.delete(msg.id);
                if (msg.error) cb.reject(msg.error);
                else cb.resolve(msg.result);
            } else if (msg.method) {
                if (msg.method === 'Runtime.consoleAPICalled') {
                    console.log('  [CONSOLE]', msg.params.type, msg.params.args.map(a => a.value || a.description).join(' '));
                }
                if (msg.method === 'Runtime.exceptionThrown') {
                    console.error('  [EXCEPTION]', msg.params.exceptionDetails?.text, msg.params.exceptionDetails?.exception?.description);
                }
                const handlers = this.events.get(msg.method) || [];
                handlers.forEach(h => h(msg.params));
            }
        };
    }

    async ready() {
        if (this.ws.readyState === WebSocket.OPEN) return;
        return new Promise(r => this.ws.onopen = r);
    }

    send(method, params = {}) {
        const curId = ++this.id;
        return new Promise((resolve, reject) => {
            this.callbacks.set(curId, { resolve, reject });
            this.ws.send(JSON.stringify({ id: curId, method, params }));
        });
    }

    async eval(expr) {
        const res = await this.send('Runtime.evaluate', {
            expression: expr,
            returnByValue: true,
            awaitPromise: true
        });
        return res?.result?.value;
    }

    async waitFor(fn, timeoutMs = 5000) {
        const start = Date.now();
        while (Date.now() - start < timeoutMs) {
            try {
                const res = await fn();
                if (res) return res;
            } catch (_) {}
            await sleep(150);
        }
        return await fn();
    }
}

async function testFlow() {
    console.log('========================================================');
    console.log(' Testing Forgot Password / Password Reset User Flow');
    console.log('========================================================');

    const tmp = path.join(os.tmpdir(), 'chrome_pwreset_' + Date.now());
    const chrome = spawn(CHROME_PATH, [
        '--headless=new',
        `--remote-debugging-port=${PORT}`,
        '--disable-extensions',
        '--disable-background-networking',
        '--no-first-run',
        '--no-default-browser-check',
        `--user-data-dir=${tmp}`,
        'about:blank'
    ]);

    let version;
    for (let i = 0; i < 30; i++) {
        await sleep(300);
        try {
            version = await getJson(`http://127.0.0.1:${PORT}/json/version`);
            if (version) break;
        } catch (_) {}
    }

    const list = await getJson(`http://127.0.0.1:${PORT}/json/list`);
    const pageTarget = list.find(t => t.type === 'page') || list[0];
    const cdp = new CDP(pageTarget.webSocketDebuggerUrl);
    await cdp.ready();
    await cdp.send('Page.enable');
    await cdp.send('Runtime.enable');

    let passed = 0;
    let failed = 0;
    function assert(cond, name, details = '') {
        if (cond) {
            console.log(`[PASS] ${name}`);
            passed++;
        } else {
            console.error(`[FAIL] ${name} ${details}`);
            failed++;
        }
    }

    try {
        // Step 1: Open #login
        console.log('\nStep 1: Navigate to #login');
        await cdp.send('Page.navigate', { url: 'http://localhost/KukiCakes-Website/#login' });
        await cdp.waitFor(async () => {
            return await cdp.eval('document.getElementById("loginFormSection") !== null');
        });
        await sleep(1000);

        let loginFormVisible = await cdp.eval('!document.getElementById("loginFormSection")?.hidden');
        assert(loginFormVisible, 'Step 1: Login form section is visible');

        // Step 2: Click Forgot Password button
        console.log('\nStep 2: Click "Forgot Password?" button');
        await cdp.eval('document.getElementById("forgotPasswordBtn")?.click()');
        await sleep(300);

        let forgotSectionVisible = await cdp.eval('!document.getElementById("forgotPasswordSection")?.hidden');
        let loginSectionHidden = await cdp.eval('!!document.getElementById("loginFormSection")?.hidden');
        assert(forgotSectionVisible && loginSectionHidden, 'Step 2: Forgot password form displayed');

        // Step 3: Enter registered email and submit
        console.log('\nStep 3: Enter email vinu@kukicakes.lk and submit forgot password form');
        await cdp.eval(`
            document.getElementById("fp-email").value = "vinu@kukicakes.lk";
            document.getElementById("forgotPasswordForm").dispatchEvent(new Event("submit", { cancelable: true }));
        `);

        // Step 4: Wait for status box to show reset link
        await cdp.waitFor(async () => {
            const box = await cdp.eval('document.getElementById("fp-status-box")');
            const hidden = await cdp.eval('document.getElementById("fp-status-box")?.hidden');
            return box && !hidden;
        });

        let statusBoxText = await cdp.eval('document.getElementById("fp-status-box")?.textContent');
        let resetBtnExists = await cdp.eval('!!document.getElementById("fpGoResetBtn")');
        assert(resetBtnExists, 'Step 4: Status box generated dev reset link button (fpGoResetBtn)');
        console.log('Status box snippet:', statusBoxText?.replace(/\\s+/g, ' ').slice(0, 100));

        // Step 5: Click Reset Password Now button
        console.log('\nStep 5: Click "Reset Password Now →"');
        await cdp.eval('document.getElementById("fpGoResetBtn")?.click()');

        // Step 6: Wait for reset password page and token validation
        await cdp.waitFor(async () => {
            const formHidden = await cdp.eval('document.getElementById("resetPasswordForm")?.hidden');
            return formHidden === false;
        }, 6000);

        let resetPageActive = await cdp.eval('document.getElementById("reset-password-page")?.classList.contains("active")');
        let resetFormVisible = await cdp.eval('!document.getElementById("resetPasswordForm")?.hidden');
        let tokenInInput = await cdp.eval('document.getElementById("rp-token")?.value');
        let accountEmail = await cdp.eval('document.getElementById("rp-user-email")?.textContent');

        assert(resetPageActive, 'Step 6a: Reset password page is active');
        assert(resetFormVisible, 'Step 6b: Reset password form is visible after token validation');
        assert(!!tokenInInput, 'Step 6c: Reset token is populated in hidden field', `(token length: ${tokenInInput?.length})`);
        console.log('Masked email on reset form:', accountEmail);

        // Step 7: Enter new password and confirm
        console.log('\nStep 7: Enter new password "VinuNewPass123" and submit');
        await cdp.eval(`
            document.getElementById("rp-password").value = "VinuNewPass123";
            document.getElementById("rp-confirm").value = "VinuNewPass123";
            document.getElementById("resetPasswordForm").dispatchEvent(new Event("submit", { cancelable: true }));
        `);

        // Step 8: Wait for success box
        await cdp.waitFor(async () => {
            const success = await cdp.eval('!document.getElementById("rp-success")?.hidden');
            return success;
        }, 5000);

        let successVisible = await cdp.eval('!document.getElementById("rp-success")?.hidden');
        assert(successVisible, 'Step 8: Password updated success box is visible');

        // Step 9: Verify old password rejected
        console.log('\nStep 9: Verify old password "Secret123" is now rejected');
        const oldLoginRes = await cdp.eval(`
            fetch('api/login.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email: 'vinu@kukicakes.lk', password: 'Secret123' })
            }).then(r => ({ status: r.status })).catch(e => ({ error: e.message }))
        `);
        assert(oldLoginRes.status === 401, 'Step 9: Old password rejected with HTTP 401', `(status: ${oldLoginRes.status})`);

        // Step 10: Verify new password accepted
        console.log('\nStep 10: Verify new password "VinuNewPass123" is accepted');
        const newLoginRes = await cdp.eval(`
            fetch('api/login.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email: 'vinu@kukicakes.lk', password: 'VinuNewPass123' })
            }).then(r => r.json()).catch(e => ({ error: e.message }))
        `);
        assert(newLoginRes && newLoginRes.success === true, 'Step 10: New password login succeeds (HTTP 200)');

        // Step 11: Restore password back to Secret123 so regression tests stay aligned
        await cdp.eval(`
            fetch('api/change-password.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    current_password: 'VinuNewPass123',
                    new_password: 'Secret123',
                    confirm_password: 'Secret123'
                })
            })
        `);
        console.log('Restored test password back to Secret123 for regression test consistency.');

        console.log('\n========================================================');
        console.log(` Summary: ${passed} passed, ${failed} failed.`);
        console.log('========================================================');

    } catch (e) {
        console.error('Flow test error:', e);
    } finally {
        chrome.kill();
        try { require('fs').rmSync(tmp, { recursive: true, force: true }); } catch (_) {}
    }
}

testFlow();
