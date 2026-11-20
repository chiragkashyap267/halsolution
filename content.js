// One published catalog powers cards, calculators and stored estimates.
window.HALContent.then(content => {
    const products = content.products;
    document.querySelectorAll('[data-catalog-count]').forEach(node => {node.textContent = products.length;});
    const money = value => Number(value).toLocaleString('en-IN', {maximumFractionDigits:2});
    const optionFor = (item, value) => { const option = document.createElement('option'); option.value = value; option.textContent = `${item.name} — ₹${money(item.price)} / ${item.unit}`; return option; };
    if (document.body.classList.contains('page-rate-card')) {
        const template = document.querySelector('.product-card').cloneNode(true);
        const selectedName = currentCalcItem.name;
        catalogItems.splice(0, catalogItems.length, ...products);
        selectEl.replaceChildren(...products.map(optionFor));
        const selectedIndex = Math.max(0, products.findIndex(item => item.name === selectedName));
        selectEl.value = selectedIndex;
        currentCalcItem = products[selectedIndex];
        if (currentCalcItem) updateCalcDisplay();
        document.querySelectorAll('.category-section').forEach(section => {
            const items = products.filter(item => item.category === section.id);
            const grid = section.querySelector(':scope > .grid');
            section.hidden = !items.length;
            section.querySelector('.category-copy p').textContent = `${items.length} ${items.length === 1 ? 'item' : 'items'} actively accepted`;
            const count = document.querySelector(`a[href="#${section.id}"] > span:last-child`);
            if (count) count.textContent = items.length;
            grid.replaceChildren(...items.map(item => {
                const card = template.cloneNode(true); card.dataset.name = item.name.toLowerCase(); card.dataset.category = item.category; card.dataset.productId = item.id;
                const img = card.querySelector('img'); img.src = item.img; img.alt = item.name; img.removeAttribute('onerror'); img.addEventListener('error', () => {img.src='images/scrap-placeholder.svg';}, {once:true});
                card.querySelector('.item-name').textContent = item.name;
                card.querySelector('.product-info > p').textContent = item.description;
                const badge = card.querySelector('.product-badge'); badge.textContent = item.badge; badge.hidden = !item.badge;
                const price = card.querySelector('.item-price'), unit = document.createElement('span'); unit.textContent = item.unit === 'estimate' ? ' est.' : '/' + item.unit;
                price.replaceChildren(document.createTextNode('₹' + money(item.price)), unit);
                const calculate = card.querySelector('.product-calculate'), add = card.querySelector('.product-add');
                calculate.removeAttribute('onclick'); add.removeAttribute('onclick');
                calculate.setAttribute('aria-label', 'Calculate ' + item.name); add.setAttribute('aria-label', 'Add ' + item.name + ' to estimate');
                calculate.addEventListener('click', () => calculateFor(item.name,item.price,item.unit)); add.addEventListener('click', () => addToEstimate(item.name,item.price,item.unit));
                return card;
            }));
        });
        // Saved estimates follow the latest published rates, including hidden/renamed items.
        for (const name of Object.keys(estimateItems)) {
            const item = products.find(item => item.name === name);
            if (!item) delete estimateItems[name];
            else { estimateItems[name].price = item.price; estimateItems[name].unit = item.unit; }
        }
        saveCart(); updateCartUI(); searchScrap();
    }
    const homeSelect = document.getElementById('hp-calc-item');
    if (homeSelect && products.length) {
        homeSelect.replaceChildren(...products.map(item => optionFor(item, `${item.price},${item.unit}`)));
        onHpCalcChange();
        document.querySelectorAll('[data-home-product]').forEach(card => {
            const item = products.find(item => item.id === card.dataset.homeProduct);
            card.hidden = !item;
            if (!item) return;
            const img = card.querySelector('img'); img.src = item.img; img.alt = item.name;
            const price = card.querySelector('p'), unit = document.createElement('span'); unit.className = 'text-xs font-normal text-slate-500'; unit.textContent = '/' + item.unit;
            price.replaceChildren(document.createTextNode('₹' + money(item.price)), unit);
            card.querySelector('h4').textContent = item.name;
            card.removeAttribute('onclick'); card.addEventListener('click', () => {homeSelect.value = `${item.price},${item.unit}`; homeSelect.selectedIndex = products.indexOf(item); onHpCalcChange(); homeSelect.scrollIntoView({behavior:'smooth',block:'center'});});
        });
    }
}).catch(() => {
    const status = document.getElementById('site-status');
    if (status) status.textContent = 'Showing listed reference rates. Confirm current rates with our team.';
});
