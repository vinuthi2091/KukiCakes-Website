(function () {
    'use strict';

    const AUTH_PAGES = ['login', 'signup', 'profile', 'my-orders', 'auth-guard'];

    // Holds any page the user tried to navigate to before components finished loading
    let _pendingPage = null;

    /**
     * Intercept clicks on auth nav buttons (Log In / Sign Up) that fire before
     * the components have loaded. We store the target page and replay it once
     * the fragment is in the DOM.
     */
    function interceptEarlyClicks() {
        document.addEventListener('click', function earlyGuard(e) {
            const link = e.target.closest('[data-page]');
            if (!link) return;
            const page = link.dataset.page;
            if (AUTH_PAGES.includes(page) && !document.getElementById(`${page}-page`)) {
                // Auth page not in DOM yet — queue it and suppress site.js navigation
                e.stopImmediatePropagation();
                e.preventDefault();
                _pendingPage = page;
            }
        }, true); // capture phase — runs before site.js bubble handler
    }

    async function loadAuthComponents() {
        // Install early-click guard before any fetch delay
        interceptEarlyClicks();

        const fragment = document.createDocumentFragment();

        for (const name of AUTH_PAGES) {
            try {
                const response = await fetch(`components/${name}.html`);
                if (!response.ok) throw new Error(`Could not load components/${name}.html`);

                const html = await response.text();
                const wrapper = document.createElement('div');
                wrapper.innerHTML = html;
                while (wrapper.firstChild) fragment.appendChild(wrapper.firstChild);
            } catch (error) {
                console.error(`[KúkiCakes] Failed to load ${name}.html`, error);
            }
        }

        // ─── KEY FIX ────────────────────────────────────────────────────────────
        // Append into <main> (the #auth-pages container), NOT document.body.
        // This keeps auth pages alongside the other .page divs so:
        //   1. goPage() shows/hides them correctly
        //   2. The footer stays below ALL pages, not above auth pages
        // ────────────────────────────────────────────────────────────────────────
        const container = document.getElementById('auth-pages')
                       || document.querySelector('main')
                       || document.body;
        container.appendChild(fragment);

        // Replay any navigation the user attempted before components loaded
        if (_pendingPage && typeof goPage === 'function') {
            goPage(_pendingPage);
            _pendingPage = null;
        }

        // Load auth.js AFTER components are in DOM so initAuth() finds all elements
        const script = document.createElement('script');
        script.src = 'js/auth.js';
        script.onload = function () {
            console.log('[KúkiCakes] Authentication module loaded.');
        };
        script.onerror = function () {
            console.error('[KúkiCakes] Failed to load js/auth.js');
        };
        document.body.appendChild(script);
    }

    loadAuthComponents();
})();