# ParnianCoin Payment Gateway for OpenCart

Accept **ParnianCoin (PARC)** payments in your OpenCart store. Payments go **directly from the buyer's wallet to your own PARC account**. The gateway never holds your funds.

Supports **OpenCart 3.x** and **OpenCart 4.x**.

[فارسی](README.fa.md) · [Website](https://pay.parniancoin.com) · [Releases](../../releases)

---

## Repository layout

| Folder | For |
|---|---|
| [`3.x/`](3.x) | OpenCart 3.0.x |
| [`4.x/`](4.x) | OpenCart 4.0.x |

Use **only** the folder that matches your OpenCart version. The two are not interchangeable.

## Features

- **Non-custodial**: funds are sent straight to the merchant's PARC account; no third party holds your money.
- **Price in your own currency**: products stay priced in your store currency (IRT, IRR, USD and 20+ more). The gateway converts to PARC using the rate you set in your merchant dashboard.
- **Secure hosted payment page**: buyers pay on `pay.parniancoin.com`. Private keys and passwords are never entered on your store and never reach any server.
- **Two ways to pay**: the ParnianCoin web wallet, or manual payment by QR code / account number from any PARC wallet.
- **Server-to-server verification**: an order is confirmed only after the gateway verifies the transaction on-chain, never on the basis of a browser redirect alone.
- **Signed webhooks** (HMAC + timestamp) for instant order status updates.
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

- OpenCart 3.0.x or 4.0.x
- PHP with the `curl` and `json` extensions
- HTTPS on your store
- An **approved** merchant account on [pay.parniancoin.com](https://pay.parniancoin.com)

## 1. Get your merchant account

1. Go to [pay.parniancoin.com](https://pay.parniancoin.com) and sign in with **Google** or **Microsoft**.
2. Enter your store details: store name, **website domain**, and your **PARC account number** (where payments will be received).
3. Set your **conversion rate** for your store currency (for example, how many IRT equal 1 PARC).
4. Wait for administrator approval. Your account cannot take payments until it is approved.
5. After approval, copy your **API Key** and **Webhook Secret** from the dashboard.

> **Note:** Any change to your profile, PARC account or website sends your account back for review. Payments are paused until it is approved again.

## 2. Install

Download the package for your version from [Releases](../../releases).

### OpenCart 3.x

**Option A: Extension Installer**
1. Go to **Extensions → Installer** and upload `parniancoin-oc3.ocmod.zip`.
2. Go to **Extensions → Modifications** and click **Refresh**.

**Option B: Manual**
Copy the contents of `3.x/upload/` into your OpenCart root directory.

Then go to **Extensions → Extensions**, choose **Payments** from the filter, find **ParnianCoin** and click **Install** (green +), then **Edit**.

### OpenCart 4.x

1. Go to **Extensions → Installer**, upload `parniancoin-oc4.ocmod.zip`, then click **Install** next to it.
2. Go to **Extensions → Extensions**, choose **Payments**, find **ParnianCoin** and click **Install**, then **Edit**.

## 3. Configure

| Setting | Description |
|---|---|
| API Key | From your merchant dashboard |
| Webhook Secret | From your merchant dashboard |
| Order Status (paid) | Status set when payment is confirmed, e.g. *Processing* |
| Geo Zone | Limit the method to a region, or *All Zones* |
| Status | Enable / disable the payment method |
| Sort Order | Position among payment methods at checkout |

Save the settings. The **webhook URL** shown on the settings page must match the one registered in your merchant dashboard.

> The conversion rate is **not** set in the extension. It is managed per currency in your merchant dashboard, so all your stores and plugins use the same rate.

## Payment page and your domain

For your buyers' safety, the payment page only opens when the buyer arrives from the **website domain approved in your merchant account**. If your store moves to a new domain, update it in the dashboard first (this triggers a new review).

## Troubleshooting

- **Payment method does not appear at checkout**: make sure its status is enabled, the geo zone matches the buyer's address, your store currency has a rate in the dashboard, and your merchant account is approved.
- **OpenCart 3: changes not visible after install**: refresh **Extensions → Modifications** and clear the theme cache from the Dashboard (gear icon).
- **Payment page shows an access error**: the buyer did not come from your approved domain, or your account is under review.
- **Order not confirmed after paying**: check that your site is reachable over HTTPS, that the webhook URL and secret match the dashboard, and that no firewall blocks incoming requests from the gateway.

## Security

- Never share your API Key or Webhook Secret, and never commit them to a repository.
- Buyers must never be asked for a private key or wallet password on your store.
- To report a vulnerability, please follow [SECURITY.md](SECURITY.md) instead of opening a public issue.

## License

Released under the [GNU General Public License v3.0](LICENSE).
