(() => {
    window.HALOrderActions = async order => {
        const business = await window.HALBusiness;
        if (!business) throw new Error('Business contact is unavailable.');
        const items = order.items.map(item => `${item.name}${item.quantity === null ? '' : ` (${item.quantity} ${item.unit})`}`).join(', ');
        const address = [order.address, order.city, order.pincode].filter(Boolean).join(', ');
        const lines = ['Hello HAL Solution, I placed a pickup request.', `Order: ${order.reference}`,
            `Name: ${order.name}`, `Mobile: ${order.phone}`, `Scrap: ${items}`];
        if (address) lines.push(`Address: ${address}`);
        if (order.latitude !== null) lines.push(`Location: https://maps.google.com/?q=${order.latitude},${order.longitude}`);
        lines.push(`Preferred date: ${order.date || 'Next available day'}`, `Time: ${order.slot}`);
        if (order.notes) lines.push(`Notes: ${order.notes}`);
        lines.push('Please confirm pickup availability.');
        return {whatsapp: business.whatsapp(lines.join('\n')), receipt: `api/receipt.php?reference=${encodeURIComponent(order.reference)}`};
    };
})();
