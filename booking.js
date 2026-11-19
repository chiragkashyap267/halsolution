(() => {
    const status = document.getElementById('pickup-status');
    const phoneForm = document.getElementById('phone-form');
    const otpForm = document.getElementById('otp-form');
    const bookingForm = document.getElementById('pickup-form');
    const fields = document.getElementById('pickup-fields');
    const authPanel = document.getElementById('pickup-auth');
    const verifiedPanel = document.getElementById('pickup-verified');
    let csrf = '', verifiedPhone = null, pin = null, requestKey = '', cooldownUntil = 0;
    let submission = null;
    const message = (text = '', kind = '') => { status.textContent = text; status.dataset.kind = kind; };
    const newKey = () => [...crypto.getRandomValues(new Uint8Array(16))].map(n => n.toString(16).padStart(2, '0')).join('');
    async function api(action, data) {
        const response = await fetch(`api/pickup.php?action=${action}`, {
            method: data === undefined ? 'GET' : 'POST', credentials: 'same-origin', cache: 'no-store',
            headers: data === undefined ? {} : {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
            body: data === undefined ? undefined : JSON.stringify(data),
        });
        let result;
        try { result = await response.json(); } catch { throw new Error('Connection interrupted. Please try again.'); }
        if (!response.ok) { const error = new Error(result.error || 'Please try again.'); error.status = response.status; throw error; }
        if (result.csrfToken) csrf = result.csrfToken;
        return result;
    }
    function renderSession(result) {
        verifiedPhone = result.verifiedPhone;
        authPanel.hidden = Boolean(verifiedPhone);
        if (verifiedPanel) {
            verifiedPanel.hidden = !verifiedPhone;
            document.getElementById('verified-number').textContent = verifiedPhone || '';
        }
        if (fields) fields.disabled = !verifiedPhone;
        const placeOrder = document.getElementById('place-order');
        if (placeOrder) placeOrder.disabled = !verifiedPhone;
        if (result.user && bookingForm) {
            for (const key of ['name', 'address', 'city', 'pincode']) bookingForm.elements.namedItem(key).value = result.user[key] === 'Customer' ? '' : result.user[key] || '';
        }
        if (result.user?.phone) phoneForm.elements.phone.value = result.user.phone.replace(/^\+91/, '');
    }
    function cooldown() {
        const left = Math.max(0, Math.ceil((cooldownUntil - Date.now()) / 1000));
        const button = document.getElementById('resend-otp');
        button.disabled = left > 0;
        button.textContent = left ? `Resend in ${left}s` : 'Resend OTP';
    }
    async function sendOtp() {
        const result = await api('send-otp', {phone: phoneForm.elements.phone.value});
        otpForm.hidden = false;
        phoneForm.elements.phone.readOnly = true;
        phoneForm.querySelector('[type=submit]').hidden = true;
        document.getElementById('otp-number').textContent = phoneForm.elements.phone.value;
        cooldownUntil = Date.now() + result.retryAfter * 1000; cooldown();
        message(result.message, 'success'); otpForm.elements.code.focus();
    }
    phoneForm.addEventListener('submit', async event => {
        event.preventDefault(); const button = event.submitter; button.disabled = true; message('Sending OTP...');
        try { await sendOtp(); } catch (error) { message(error.message, 'error'); }
        finally { button.disabled = false; }
    });
    document.getElementById('resend-otp').addEventListener('click', async event => {
        event.currentTarget.disabled = true;
        try { await sendOtp(); } catch (error) { message(error.message, 'error'); }
        finally { cooldown(); }
    });
    document.getElementById('change-number').addEventListener('click', () => {
        otpForm.hidden = true; otpForm.reset(); phoneForm.elements.phone.readOnly = false;
        phoneForm.querySelector('[type=submit]').hidden = false; phoneForm.elements.phone.focus(); message();
    });
    otpForm.addEventListener('submit', async event => {
        event.preventDefault(); const button = event.submitter; button.disabled = true; message('Verifying...');
        try {
            const result = await api('verify-otp', {code: otpForm.elements.code.value}); renderSession(result);
            if (!bookingForm) return location.replace('booking.html');
            message('Mobile verified. Your account is ready. Choose your scrap below.', 'success');
            bookingForm.elements.name.focus();
        } catch (error) { message(error.message, 'error'); }
        finally { button.disabled = false; }
    });
    document.getElementById('verify-again')?.addEventListener('click', () => {
        authPanel.hidden = false; verifiedPanel.hidden = true; fields.disabled = true;
        otpForm.hidden = true; phoneForm.querySelector('[type=submit]').hidden = false; phoneForm.elements.phone.readOnly = false;
        phoneForm.elements.phone.focus();
    });
    document.getElementById('detect-location')?.addEventListener('click', event => {
        if (!navigator.geolocation) return message('Location is unavailable. Enter your full address.', 'error');
        const button = event.currentTarget; button.disabled = true;
        document.getElementById('location-status').textContent = 'Finding your location...';
        navigator.geolocation.getCurrentPosition(position => {
            pin = {latitude: position.coords.latitude, longitude: position.coords.longitude, accuracy: position.coords.accuracy};
            document.getElementById('location-status').textContent = `Location pin added (accuracy about ${Math.round(pin.accuracy)} m). Add a house number or landmark for the collector.`;
            document.getElementById('clear-location').hidden = false; button.disabled = false;
        }, () => {
            document.getElementById('location-status').textContent = 'Could not detect location. Please enter your full address or try again.';
            button.disabled = false;
        }, {enableHighAccuracy: true, timeout: 15000, maximumAge: 0});
    });
    document.getElementById('clear-location')?.addEventListener('click', event => {
        pin = null; event.currentTarget.hidden = true; document.getElementById('location-status').textContent = 'Location pin removed.';
    });
    bookingForm?.addEventListener('submit', async event => {
        event.preventDefault();
        const button = event.submitter;
        if (!submission) {
            const data = Object.fromEntries(new FormData(bookingForm));
            const items = [...bookingForm.querySelectorAll('[data-category]:checked')].map(input => {
                const value = document.getElementById(`qty-${input.value}`).value;
                return {category: input.value, quantity: value === '' ? null : Number(value)};
            });
            if (!items.length) return message('Select at least one scrap category.', 'error');
            if (!pin && (!data.address.trim() || !data.city.trim() || !data.pincode.trim())) return message('Enter your address, city and pincode, or detect your location.', 'error');
            submission = {...data, items, ...(pin || {}), request_key: requestKey};
        }
        fields.disabled = true; button.disabled = true; button.textContent = 'Placing order...'; message('Saving your pickup request...');
        try {
            const result = await api('create', submission);
            bookingForm.hidden = true; verifiedPanel.hidden = true;
            const confirmation = document.getElementById('pickup-confirmation'); confirmation.hidden = false;
            document.getElementById('order-reference').textContent = result.order.reference;
            document.getElementById('order-summary').textContent = `${result.order.items.map(item => item.name).join(', ')} · ${result.order.date || 'Next available day'} · ${result.order.slot}`;
            document.getElementById('download-receipt').href = `api/receipt.php?reference=${encodeURIComponent(result.order.reference)}`;
            document.getElementById('notification-status').textContent = 'Your order is saved. To notify our team, open WhatsApp below and tap Send. You can also download your pickup receipt.';
            try {
                const actions = await window.HALOrderActions(result.order);
                const share = document.getElementById('share-order'); share.href = actions.whatsapp; share.hidden = false;
            } catch { document.getElementById('notification-status').textContent = 'Your order is saved and your receipt is ready. The WhatsApp link is unavailable. Please contact our team.'; }
            message(); confirmation.focus();
            submission = null; requestKey = newKey();
        } catch (error) {
            // Keep the exact payload on unknown outcomes; retry cannot create a second order.
            if ([401, 403, 422, 429].includes(error.status)) { submission = null; fields.disabled = false; }
            if (error.status === 401) { authPanel.hidden = false; fields.disabled = true; phoneForm.querySelector('[type=submit]').hidden = false; otpForm.hidden = true; }
            if (error.status === 403) message('Your session changed. Reload this page before trying again.', 'error');
            else message(error.message + (submission ? ' Retry to check or finish the same order.' : ''), 'error');
            // Keep form values frozen for an uncertain response, but allow the same request to retry.
            button.disabled = false;
        } finally { button.textContent = submission ? 'Retry same order' : 'Place pickup order'; }
    });
    // Submit stays available for safe retries even while the input fieldset is frozen.
    async function init() {
        try {
            const result = await api('session');
            if (bookingForm) {
                const catalog = document.getElementById('scrap-categories');
                for (const [id, item] of Object.entries(result.categories)) {
                    const row = document.createElement('div'); row.className = 'scrap-option';
                    const label = document.createElement('label'); const checkbox = document.createElement('input');
                    checkbox.type = 'checkbox'; checkbox.value = id; checkbox.dataset.category = id;
                    label.append(checkbox, document.createTextNode(item.name));
                    const quantityLabel = document.createElement('label'); quantityLabel.className = 'quantity-label';
                    quantityLabel.textContent = `Approx. ${item.unit} (optional)`;
                    const quantity = document.createElement('input'); quantity.id = `qty-${id}`; quantity.type = 'number';
                    quantity.min = item.unit === 'pieces' ? '1' : '0.01'; quantity.max = '100000'; quantity.step = item.unit === 'pieces' ? '1' : '0.01';
                    quantity.className = 'account-input'; quantity.disabled = true; quantity.setAttribute('aria-label', `${item.name}: approximate ${item.unit}`);
                    checkbox.addEventListener('change', () => { quantity.disabled = !checkbox.checked; });
                    quantityLabel.append(quantity); row.append(label, quantityLabel); catalog.append(row);
                }
                const date = bookingForm.elements.date;
                const indiaDay = offset => {
                    const now = new Date(); const parts = new Intl.DateTimeFormat('en-CA', {timeZone:'Asia/Kolkata', year:'numeric', month:'2-digit', day:'2-digit'}).formatToParts(now);
                    const value = key => parts.find(part => part.type === key).value;
                    const day = new Date(`${value('year')}-${value('month')}-${value('day')}T00:00:00Z`); day.setUTCDate(day.getUTCDate() + offset);
                    return day.toISOString().slice(0, 10);
                };
                date.min = indiaDay(0); date.max = indiaDay(30); requestKey = newKey();
            }
            renderSession(result);
            if (!bookingForm && result.verifiedPhone) return location.replace('booking.html');
            const otpReady = result.otpReady === true;
            document.getElementById('otp-setup-note').hidden = otpReady;
            const sendButton = phoneForm.querySelector('[type=submit]');
            sendButton.disabled = !otpReady;
            sendButton.textContent = otpReady ? 'Send OTP' : 'OTP setup pending';
            if (!result.user) {
                const passed = new URLSearchParams(location.search).get('phone');
                if (passed && /^(?:\+91)?[6-9][0-9]{9}$/.test(passed)) phoneForm.elements.phone.value = passed.replace(/^\+91/, '');
            }
            message();
        } catch (error) { message(error.message, 'error'); }
    }
    setInterval(cooldown, 1000);
    window.addEventListener('pageshow', event => { if (event.persisted) location.reload(); });
    init();
})();
