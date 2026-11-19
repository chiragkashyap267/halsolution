# Business details used for the revamp

The existing website at https://halsolution.com/ is the source of business information. The homepage fetch timed out; its indexed service pages and the live contact/about pages were available through web search on 20 November 2026.

- Company: Hal Solution Private Limited (the supplied logo uses HAL SOLUTION pvt. Ltd.). Source: https://www.halsolution.com/about
- Address: K-58, Site V, UPSIDC, Greater Noida, Uttar Pradesh 201308. Source: https://www.halsolution.com/contact
- Email: info@halsolution.com. Source: https://www.halsolution.com/contact
- Business phone and WhatsApp: +91 8851 644 415, explicitly supplied by the owner in this chat. This intentionally overrides the older number on the existing website.
- Services: electronics recycling, refurbishment, secure data destruction, EPR planning support, business/residential collection and recycling events. Sources: https://www.halsolution.com/about and https://www.halsolution.com/epr
- Branding: the owner-supplied `images/logo.png` is used in public headers, mobile menus, branded footers, booking, and downloaded receipts.

Edit `business-config.json` to update the business phone in one place. The same file supplies the contact details printed on receipts. `business.js` builds public call/WhatsApp links and formats the displayed number from that single phone value.

No current legal certification, authorization expiry, prices, pickup volume, customer count, media endorsement or testimonial was independently verified from the legacy site. Existing template claims are not proof of those facts. The old site's certificates/documents should not be treated as a customer's order receipt or invoice.
