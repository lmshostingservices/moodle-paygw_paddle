# Paddle payment gateway for Moodle

`paygw_paddle` lets a Moodle site take one-off payments through
[Paddle Billing](https://www.paddle.com/). Paddle acts as Merchant of Record, so it
calculates, collects and remits VAT, GST and sales tax on your behalf, and it issues
the invoice to the learner.

Payment is confirmed by a signed webhook from Paddle rather than by the learner
returning to the site, so a closed browser tab or a flaky connection cannot leave a
paid learner unenrolled.

## Requirements

- Moodle 4.1 LTS or later (tested up to Moodle 5.0)
- PHP 8.0 or later
- Outbound HTTPS access from the web server to `api.paddle.com`
  (or `sandbox-api.paddle.com`)
- Learners' browsers must be able to load `https://cdn.paddle.com/paddle/v2/paddle.js`,
  which is fetched at checkout time. The plugin bundles no third-party code; this
  script is loaded from Paddle's CDN because Paddle requires it.
- A Paddle account. A sandbox account is enough to evaluate the plugin.

## Installation

1. Copy the plugin into `payment/gateway/paddle` in your Moodle directory, or install
   the ZIP through *Site administration > Plugins > Install plugins*.
2. Visit *Site administration > Notifications* to complete the database upgrade.

The plugin creates three tables: transactions, webhook events, and optional
course-to-price mappings.

## Setting up Paddle

You will need three values from your Paddle dashboard, all under
*Developer tools > Authentication* except the last:

| Value | Where to find it | Used for |
| --- | --- | --- |
| API key | Developer tools > Authentication | Server-to-server calls that create the transaction |
| Client-side token | Developer tools > Authentication | Initialising the checkout overlay in the browser |
| Webhook signing secret | Developer tools > Notifications | Verifying that notifications really came from Paddle |

### 1. Create a product

In *Catalog > Products*, create a product — one is enough for the whole site, for
example "Course enrolment". Copy its ID, which starts with `pro_`.

With a product ID configured, each course is charged the cost you set in its
*Enrolment on payment* settings. You do not need a separate Paddle price per course.

### 2. Add the notification destination

In *Developer tools > Notifications*, add a destination pointing at:

```
https://YOUR-MOODLE-SITE/payment/gateway/paddle/webhook.php
```

Subscribe it to at least these events:

- `transaction.completed` — confirms payment and enrols the learner
- `transaction.payment_failed` — records the failure
- `adjustment.created` and `adjustment.updated` — refunds and chargebacks

Copy the signing secret Paddle shows for this destination.

### 3. Approve your domain

In *Checkout > Checkout settings*, add your Moodle site's domain to the approved
domains list. Paddle's overlay refuses to open on an unapproved domain.

### 4. Configure the payment account in Moodle

Go to *Site administration > Payments > Payment accounts*, open (or create) an
account, and enable the Paddle gateway. Fill in the API key, client-side token,
webhook signing secret, environment, and the product ID from step 1.

Start in **Sandbox** and switch to **Live** only after a successful sandbox purchase.

### 5. Enable payment on a course

Add the *Enrolment on payment* method to a course, choose the payment account, and
set the cost and currency.

## How a payment flows

1. The learner chooses Paddle in the payment modal and lands on a short billing
   details form. This exists because Paddle's own overlay asks only for email,
   country and postcode — the name and street address that appear on the invoice
   have to be collected first.
2. The plugin creates a Paddle customer, attaches the address, and creates the
   transaction.
3. Paddle's checkout overlay opens and takes payment.
4. Paddle sends `transaction.completed` to the webhook endpoint. The plugin
   verifies the signature, records the payment and enrols the learner.
5. The return page polls for up to a minute and then tells the learner what to do
   if confirmation is still outstanding.

## Optional settings

**Refund action** — whether an approved refund or chargeback unenrols the learner
or leaves the enrolment alone. Set per payment account.

**Custom checkout URL** — overrides the built-in checkout page. Whatever you set
must also be an approved domain in Paddle.

**Default price ID** — for sites that manage prices inside the Paddle catalog
rather than in Moodle. When set it overrides the product ID, and Paddle rather
than Moodle controls the amount charged.

**Price mapping** (*Site administration > Plugins > Payment gateways > Paddle price
mapping*) — points individual courses at specific Paddle prices. Most sites do not
need this; use it only if you already manage prices in the Paddle catalog.

## Reports

*Site administration > Plugins > Payment gateways > Paddle transaction reports*
lists transactions and the webhook events received, with search, status filtering
and CSV export. The webhook events tab is the first place to look when a payment
did not result in an enrolment.

Two capabilities control access:

- `paygw/paddle:viewreports` — view the reports
- `paygw/paddle:manage` — manage price mappings

## Troubleshooting

**The learner paid but was not enrolled.** Check the webhook events tab. No rows
at all means Paddle is not reaching the endpoint: confirm the destination URL and
that the endpoint is reachable from the internet. Rows with a result of *Error*
carry the reason. A 403 at Paddle's end means the signing secret does not match
the one on the payment account.

**"Something went wrong" in the checkout overlay.** Usually the site's domain is
not on Paddle's approved domains list, or the client-side token belongs to a
different environment than the one selected in Moodle.

**The invoice has no name or address.** The billing details form must have been
completed. If the fields were filled in and the invoice is still bare, turn on
developer debugging and repeat the payment: the plugin logs any failure to create
the Paddle customer or address.

**Prices look wrong.** A course-level price mapping silently overrides the default
product ID. Check the price mapping page before assuming the enrolment cost is at
fault.

## Privacy

The plugin sends the payer's name, email address, street address, suburb, postcode,
country and — where given — company name to Paddle, along with the Moodle user ID
as transaction metadata. It stores payment records and the notification bodies
Paddle sends, which contain the same details.

A data deletion request anonymises these records rather than removing them: the
link to the Moodle user is cleared and the stored notification body is discarded,
while the financial record itself is kept, as sites generally must for tax and
audit purposes.

## Development

Run the checks the plugins directory runs:

```
moodle-plugin-ci phplint
moodle-plugin-ci phpcs --max-warnings 0
moodle-plugin-ci mustache
moodle-plugin-ci grunt
moodle-plugin-ci phpunit
```

If you change anything under `amd/src`, rebuild with `grunt amd` and commit the
regenerated files in `amd/build`.

## Licence

GNU GPL v3 or later. See [LICENSE](LICENSE).
