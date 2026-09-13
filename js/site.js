const { products, gallery } = window.kukiData;
let cart = JSON.parse(localStorage.getItem('kukiCart') || '[]');
let activeProduct = null;
let searchTerm = '';
const money = value => `Rs. ${value.toLocaleString()}`;
const $ = selector => document.querySelector(selector);

function productCard(product) {
	return `<article class="product-card" data-cat="${product.cat}"><div class="product-img" data-product="${product.id}"><img src="${product.img}" alt="${product.name}"><span class="badge">${product.badge}</span><button class="heart" aria-label="Save">♡</button></div><div class="product-info"><h3>${product.name}</h3><div class="product-meta"><span>From</span><span class="price">${money(product.price)}</span></div><button class="add-btn" data-product="${product.id}">Choose this cake</button></div></article>`;
}

function renderAllProducts() {
	const activeFilter = document.querySelector('.filter.active')?.dataset.filter || 'all';
	const sort = $('#priceSort')?.value || 'default';
	const visibleProducts = products.filter(product => {
		const matchesCategory = activeFilter === 'all' || product.cat.includes(activeFilter);
		const searchableText = `${product.name} ${product.cat} ${product.desc}`.toLowerCase();
		return matchesCategory && (!searchTerm || searchableText.includes(searchTerm));
	});
	if (sort === 'new') visibleProducts.sort((first, second) => second.id - first.id);
	if (sort === 'low-high') visibleProducts.sort((first, second) => first.price - second.price);
	if (sort === 'high-low') visibleProducts.sort((first, second) => second.price - first.price);
	$('#allProducts').innerHTML = visibleProducts.length ? visibleProducts.map(productCard).join('') : '<div class="search-empty"><h3>No cakes found</h3><p>Try a different name, flavour or category.</p></div>';
}

function renderCatalog() {
	$('#featuredProducts').innerHTML = products.slice(0, 4).map(productCard).join('');
	renderAllProducts();
	$('#galleryGrid').innerHTML = gallery.map(item => `<article class="gallery-item"><img src="${item.img}" alt="${item.title}"><div class="gallery-overlay"><b>${item.title}</b></div></article>`).join('');
}

function goPage(page) {
	document.querySelectorAll('.page').forEach(item => item.classList.toggle('active', item.id === `${page}-page`));
	document.querySelectorAll('[data-page]').forEach(item => item.classList.toggle('active', item.dataset.page === page));
	$('#navLinks').classList.remove('open');
	if (page === 'cart') renderCart();
	if (page === 'checkout') renderCheckout();
	if (page === 'confirmation') renderConfirmation();
	window.scrollTo({ top: 0, behavior: 'smooth' });
	history.replaceState(null, '', `#${page}`);
}

function openProduct(id) {
	activeProduct = products.find(product => product.id === id);
	$('#modalImage').src = activeProduct.img;
	$('#modalTitle').textContent = activeProduct.name;
	$('#modalDesc').textContent = activeProduct.desc;
	$('#modalPrice').textContent = `From ${money(activeProduct.price)}`;
	$('#productModal').classList.add('open');
	document.body.style.overflow = 'hidden';
	// Reset weight select to first option
	if ($('#weightSelect')) $('#weightSelect').value = '500g';
	if ($('#cakeFlavor')) $('#cakeFlavor').value = 'Not sure yet';
	if ($('#specialNote')) $('#specialNote').value = '';
	const flavorCategories = ['wedding', 'anniversary', 'birthday', 'cupcake', 'engagement', 'graduation', 'party', 'number'];
	if ($('#flavorChoice')) $('#flavorChoice').hidden = !flavorCategories.some(category => activeProduct.cat.includes(category));
}

function closeModal() { $('#productModal').classList.remove('open'); document.body.style.overflow = ''; }

function saveCart() {
	localStorage.setItem('kukiCart', JSON.stringify(cart));
	$('#cartCount').textContent = cart.reduce((total, item) => total + item.qty, 0);
	renderCart();
}

function matchesCartItem(item, itemId, weight, flavor, note) {
	return item.id === itemId && (item.weight || '500g') === weight && (item.flavor || '') === flavor && (item.note || '') === note;
}

function renderCart() {
	const box = $('#cartItems');
	box.innerHTML = cart.length ? cart.map(item => `<article class="cart-item"><img src="${item.img}" alt="${item.name}"><div><h3>${item.name}</h3><p>Weight: ${item.weight || '500g'}${item.flavor ? ` · Flavour: ${item.flavor}` : ''}${item.note ? ` · Note: ${item.note}` : ''}</p><div class="qty"><button data-qty="-1" data-id="${item.id}" data-weight="${item.weight || '500g'}" data-flavor="${item.flavor || ''}" data-note="${item.note || ''}">−</button><b>${item.qty}</b><button data-qty="1" data-id="${item.id}" data-weight="${item.weight || '500g'}" data-flavor="${item.flavor || ''}" data-note="${item.note || ''}">+</button></div></div><div class="item-price"><b>${money(item.price * item.qty)}</b><button class="remove" data-remove="${item.id}" data-weight="${item.weight || '500g'}" data-flavor="${item.flavor || ''}" data-note="${item.note || ''}">Remove</button></div></article>`).join('') : '<div class="empty-cart"><div>🎂</div><h3>Your cake box is empty</h3><p class="subtext">A celebration this sweet deserves a cake.</p><button class="btn btn-primary" data-page="cakes">Explore our cakes</button></div>';
	const subtotal = cart.reduce((total, item) => total + item.price * item.qty, 0);
	$('#subtotal').textContent = money(subtotal);
	$('#total').textContent = money(subtotal);
}

function renderCheckout() {
	const items = $('#checkoutItems');
	if (!items) return;
	items.innerHTML = cart.length ? cart.map(item => `<div class="checkout-item"><div><b>${item.name}</b><span>${item.weight || '500g'} · Qty ${item.qty}</span></div><strong>${money(item.price * item.qty)}</strong></div>`).join('') : '<p class="checkout-empty">Your cart is empty. Add a cake before checking out.</p>';
	const subtotal = cart.reduce((total, item) => total + item.price * item.qty, 0);
	const delivery = cart.length ? 500 : 0;
	$('#checkoutSubtotal').textContent = money(subtotal);
	$('#checkoutDelivery').textContent = money(delivery);
	$('#checkoutTotal').textContent = money(subtotal + delivery);
}

function renderConfirmation() {
	const order = JSON.parse(localStorage.getItem('kukiLastOrder') || '{}');
	if ($('#confirmationNumber')) $('#confirmationNumber').textContent = order.number || 'KC1025';
	if ($('#confirmationTotal')) $('#confirmationTotal').textContent = money(order.total || 0);
}

function showToast(message) { const toast = $('#toast'); toast.textContent = message; toast.classList.add('show'); clearTimeout(window.toastTimer); window.toastTimer = setTimeout(() => toast.classList.remove('show'), 2800); }

document.addEventListener('click', event => {
	const pageLink = event.target.closest('[data-page]');
	if (pageLink) { event.preventDefault(); goPage(pageLink.dataset.page); return; }
	const scrollLink = event.target.closest('[data-scroll]');
	if (scrollLink) { event.preventDefault(); goPage('home'); setTimeout(() => document.getElementById(scrollLink.dataset.scroll)?.scrollIntoView({ behavior: 'smooth' }), 100); return; }
	const productLink = event.target.closest('[data-product]');
	if (productLink && !event.target.closest('.heart')) openProduct(Number(productLink.dataset.product));
	const quantity = event.target.closest('[data-qty]');
	if (quantity) { 
		const itemId = Number(quantity.dataset.id);
		const weight = quantity.dataset.weight || '500g';
		const flavor = quantity.dataset.flavor || '';
		const note = quantity.dataset.note || '';
		const item = cart.find(entry => matchesCartItem(entry, itemId, weight, flavor, note)); 
		if (item) { 
			item.qty += Number(quantity.dataset.qty); 
			if (item.qty < 1) cart = cart.filter(entry => !matchesCartItem(entry, itemId, weight, flavor, note)); 
			saveCart(); 
		} 
	}
	const remove = event.target.closest('[data-remove]');
	if (remove) { 
		const itemId = Number(remove.dataset.remove);
		const weight = remove.dataset.weight || '500g';
		const flavor = remove.dataset.flavor || '';
		const note = remove.dataset.note || '';
		cart = cart.filter(item => !matchesCartItem(item, itemId, weight, flavor, note)); 
		saveCart(); 
	}
});

$('#menuBtn').onclick = () => $('#navLinks').classList.toggle('open');
$('#searchBtn').onclick = () => {
	$('#siteSearch').classList.toggle('open');
	if ($('#siteSearch').classList.contains('open')) {
		goPage('cakes');
		$('#searchInput').focus();
	}
};
$('#searchClose').onclick = () => {
	searchTerm = '';
	$('#searchInput').value = '';
	$('#siteSearch').classList.remove('open');
	renderAllProducts();
};
$('#searchInput').oninput = event => {
	searchTerm = event.target.value.trim().toLowerCase();
	if (!$('#cakes-page').classList.contains('active')) goPage('cakes');
	renderAllProducts();
};
$('#modalClose').onclick = closeModal;
$('#productModal').onclick = event => { if (event.target.id === 'productModal') closeModal(); };
$('#modalAdd').onclick = () => { 
	// Get selected weight from dropdown
	const selectedWeight = $('#weightSelect').value || '500g';
	const selectedFlavor = $('#flavorChoice').hidden ? '' : ($('#cakeFlavor').value || 'Not sure yet');
	const specialNote = $('#specialNote').value.trim();
	const existing = cart.find(item => item.id === activeProduct.id && item.weight === selectedWeight && (item.flavor || '') === selectedFlavor && (item.note || '') === specialNote); 
	if (existing) {
		existing.qty++; 
	} else {
		cart.push({ ...activeProduct, qty: 1, weight: selectedWeight, flavor: selectedFlavor, note: specialNote }); 
	}
	saveCart(); 
	closeModal(); 
	showToast(`${activeProduct.name} (${selectedWeight}) added to your selection`); 
};
$('#quoteForm').onsubmit = event => {
	event.preventDefault();
	$('#successMessage').classList.add('show');
	event.target.querySelector('button[type="submit"]').textContent = 'Inquiry received ✓';
	showToast('Your cake inquiry is on its way!');
};
$('#checkoutBtn').onclick = () => {
	if (cart.length) goPage('checkout');
	else showToast('Add a cake before checking out');
};
if ($('#checkoutForm')) $('#checkoutForm').onsubmit = event => {
	event.preventDefault();
	if (!cart.length) { showToast('Add a cake before placing an order'); goPage('cart'); return; }
	const total = cart.reduce((sum, item) => sum + item.price * item.qty, 0) + 500;
	const order = { number: `KC${Math.floor(10000 + Math.random() * 90000)}`, total };
	localStorage.setItem('kukiLastOrder', JSON.stringify(order));
	cart = [];
	saveCart();
	goPage('confirmation');
};
document.querySelectorAll('input[name="payment"]').forEach(input => input.onchange = event => {
	if ($('#cardDetails')) $('#cardDetails').hidden = event.target.value !== 'card';
});
if ($('#newsletter')) $('#newsletter').onsubmit = event => { event.preventDefault(); showToast('You are on the sweet list!'); };
document.querySelectorAll('.filter[data-filter]').forEach(button => button.onclick = () => { document.querySelectorAll('.filter[data-filter]').forEach(item => item.classList.remove('active')); button.classList.add('active'); renderAllProducts(); });
if ($('#priceSort')) $('#priceSort').onchange = renderAllProducts;
document.querySelectorAll('.chips').forEach(group => group.onclick = event => { if (event.target.classList.contains('chip')) { group.querySelectorAll('.chip').forEach(chip => chip.classList.remove('active')); event.target.classList.add('active'); } });
document.addEventListener('keydown', event => { if (event.key === 'Escape') closeModal(); });

renderCatalog();
saveCart();
const initialPage = location.hash.slice(1);
if (['cakes', 'gallery', 'cart', 'checkout', 'confirmation', 'admin'].includes(initialPage)) goPage(initialPage);
