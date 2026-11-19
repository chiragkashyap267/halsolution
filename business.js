(() => {
    window.HALBusiness = fetch('business-config.json', {cache: 'no-cache'})
        .then(response => { if (!response.ok) throw new Error('Contact settings unavailable.'); return response.json(); })
        .then(config => {
            if (!/^\+[1-9][0-9]{7,14}$/.test(config.phone)) throw new Error('Invalid business number.');
            const phone = config.phone;
            const display = /^\+91\d{10}$/.test(phone) ? `+91 ${phone.slice(3, 7)} ${phone.slice(7, 10)} ${phone.slice(10)}` : phone;
            const whatsapp = text => `https://wa.me/${phone.slice(1)}${text ? '?text=' + encodeURIComponent(text) : ''}`;
            document.querySelectorAll('[data-business-link]').forEach(link => {
                link.href = link.dataset.businessLink === 'call' ? `tel:${phone}` : whatsapp();
                if (link.target === '_blank') link.rel = 'noopener noreferrer';
            });
            document.querySelectorAll('[data-business-number]').forEach(label => { label.textContent = display; });
            return {phone, display, whatsapp};
        });
    // Pages without contact controls should not raise an unhandled rejection.
    window.HALBusiness.catch(() => {});
})();
