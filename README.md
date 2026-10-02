# Parnian Pay for OpenCart

Accept **ParnianCoin (PARC)** payments in your OpenCart store. Payments go **directly from the buyer's wallet to your own PARC account**. The gateway never holds your funds or keys.

Supports **OpenCart 3.0.x** and **OpenCart 4.0.2+ / 4.1.x**.

[فارسی](README.fa.md) · [Website](https://pay.parniancoin.com) · [API docs](https://pay.parniancoin.com/docs) · [Download](../../releases/latest)

---

## Which file do I need?

| Your OpenCart | Download from Releases | Source folder |
|---|---|---|
| 4.0.2+ / 4.1.x | `parnian_pay.ocmod.zip` (**do not rename** it) | [`4.x/`](4.x) |
| 3.0.x | `parnian_pay-opencart3.ocmod.zip` | [`3.x/`](3.x) |

The two packages are **not** interchangeable.

## Features

- **Non-custodial**: funds go straight to the merchant's PARC account; no third party holds your money.
- **Price in your own currency**: products stay priced in your store currency (IRT, IRR, USD and more). The gateway converts to PARC using the rate you set in your merchant dashboard.
- **Hosted payment page**: buyers pay on `pay.parniancoin.com` with a QR code or one-click web wallet payment. Private keys and passwords are never entered on your store.
- **Server-side verification**: signed webhooks (HMAC-SHA256), plus a re-check of the invoice when the buyer returns.
- **Separate statuses** for paid, expired and partial/late payments (needs review).
- Payment method is **hidden automatically** when the gateway is inactive or no rate is set.
- **Multilingual**: English, Persian and Arabic.

## How it works

```
Buyer ──► Your store ──(create invoice, API key)──► pay.parniancoin.com
                                                        │
Buyer ◄──────────── redirected to payment page ◄────────┘
  │
  └──► pays from own wallet ──► ParnianCoin blockchain ──► your PARC account
                                                        │
Your store ◄──── signed webhook + verify ◄──────────────┘  → order confirmed
```

## Requirements

- OpenCart 3.0.x, or 4.0.2+ / 4.1.x
- PHP 7.4 or later with the `curl` and `json` extensions
- HTTPS on your store (`http://localhost` is allowed for testing)
- An **approved** merchant account on [pay.parniancoin.com](https://pay.parniancoin.com)

## 1. Get your merchant account

1. Go to [pay.parniancoin.com](https://pay.parniancoin.com) and sign in with **Google** or **Microsoft**.
2. Enter your store details: store name, **website domain**, and your **PARC account number** (where payments will be received).
3. Add a **conversion rate** for your store currency (for example, how many IRT equal 1 PARC).
4. Wait for administrator approval. Your account cannot take payments until it is approved.
5. After approval, copy your **API key** and **Webhook signing secret** from the dashboard.

> **Note:** Any change to your profile, PARC account or website sends your account back for review. Payments are paused until it is approved again.

## 2. Install

1. Download the zip for your OpenCart version from the [latest release](../../releases/latest) (see the table above).
2. Go to **Extensions → Installer** and upload the zip.
   - **OpenCart 4.x:** click **Install** next to the uploaded package.
   - **OpenCart 3.x:** then go to **Extensions → Modifications** and click **Refresh**.
3. Go to **Extensions → Extensions**, choose **Payments** from the filter, find **Parnian Pay**, click **Install** (green +), then **Edit**.

> ⚠️ Do **not** use GitHub's green **Code → Download ZIP** button. Always install the zip from Releases.

## 3. Configure

| Setting | Description |
|---|---|
| API key | From your merchant dashboard |
| Webhook signing secret | From your merchant dashboard |
| Order Status (paid) | Status set when payment is confirmed, e.g. *Processing* |
| Geo Zone | Limit the method to a region, or *All Zones* |
| Status | Enable / disable the payment method |
| Sort Order | Position among payment methods at checkout |

Save the settings. Then copy the **Webhook URL** shown on the settings page into your Parnian Pay dashboard.

> The conversion rate is **not** set in the extension. It is managed per currency in your merchant dashboard, so all your stores and plugins use the same rate.

## Payment page and your domain

For your buyers' safety, the payment page only opens when the buyer arrives from the **website domain approved in your merchant account**. If your store moves to a new domain, update it in the dashboard first (this triggers a new review).

## Troubleshooting

- **Payment method does not appear at checkout**: make sure its status is enabled, the geo zone matches the buyer's address, your store currency has a rate in the dashboard, and your merchant account is approved.
- **OpenCart 4: "invalid file" on upload**: the file was renamed. Use the original `parnian_pay.ocmod.zip` name.
- **OpenCart 3: changes not visible after install**: refresh **Extensions → Modifications** and clear the theme cache from the Dashboard (gear icon).
- **Payment page shows an access error**: the buyer did not come from your approved domain, or your account is under review.
- **Order not confirmed after paying**: check that your site is reachable over HTTPS, that the webhook URL and secret match the dashboard, and that no firewall blocks incoming requests from the gateway.

## Security

- Never share your API key or webhook signing secret, and never commit them to a repository.
- Buyers must never be asked for a private key or wallet password on your store.
- To report a vulnerability, see [SECURITY.md](SECURITY.md). Please do not open a public issue.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

Released under the [GNU General Public License v3.0](LICENSE).
