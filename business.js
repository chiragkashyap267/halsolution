(() => {
    window.HALContent = fetch('api/content.php', {cache:'no-store'}).then(response => {
        if (!response.ok) throw new Error('Website settings unavailable.');
        return response.json();
    });
    window.HALBusiness = window.HALContent.then(content => content.settings).catch(() => fetch('business-config.json', {cache:'no-cache'}).then(r=>r.json())).then(config => {
        if (!/^\+[1-9][0-9]{7,14}$/.test(config.phone)) throw new Error('Invalid business number.');
        const phone = config.phone;
        const display = /^\+91\d{10}$/.test(phone) ? `+91 ${phone.slice(3,7)} ${phone.slice(7,10)} ${phone.slice(10)}` : phone;
        const whatsapp = text => `https://wa.me/${phone.slice(1)}${text ? '?text=' + encodeURIComponent(text) : ''}`;
        document.querySelectorAll('[data-business-link]').forEach(link => {link.href=link.dataset.businessLink==='call'?`tel:${phone}`:whatsapp();if(link.target==='_blank')link.rel='noopener noreferrer';});
        document.querySelectorAll('[data-business-number]').forEach(label=>{label.textContent=display;});
        document.querySelectorAll('[data-setting]').forEach(node=>{const value=config[node.dataset.setting];if(typeof value==='string')node.textContent=value;});
        document.querySelectorAll('a[href^="mailto:"]').forEach(link=>{link.href='mailto:'+config.email;link.textContent=config.email;});
        document.querySelectorAll('[data-business-map]').forEach(link=>{link.href='https://www.google.com/maps/search/?api=1&query='+encodeURIComponent(config.address);});
        return {...config,phone,display,whatsapp};
    });
    window.HALBusiness.catch(()=>{});
})();
