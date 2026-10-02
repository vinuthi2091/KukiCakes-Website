const http = require('http');
const { spawn } = require('child_process');
const os = require('os');
const path = require('path');

const PORT = 9555;
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

    on(event, handler) {
        if (!this.events.has(event)) this.events.set(event, []);
        this.events.get(event).push(handler);
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

    async navigate(url) {
        await this.send('Page.navigate', { url });
        await sleep(500);
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

async function run() {
    console.log('--- Starting Route-Alias Verification ---');
    const tmp = path.join(os.tmpdir(), 'chrome_clean_' + Date.now());
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

    try {
        // ==============================================================
        // TEST 1: Unauthenticated direct visit to #account
        // ==============================================================
        console.log('\n[1] Navigating to http://localhost/KukiCakes-Website/#account (unauthenticated)...');
        await cdp.navigate('http://localhost/KukiCakes-Website/#account');
        await cdp.waitFor(async () => {
            const h = await cdp.eval('location.hash');
            return h === '#auth-guard';
        });

        let hash1 = await cdp.eval('location.hash');
        let authGuardActive1 = await cdp.eval('document.getElementById("auth-guard-page")?.classList.contains("active")');
        let profileActive1 = await cdp.eval('document.getElementById("profile-page")?.classList.contains("active")');

        console.log('Result 1: hash =', hash1, '| authGuardActive =', authGuardActive1, '| profileActive =', profileActive1);

        // ==============================================================
        // TEST 2: Unauthenticated direct visit to #profile
        // ==============================================================
        console.log('\n[2] Navigating to http://localhost/KukiCakes-Website/#profile (unauthenticated)...');
        await cdp.navigate('http://localhost/KukiCakes-Website/#profile');
        await cdp.waitFor(async () => {
            const h = await cdp.eval('location.hash');
            return h === '#auth-guard';
        });

        let hash2 = await cdp.eval('location.hash');
        let authGuardActive2 = await cdp.eval('document.getElementById("auth-guard-page")?.classList.contains("active")');
        let profileActive2 = await cdp.eval('document.getElementById("profile-page")?.classList.contains("active")');

        console.log('Result 2: hash =', hash2, '| authGuardActive =', authGuardActive2, '| profileActive =', profileActive2);

        // ==============================================================
        // TEST 3: Authenticate user
        // ==============================================================
        console.log('\n[3] Logging in as vinu@kukicakes.lk...');
        const loginRes = await cdp.eval(`
            fetch('api/login.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email: 'vinu@kukicakes.lk', password: 'Secret123' })
            }).then(r => r.json())
        `);
        console.log('Login result:', loginRes?.success ? 'SUCCESS' : 'FAILED');

        // ==============================================================
        // TEST 4: Authenticated direct visit to #account
        // ==============================================================
        console.log('\n[4] Navigating to http://localhost/KukiCakes-Website/#account (authenticated)...');
        await cdp.navigate('http://localhost/KukiCakes-Website/#account');
        await cdp.waitFor(async () => {
            const email = await cdp.eval('document.getElementById("profileEmail")?.textContent');
            return email && email !== '—';
        });

        let hash4 = await cdp.eval('location.hash');
        let authGuardActive4 = await cdp.eval('document.getElementById("auth-guard-page")?.classList.contains("active")');
        let profileActive4 = await cdp.eval('document.getElementById("profile-page")?.classList.contains("active")');
        let profileEmail4 = await cdp.eval('document.getElementById("profileEmail")?.textContent');
        let profileName4 = await cdp.eval('document.getElementById("profileName")?.textContent');

        console.log('Result 4: hash =', hash4, '| authGuardActive =', authGuardActive4, '| profileActive =', profileActive4);
        console.log('Profile dashboard displayed name =', profileName4, '| email =', profileEmail4);

        // ==============================================================
        // TEST 5: Authenticated direct visit to #profile
        // ==============================================================
        console.log('\n[5] Navigating to http://localhost/KukiCakes-Website/#profile (authenticated)...');
        await cdp.navigate('http://localhost/KukiCakes-Website/#profile');
        await cdp.waitFor(async () => {
            const email = await cdp.eval('document.getElementById("profileEmail")?.textContent');
            return email && email !== '—';
        });

        let hash5 = await cdp.eval('location.hash');
        let authGuardActive5 = await cdp.eval('document.getElementById("auth-guard-page")?.classList.contains("active")');
        let profileActive5 = await cdp.eval('document.getElementById("profile-page")?.classList.contains("active")');
        let profileEmail5 = await cdp.eval('document.getElementById("profileEmail")?.textContent');
        let profileName5 = await cdp.eval('document.getElementById("profileName")?.textContent');

        console.log('Result 5: hash =', hash5, '| authGuardActive =', authGuardActive5, '| profileActive =', profileActive5);
        console.log('Profile dashboard displayed name =', profileName5, '| email =', profileEmail5);

        // ==============================================================
        // TEST 6: Hashchange navigation between #account and #profile
        // ==============================================================
        console.log('\n[6] In-page hashchange testing...');
        await cdp.eval('window.location.hash = "#account"');
        await sleep(500);
        let hash6a = await cdp.eval('location.hash');
        let profileActive6a = await cdp.eval('document.getElementById("profile-page")?.classList.contains("active")');
        console.log('Hashchange to #account -> hash =', hash6a, '| profileActive =', profileActive6a);

        await cdp.eval('window.location.hash = "#profile"');
        await sleep(500);
        let hash6b = await cdp.eval('location.hash');
        let profileActive6b = await cdp.eval('document.getElementById("profile-page")?.classList.contains("active")');
        console.log('Hashchange to #profile -> hash =', hash6b, '| profileActive =', profileActive6b);

        // Check if both reach the same profile dashboard
        const dashboardIdentical = profileActive4 && profileActive5 && profileActive6a && profileActive6b && (profileEmail4 === profileEmail5);
        console.log('\n>>> Both #profile and #account reach the same profile dashboard:', dashboardIdentical ? 'YES (PASSED)' : 'NO (FAILED)');

        // Check unauthenticated redirects
        const unauthRedirectsWork = (hash1 === '#auth-guard' && authGuardActive1 && !profileActive1) &&
                                     (hash2 === '#auth-guard' && authGuardActive2 && !profileActive2);
        console.log('>>> Unauthenticated users visiting #account and #profile redirected to #auth-guard:', unauthRedirectsWork ? 'YES (PASSED)' : 'NO (FAILED)');

    } catch (e) {
        console.error('Error during test:', e);
    } finally {
        chrome.kill();
        try { require('fs').rmSync(tmp, { recursive: true, force: true }); } catch (_) {}
    }
}

run();
