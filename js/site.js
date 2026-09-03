const { products, gallery } = window.kukiData;
let cart = JSON.parse(localStorage.getItem('kukiCart') || '[]');
let activeProduct = null;
const money = value => `Rs. ${value.toLocaleString()}`;
const $ = selector => document.querySelector(selector);

function productCard(product) {
	return `<article class="product-card" data-cat="${product.cat}"><div class="product-img" data-product="${product.id}"><img src="${product.img}" alt="${product.name}"><span class="badge">${product.badge}</span><button class="heart" aria-label="Save">♡</button></div><div class="product-info"><h3>${product.name}</h3><div class="product-meta"><span>From</span><span class="price">${money(product.price)}</span></div><button class="add-btn" data-product="${product.id}">Choose this cake</button></div></article>`;
}

function renderCatalog() {
	$('#featuredProducts').innerHTML = products.slice(0, 4).map(productCard).join('');
	$('#allProducts').innerHTML = products.map(productCard).join('');
	$('#galleryGrid').innerHTML = gallery.map(item => `<article class="gallery-item"><img src="${item.img}" alt="${item.title}"><div class="gallery-overlay"><b>${item.title}</b></div></article>`).join('');
}

function goPage(page) {
	document.querySelectorAll('.page').forEach(item => item.classList.toggle('active', item.id === `${page}-page`));
	document.querySelectorAll('[data-page]').forEach(item => item.classList.toggle('active', item.dataset.page === page));
	$('#navLinks').classList.remove('open');
	if (page === 'cart') renderCart();
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
}

function closeModal() { $('#productModal').classList.remove('open'); document.body.style.overflow = ''; }

function saveCart() {
	localStorage.setItem('kukiCart', JSON.stringify(cart));
	$('#cartCount').textContent = cart.reduce((total, item) => total + item.qty, 0);
	renderCart();
}

function renderCart() {
	const box = $('#cartItems');
	box.innerHTML = cart.length ? cart.map(item => `<article class="cart-item"><img src="${item.img}" alt="${item.name}"><div><h3>${item.name}</h3><p>6" · Vanilla berry · Weight: ${item.weight || '500g'} · Serves 10</p><div class="qty"><button data-qty="-1" data-id="${item.id}" data-weight="${item.weight || '500g'}">−</button><b>${item.qty}</b><button data-qty="1" data-id="${item.id}" data-weight="${item.weight || '500g'}">+</button></div></div><div class="item-price"><b>${money(item.price * item.qty)}</b><button class="remove" data-remove="${item.id}" data-weight="${item.weight || '500g'}">Remove</button></div></article>`).join('') : '<div class="empty-cart"><div>🎂</div><h3>Your cake box is empty</h3><p class="subtext">A celebration this sweet deserves a cake.</p><button class="btn btn-primary" data-page="cakes">Explore our cakes</button></div>';
	const subtotal = cart.reduce((total, item) => total + item.price * item.qty, 0);
	$('#subtotal').textContent = money(subtotal);
	$('#total').textContent = money(subtotal);
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
		const item = cart.find(entry => entry.id === itemId && entry.weight === weight); 
		if (item) { 
			item.qty += Number(quantity.dataset.qty); 
			if (item.qty < 1) cart = cart.filter(entry => !(entry.id === itemId && entry.weight === weight)); 
			saveCart(); 
		} 
	}
	const remove = event.target.closest('[data-remove]');
	if (remove) { 
		const itemId = Number(remove.dataset.remove);
		const weight = remove.dataset.weight || '500g';
		cart = cart.filter(item => !(item.id === itemId && item.weight === weight)); 
		saveCart(); 
	}
});

$('#menuBtn').onclick = () => $('#navLinks').classList.toggle('open');
$('#searchBtn').onclick = () => showToast('Search is ready for your future catalogue');
$('#modalClose').onclick = closeModal;
$('#productModal').onclick = event => { if (event.target.id === 'productModal') closeModal(); };
$('#modalAdd').onclick = () => { 
	// Get selected weight from dropdown
	const selectedWeight = $('#weightSelect').value || '500g';
	
	const existing = cart.find(item => item.id === activeProduct.id && item.weight === selectedWeight); 
	if (existing) {
		existing.qty++; 
	} else {
		cart.push({ ...activeProduct, qty: 1, weight: selectedWeight }); 
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
$('#checkoutBtn').onclick = () => showToast(cart.length ? 'Checkout details would open next' : 'Add a cake before checking out');
if ($('#newsletter')) $('#newsletter').onsubmit = event => { event.preventDefault(); showToast('You are on the sweet list!'); };
document.querySelectorAll('.filter[data-filter]').forEach(button => button.onclick = () => { document.querySelectorAll('.filter[data-filter]').forEach(item => item.classList.remove('active')); button.classList.add('active'); document.querySelectorAll('#allProducts .product-card').forEach(card => card.style.display = button.dataset.filter === 'all' || card.dataset.cat.includes(button.dataset.filter) ? '' : 'none'); });
document.querySelectorAll('.chips').forEach(group => group.onclick = event => { if (event.target.classList.contains('chip')) { group.querySelectorAll('.chip').forEach(chip => chip.classList.remove('active')); event.target.classList.add('active'); } });
document.addEventListener('keydown', event => { if (event.key === 'Escape') closeModal(); });

renderCatalog();
saveCart();
const initialPage = location.hash.slice(1);
if (['cakes', 'gallery', 'cart', 'admin'].includes(initialPage)) goPage(initialPage);
