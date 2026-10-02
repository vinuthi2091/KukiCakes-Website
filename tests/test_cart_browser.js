const http = require('http');
const { spawn } = require('child_process');
const os = require('os');
const path = require('path');

const PORT = 9557;
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

    async waitFor(fn, timeoutMs = 7000) {
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
    console.log('========================================================');
    console.log(' Testing Browser Cart Persistence & Server Synchronization');
    console.log('========================================================\n');

    const tmp = path.join(os.tmpdir(), 'chrome_cart_' + Date.now());
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

    for (let i = 0; i < 30; i++) {
        await sleep(300);
        try {
            const v = await getJson(`http://127.0.0.1:${PORT}/json/version`);
            if (v) break;
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
    function assert(cond, title, details = '') {
        if (cond) {
            console.log(`[PASS] ${title}`);
            passed++;
        } else {
            console.error(`[FAIL] ${title} ${details}`);
            failed++;
        }
    }

    try {
        // Step 1: Navigate to home as guest
        console.log('Step 1: Open website as guest');
        await cdp.send('Page.navigate', { url: 'http://localhost/KukiCakes-Website/#cakes' });
        await cdp.waitFor(async () => {
            return await cdp.eval('document.querySelectorAll(".product-card").length > 0');
        });

        // Step 2: Guest adds item to cart
        console.log('Step 2: Guest adds cake to cart');
        await cdp.eval(`
            const card = document.querySelector('.product-card');
            const btn = card?.querySelector('.add-btn');
            if (btn) btn.click();
        `);
        await sleep(500);
        await cdp.eval(`
            const modalAdd = document.getElementById('modalAdd');
            if (modalAdd) modalAdd.click();
        `);
        await sleep(500);

        const guestCount = await cdp.eval('document.getElementById("cartCount")?.textContent');
        assert(parseInt(guestCount) >= 1, 'Guest cart count updated in navbar', `Got: ${guestCount}`);

        const localCartRaw = await cdp.eval('localStorage.getItem("kukiCart")');
        const localCart = JSON.parse(localCartRaw || '[]');
        assert(localCart.length >= 1, 'Item saved to browser localStorage', `Count: ${localCart.length}`);

        // Step 3: Login as vinu@kukicakes.lk
        console.log('\nStep 3: Login as registered user vinu@kukicakes.lk');
        await cdp.send('Page.navigate', { url: 'http://localhost/KukiCakes-Website/#login' });
        await cdp.waitFor(async () => {
            return await cdp.eval('document.getElementById("li-email") !== null');
        });
        await sleep(300);

        await cdp.eval(`
            document.getElementById('li-email').value = 'vinu@kukicakes.lk';
            document.getElementById('li-password').value = 'Secret123';
            document.getElementById('loginForm').dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
        `);

        await cdp.waitFor(async () => {
            const cache = await cdp.eval('localStorage.getItem("kukiDisplay")');
            return cache !== null;
        });
        await sleep(1200); // Allow mergeCartOnLogin and debounce sync to finish

        // Verify cart count remains intact after login merge
        const postLoginCount = await cdp.eval('document.getElementById("cartCount")?.textContent');
        assert(parseInt(postLoginCount) >= 1, 'Cart items retained/merged on login', `Got: ${postLoginCount}`);

        // Step 4: Verify items synced to database
        console.log('\nStep 4: Verify items synced to server-side MySQL database');
        const dbCheck = await cdp.eval(`
            fetch('api/cart.php', { credentials: 'include' }).then(r => r.json())
        `);
        assert(
            dbCheck?.success === true && dbCheck?.authenticated === true && dbCheck?.cart?.length >= 1,
            'Database cart API confirms items saved to MySQL for authenticated customer',
            JSON.stringify(dbCheck)
        );

        // Step 5: Reload page to verify persistence across browser refresh
        console.log('\nStep 5: Reload page to verify persistence on fresh page load');
        await cdp.send('Page.navigate', { url: 'http://localhost/KukiCakes-Website/#cart' });
        await cdp.waitFor(async () => {
            return await cdp.eval('document.querySelectorAll(".cart-item").length > 0');
        });

        const reloadedCount = await cdp.eval('document.getElementById("cartCount")?.textContent');
        const renderedCartItems = await cdp.eval('document.querySelectorAll(".cart-item").length');
        assert(parseInt(reloadedCount) >= 1 && renderedCartItems >= 1, 'Cart persisted and rendered from database after page reload');

        // Step 6: Test logout isolation
        console.log('\nStep 6: User logs out');
        await cdp.eval(`
            const logoutBtn = document.getElementById('logoutBtn') || document.getElementById('profileLogoutBtn');
            if (logoutBtn) logoutBtn.click();
        `);
        await sleep(800);

        const postLogoutCount = await cdp.eval('document.getElementById("cartCount")?.textContent');
        const postLogoutStorage = await cdp.eval('localStorage.getItem("kukiCart")');
        assert(
            parseInt(postLogoutCount) === 0 && (postLogoutStorage === '[]' || postLogoutStorage === null),
            'Cart emptied on logout to prevent exposing previous user cart',
            `Count: ${postLogoutCount}, storage: ${postLogoutStorage}`
        );

    } catch (e) {
        console.error('Test error:', e);
        failed++;
    } finally {
        try { chrome.kill(); } catch (_) {}
    }

    console.log('\n========================================================');
    console.log(` Summary: ${passed} passed, ${failed} failed.`);
    console.log('========================================================\n');

    if (failed > 0) process.exit(1);
}

run();
