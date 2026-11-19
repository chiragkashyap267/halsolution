(() => {
    let csrf = '';
    const status = document.getElementById('account-status');
    const profileForm = document.getElementById('profile-form');
    const authForms = [...document.querySelectorAll('[data-auth-form]')];
    const message = (text = '', kind = '') => {
        if (status) { status.textContent = text; status.dataset.kind = kind; }
    };
    async function request(action, data) {
        const response = await fetch(`api/account.php?action=${action}`, {
            method: data === undefined ? 'GET' : 'POST', credentials: 'same-origin', cache: 'no-store',
            headers: data === undefined ? {} : { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
            body: data === undefined ? undefined : JSON.stringify(data),
        });
        let result;
        try { result = await response.json(); }
        catch { throw new Error('Sign-in is temporarily unavailable. Please try again shortly.'); }
        if (!response.ok) {
            const error = new Error(result.error || 'Please try again shortly.');
            error.status = response.status;
            throw error;
        }
        if (result.csrfToken) csrf = result.csrfToken;
        return result;
    }
    function renderUser(user) {
        document.querySelectorAll('[data-account-link]').forEach(link => {
            link.href = user ? 'profile.html' : 'login.html';
            link.setAttribute('aria-label', user ? 'Your account' : 'Log in or create an account');
            const label = link.querySelector('[data-account-label]');
            if (label) label.textContent = user ? 'My account' : 'Login';
        });
        if (user && profileForm) {
            for (const key of ['name', 'email', 'phone', 'address', 'city', 'pincode']) profileForm.elements.namedItem(key).value = user[key] || '';
            document.getElementById('profile-name').textContent = user.name;
            document.getElementById('profile-email').textContent = user.email || user.phone || '';
            document.getElementById('profile-avatar').textContent = user.name.trim().split(/\s+/).slice(0, 2).map(word => word[0]).join('').toUpperCase();
        }
    }
    async function loadOrders() {
        const list = document.getElementById('orders-list');
        const state = document.getElementById('orders-status');
        if (!list) return;
        try {
            const response = await fetch('api/pickup.php?action=orders', {credentials: 'same-origin', cache: 'no-store'});
            const result = await response.json();
            if (!response.ok) throw new Error(result.error || 'Unable to load orders.');
            list.replaceChildren();
            state.textContent = result.orders.length ? '' : 'No pickups yet. Schedule your first pickup when you are ready.';
            for (const order of result.orders) {
                const card = document.createElement('article'); card.className = 'pickup-order';
                const title = document.createElement('h3'); title.textContent = `${order.reference} · ${order.status}`;
                const items = document.createElement('p'); items.textContent = order.items.map(item => item.name).join(', ');
                const location = [order.address, order.city, order.pincode].filter(Boolean).join(', ')
                    || (order.latitude !== null ? `GPS pin: ${order.latitude}, ${order.longitude}` : '');
                const details = document.createElement('p'); details.textContent = `${order.date || 'Next available day'} · ${order.slot} · ${location}`;
                const actions = document.createElement('div'); actions.className = 'order-actions';
                const receipt = document.createElement('a'); receipt.href = `api/receipt.php?reference=${encodeURIComponent(order.reference)}`; receipt.textContent = 'Download receipt';
                actions.append(receipt);
                try {
                    const links = await window.HALOrderActions(order);
                    const whatsapp = document.createElement('a'); whatsapp.href = links.whatsapp; whatsapp.target = '_blank'; whatsapp.rel = 'noopener noreferrer'; whatsapp.textContent = 'Send order on WhatsApp';
                    actions.prepend(whatsapp);
                } catch { /* Receipt and saved order stay accessible if contact settings fail. */ }
                card.append(title, items, details, actions); list.append(card);
            }
        } catch (error) { state.textContent = error.message; }
    }
    document.querySelectorAll('[data-auth-mode]').forEach(button => button.addEventListener('click', () => {
        const mode = button.dataset.authMode;
        document.querySelectorAll('[data-auth-mode]').forEach(tab => tab.setAttribute('aria-pressed', String(tab === button)));
        authForms.forEach(form => { form.hidden = form.dataset.authForm !== mode; });
        message();
    }));
    document.querySelectorAll('[data-show-password]').forEach(button => button.addEventListener('click', () => {
        const field = document.getElementById(button.dataset.showPassword);
        const show = field.type === 'password';
        field.type = show ? 'text' : 'password';
        button.textContent = show ? 'Hide' : 'Show';
        button.setAttribute('aria-pressed', String(show));
    }));
    authForms.forEach(form => form.addEventListener('submit', async event => {
        event.preventDefault();
        const button = form.querySelector('[type="submit"]');
        const original = button.textContent;
        button.disabled = true; button.textContent = 'Please wait…'; message();
        try {
            const result = await request(form.dataset.authForm, Object.fromEntries(new FormData(form)));
            renderUser(result.user);
            location.replace('profile.html');
        } catch (error) {
            message(error.message, 'error');
            if (error.status === 403) location.reload();
        } finally { button.disabled = false; button.textContent = original; }
    }));
    profileForm?.addEventListener('submit', async event => {
        event.preventDefault();
        const button = profileForm.querySelector('[type="submit"]');
        const fields = document.getElementById('profile-fields');
        const payload = Object.fromEntries(new FormData(profileForm));
        delete payload.email;
        fields.disabled = true; button.disabled = true; button.textContent = 'Saving…'; message();
        try {
            const result = await request('profile', payload);
            renderUser(result.user);
            message(result.message, 'success');
        } catch (error) {
            message(error.message, 'error');
            if (error.status === 401) location.replace('login.html');
        } finally { fields.disabled = false; button.disabled = false; button.textContent = 'Save details'; }
    });
    document.querySelector('[data-logout]')?.addEventListener('click', async event => {
        event.currentTarget.disabled = true;
        try { await request('logout', {}); location.replace('login.html?signedout=1'); }
        catch (error) { message(error.message, 'error'); event.currentTarget.disabled = false; }
    });
    async function refreshSession() {
        try {
            const result = await request('session');
            renderUser(result.user);
            if (authForms.length && result.user) return location.replace('profile.html');
            if (profileForm && !result.user) return location.replace('login.html');
            authForms.forEach(form => form.querySelector('[type="submit"]').disabled = false);
            if (profileForm) {
                document.getElementById('profile-fields').disabled = false;
                profileForm.querySelector('[type="submit"]').disabled = false;
                loadOrders();
            }
            message(new URLSearchParams(location.search).has('signedout') ? 'You have been signed out.' : '');
        } catch (error) { message(error.message, 'error'); }
    }
    window.addEventListener('pageshow', event => {
        if (event.persisted) {
            if (profileForm) {
                document.getElementById('profile-fields').disabled = true;
                profileForm.reset();
            }
            refreshSession();
        }
    });
    window.addEventListener('pagehide', () => {
        if (!profileForm) return;
        profileForm.reset();
        document.getElementById('profile-fields').disabled = true;
        document.getElementById('profile-name').textContent = 'Your account';
        document.getElementById('profile-email').textContent = '';
        document.getElementById('profile-avatar').textContent = '';
        document.getElementById('orders-list')?.replaceChildren();
    });
    refreshSession();
})();
