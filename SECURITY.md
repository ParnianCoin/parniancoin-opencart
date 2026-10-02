# Security Policy

## Supported versions

Only the latest release receives security fixes. Please always run the most recent version from [Releases](../../releases/latest).

## Reporting a vulnerability

**Please do not open a public GitHub issue for security problems.**

Email **info@parniancoin.com** with:

- a description of the issue and its impact,
- steps to reproduce (or a proof of concept),
- the plugin version and platform version you tested on.

We will acknowledge your report within **72 hours** and keep you informed until it is resolved. Please give us reasonable time to release a fix before disclosing the issue publicly.

## Scope

In scope: this plugin, and the Parnian Pay gateway at `pay.parniancoin.com` (API, webhooks, hosted payment page).

Out of scope: vulnerabilities in WordPress/WooCommerce, OpenCart or PrestaShop themselves, and issues that require a compromised merchant server or leaked API keys.

## Good practice for merchants

- Keep your **API key** and **webhook signing secret** private. Never commit them to a repository.
- If you think a key has leaked, regenerate it in your merchant dashboard immediately.
- Buyers must never be asked for a private key or wallet password on your store.
