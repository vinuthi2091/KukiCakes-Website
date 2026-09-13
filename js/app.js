const pages = ['home', 'cakes', 'gallery', 'cart', 'checkout', 'confirmation', 'admin'];

async function loadComponent(targetId, name) {
    const response = await fetch(`components/${name}.html`);
    if (!response.ok) throw new Error(`Could not load ${name}.html`);
    document.getElementById(targetId).insertAdjacentHTML('beforeend', await response.text());
}

async function startApp() {
    await loadComponent('site-header', 'header');
    for (const page of pages) await loadComponent('app', page);
    await Promise.all([
        loadComponent('site-footer', 'footer'),
        loadComponent('site-modal', 'modal'),
        loadComponent('site-toast', 'toast')
    ]);
    // Load site.js after components are loaded
    const script = document.createElement('script');
    script.src = 'js/site.js';
    document.body.appendChild(script);
}

startApp().catch(error => {
    document.getElementById('app').textContent = 'Unable to load the website. Please open it through a local server, such as VS Code Live Server.';
    console.error(error);
});
