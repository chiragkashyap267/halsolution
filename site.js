(() => {
    const menu = document.getElementById('mobile-menu');
    const opener = document.querySelector('[data-menu-open]');
    let previousFocus;
    if (menu && opener) {
        const closeButton = menu.querySelector('[data-menu-close]');
        menu.setAttribute('role', 'dialog');
        menu.setAttribute('aria-modal', 'true');
        menu.setAttribute('aria-label', 'Site navigation');
        const setMenu = (open, restore = true) => {
            if (open) previousFocus = document.activeElement;
            menu.classList.toggle('translate-x-full', !open);
            menu.inert = !open;
            menu.setAttribute('aria-hidden', String(!open));
            opener.setAttribute('aria-expanded', String(open));
            document.documentElement.classList.toggle('menu-open', open);
            if (open) closeButton?.focus();
            else if (restore) previousFocus?.focus();
        };
        setMenu(false, false);
        opener.addEventListener('click', () => setMenu(true));
        closeButton?.addEventListener('click', () => setMenu(false));
        menu.querySelectorAll('a').forEach(link => link.addEventListener('click', () => setMenu(false)));
        menu.addEventListener('keydown', event => {
            if (event.key === 'Escape') { event.preventDefault(); setMenu(false); }
            if (event.key !== 'Tab') return;
            const controls = [...menu.querySelectorAll('a[href], button:not([disabled])')].filter(el => el.getClientRects().length);
            const first = controls[0], last = controls[controls.length - 1];
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
        });
        matchMedia('(min-width: 1200px)').addEventListener('change', event => {
            if (event.matches) setMenu(false, false);
        });
    }

    document.querySelectorAll('.site-header nav > .group').forEach((group, index) => {
        const trigger = group.querySelector('button'), panel = trigger?.nextElementSibling;
        if (!trigger || !panel) return;
        panel.id = `ecosystem-links-${index}`;
        trigger.setAttribute('aria-controls', panel.id);
        trigger.setAttribute('aria-expanded', 'false');
        const close = () => { group.dataset.open = 'false'; trigger.setAttribute('aria-expanded', 'false'); };
        group.addEventListener('pointerenter', () => { if (group.dataset.open === 'false') delete group.dataset.open; });
        trigger.addEventListener('click', () => {
            const open = group.dataset.open !== 'true';
            group.dataset.open = String(open);
            trigger.setAttribute('aria-expanded', String(open));
            if (!open) trigger.blur();
        });
        group.addEventListener('keydown', event => {
            if (event.key === 'Escape') { close(); trigger.focus(); }
            if (event.key === 'ArrowDown' && event.target === trigger) {
                event.preventDefault();
                group.dataset.open = 'true';
                trigger.setAttribute('aria-expanded', 'true');
                panel.querySelector('a')?.focus();
            }
        });
        document.addEventListener('click', event => { if (!group.contains(event.target)) close(); });
        group.addEventListener('focusout', event => { if (!group.contains(event.relatedTarget)) close(); });
    });

    document.querySelectorAll('.floating-actions a').forEach(link => {
        if (link.querySelector('.fa-phone-alt')) link.setAttribute('aria-label', 'Call HAL Solution');
        if (link.querySelector('.fa-whatsapp')) link.setAttribute('aria-label', 'Chat on WhatsApp');
        if (link.querySelector('.fa-share-alt')) {
            link.setAttribute('aria-label', 'Share this page');
            link.setAttribute('role', 'button');
            const share = async event => {
                event.preventDefault();
                try {
                    if (navigator.share) await navigator.share({ title: document.title, url: location.href });
                    else if (navigator.clipboard) {
                        await navigator.clipboard.writeText(location.href);
                        const status = document.getElementById('site-status');
                        if (status) status.textContent = 'Page link copied to clipboard.';
                    } else {
                        const status = document.getElementById('site-status');
                        if (status) status.textContent = 'Copy the address from your browser to share this page.';
                    }
                } catch (error) {
                    if (error.name !== 'AbortError') {
                        const status = document.getElementById('site-status');
                        if (status) status.textContent = 'Copy the address from your browser to share this page.';
                    }
                }
            };
            link.addEventListener('click', share);
            link.addEventListener('keydown', event => { if (event.key === ' ') share(event); });
        }
    });
    document.querySelectorAll('div.cursor-pointer[onclick]').forEach(control => {
        control.setAttribute('role', 'button');
        control.tabIndex = 0;
        control.addEventListener('keydown', event => {
            if (event.target === control && (event.key === 'Enter' || event.key === ' ')) {
                event.preventDefault();
                control.click();
            }
        });
    });
    document.querySelectorAll('a[target="_blank"]').forEach(link => link.rel = 'noopener noreferrer');
    // Reveal content when the optional animation library fails to load.
    if (!window.AOS) document.querySelectorAll('[data-aos]').forEach(el => el.removeAttribute('data-aos'));
})();
