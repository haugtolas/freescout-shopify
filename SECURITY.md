# Security

## Reporting a vulnerability

Please do not open a public issue. Use **Security → Report a vulnerability** on this repository (GitHub private vulnerability reporting). You will get an answer as soon as possible.

## Security model

- Read-only access to the Shopify Admin API (`read_orders`, `read_customers`, optionally `read_all_orders`).
- Every request requires a logged-in FreeScout user who can view the conversation, and is rate-limited.
- The shop domain must be `*.myshopify.com`; calls use HTTPS and do not follow redirects.
- Client secret and access token are encrypted at rest; secrets are masked in forms and never logged.
- Search results are verified (exact email / order number) before being shown.
- Optional setting to restrict lookups to the orders of the conversation's customer.
- Shopify data is escaped on output; links are limited to http(s).
