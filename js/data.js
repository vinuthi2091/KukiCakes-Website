const catalogProducts = [
    { id: 1, name: 'Blush Garden', cat: 'floral birthday', price: 8500, badge: 'Bestseller', img: 'https://images.unsplash.com/photo-1535254973040-607b474cb50d?auto=format&fit=crop&w=700&q=85', desc: 'Soft vanilla sponge, berry compote and vanilla bean buttercream.' },
    { id: 2, name: 'Ivory Romance', cat: 'wedding minimal', price: 4500, badge: 'Wedding', img: 'https://images.unsplash.com/photo-1519915028121-7d3463d20b13?auto=format&fit=crop&w=700&q=85', desc: 'An elegant tiered centrepiece with silky buttercream and botanical details.' },
    { id: 3, name: 'Chocolate Ruffle', cat: 'birthday', price: 7500, badge: 'Rich & fudgy', img: 'https://images.unsplash.com/photo-1571115177098-24ec42ed204d?auto=format&fit=crop&w=700&q=85', desc: 'Deep cocoa layers with dark chocolate ganache and chocolate buttercream.' },
    { id: 4, name: 'Lemon Meadow', cat: 'floral minimal', price: 3000, badge: 'Fresh favourite', img: 'https://images.unsplash.com/photo-1464349095431-e9a21285b5f3?auto=format&fit=crop&w=700&q=85', desc: 'Zesty lemon sponge, elderflower curd and a light vanilla finish.' },
    { id: 5, name: 'Berry Cloud', cat: 'birthday floral', price: 8000, badge: 'Seasonal', img: 'https://images.unsplash.com/photo-1586788680434-30d324b2d46f?auto=format&fit=crop&w=700&q=85', desc: 'A soft berry celebration cake layered with fresh cream and compote.' },
    { id: 6, name: 'Modern Muse', cat: 'minimal wedding', price: 12000, badge: 'New', img: 'https://images.unsplash.com/photo-1565958011703-44f9829ba187?auto=format&fit=crop&w=700&q=85', desc: 'A clean, contemporary design with hand-finished texture.' },
    { id: 7, name: 'Pink Party', cat: 'birthday', price: 7000, badge: 'Party pick', img: 'https://images.unsplash.com/photo-1574085733277-851d9d856a3a?auto=format&fit=crop&w=700&q=85', desc: 'Fun vanilla confetti layers wrapped in strawberry buttercream.' },
    { id: 8, name: 'Pearl & Petal', cat: 'wedding floral', price: 18000, badge: 'Statement', img: 'https://images.unsplash.com/photo-1525257831700-183b9b8bf5c4?auto=format&fit=crop&w=700&q=85', desc: 'A romantic multi-tiered cake finished with handmade petals.' }
];

const catalogGallery = [
    ['photo-1535141192574-5d4897c12636', 'A garden celebration'],
    ['photo-1560180474-e8563fd75bab', 'Pearl details'],
    ['photo-1587668178277-295251f900ce', 'Little treats'],
    ['photo-1559620192-032c4bc4674e', 'Buttercream dreams'],
    ['photo-1596223575327-99a5be4faf1e', 'Birthday joy'],
    ['photo-1488477181946-6428a0291777', 'Freshly finished']
].map(([photo, title]) => ({
    img: `https://images.unsplash.com/${photo}?auto=format&fit=crop&w=800&q=85`,
    title
}));

window.kukiData = { products: catalogProducts, gallery: catalogGallery };