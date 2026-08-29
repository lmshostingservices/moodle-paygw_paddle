# Changelog

## 1.0.34 (2026-08-29)

Release pipeline compliance pass on top of 1.0.33. No functional or schema changes.

- Gateway credential fields use `PARAM_TEXT` instead of `PARAM_RAW_TRIMMED`.
- `thirdpartylibs.xml` restored as an explicit empty declaration.
- Language strings are single-line assignments again, as AMOS expects.
- `function (` spacing corrected in PHP closures and all three AMD modules.
- Removed the blank line after each class opening brace.
- Multi-line calls reflowed so no arguments share the line with the opening
  parenthesis, except where the final argument is an array literal — the form
  Moodle core itself uses.
- Maturity set back to `MATURITY_STABLE`.
- Changelog renamed from `CHANGES.md` to `CHANGELOG.md`.
- Added an explicit note in each entry-point script explaining why it does or
  does not need a capability check.

## 1.0.33 (2026-08-29)

A review pass ahead of submission to the Moodle plugins directory. No database
schema changes.

### Fixed

- **Privacy.** The provider now declares that billing details are sent to Paddle,
  declares the `paygw_paddle_events` table (whose stored notification bodies contain
  the payer's name, email and address), exports both tables, and clears the stored
  payloads on a deletion request. The `privacy:metadata` string that wrongly claimed
  the plugin stored no personal data has been removed.
- **CSV export.** Exporting transactions produced a broken page instead of a file,
  because HTTP headers were sent after page output had started. The export now runs
  before any output and streams rows through `\core\dataformat` rather than loading
  every transaction into memory.
- **Version number.** 1.0.32 shipped with the same `$plugin->version` as 1.0.31, so
  it never installed over it.
- **Country.** The country resolved from the billing form was discarded in favour of
  the (possibly empty) profile country when building the checkout data, defeating the
  1.0.30 fix for learners with no country set.
- **Surcharge.** A gateway surcharge configured by an administrator was shown to the
  learner but never added to the amount charged.
- **Bootstrap 5.** Status badges and the report filter bar rendered unstyled on
  Moodle 4.4 and later. Markup now carries both the Bootstrap 4 and Bootstrap 5
  class names.
- **Duplicate webhook deliveries.** Recording an event and marking it processed were
  not atomic, so two concurrent deliveries of the same notification could both enrol
  the learner. The unique index is now used as the lock, and delivery additionally
  checks for an existing payment record.
- **Delete confirmation.** The price mapping delete confirmation was built with an
  inline `onclick` containing a translated string, and broke for any language whose
  translation contains an apostrophe.

### Changed

- Paddle.js is now loaded through RequireJS instead of by overwriting `window.define`
  and `window.require` around a plain script tag. All checkout behaviour moved into
  the `paygw_paddle/checkout` AMD module.
- The billing form moved from inline styles in PHP to a Mustache template plus
  `styles.css`, and its country list comes from Moodle rather than a hardcoded set
  of 28 countries.
- Webhook handling moved from unprefixed global functions into
  `\paygw_paddle\webhook_handler`.
- The reports and price mapping pages are registered in the admin tree under
  Payment gateways, and check the plugin's own capabilities instead of
  `moodle/site:config`.
- Price mappings are edited through a proper Moodle form with an autocomplete course
  selector, rather than a hand-built form that loaded every course on the site.
- Refund action and custom checkout URL are now configurable per payment account.
  Both were previously read at runtime with no way to set them.
- The return page polls a bounded number of times and then explains what to do,
  instead of reloading every five seconds forever.
- All user-facing strings go through `get_string()`. Around twenty were hardcoded
  English.
- Paddle API calls all go through Moodle's `curl` wrapper, including PATCH.
- Added PHPUnit coverage for webhook signature verification, currency conversion and
  webhook idempotency.
- Added this changelog and a real README; removed `BUILD_INFO.json`, an empty
  `lib.php` and an empty `thirdpartylibs.xml`.
- Declared support for Moodle 4.1 LTS to 5.0; maturity lowered to release candidate
  for the first public release.

## 1.0.32 (2026-07-22)

Reports page fatal error fixed: `html_writer::tag('br')` replaced with
`html_writer::empty_tag('br')`.

## 1.0.31 (2026-07-22)

Checkout no longer passes both `customer.id` and a raw `customer.address` to
`Paddle.Checkout.open()`, which Paddle rejected with "Something went wrong".

## 1.0.30 (2026-07-21)

Country is asked for when the learner's profile has none, so the checkout overlay no
longer fails for those learners.

## 1.0.29 (2026-06-04)

Savepoint marker for a clean upgrade path.

## 1.0.27 (2026-05-12)

Paddle API calls moved from raw `curl_init()` to Moodle's `curl` wrapper.

## 1.0.25 (2026-05-07)

Address line 1, suburb and postcode made mandatory on the billing form.

## 1.0.24 (2026-05-06)

Customer lookup by email fixed. The previous `email[]` filter is not a valid Paddle
parameter, so Paddle ignored it and returned the most recently created customer
regardless of email, mixing up customer records.

## 1.0.22 (2026-05-01)

Buyer name now appears on Paddle invoices, by creating a customer and address through
the Paddle API rather than sending the individual's name as a business name.

## 1.0.20 (2026-04-29)

Pre-checkout billing details form added.

## 1.0.19 (2026-04-29)

Moodle profile data passed to Paddle so checkout is prefilled.

## 1.0.18 (2026-04-17)

Worked around Paddle.js's UMD wrapper conflicting with RequireJS. Superseded in
1.0.33.

## 1.0.17 (2026-04-16)

Removed `js_init_code()` from the checkout page, which caused an AMD conflict and an
over-length cache key.

## 1.0.16 (2026-04-04)

Resynchronised the stale `amd/build/gateways_modal.min.js`.

## 1.0.15 (2026-03-30)

Removed `admin_externalpage_setup()` calls referring to unregistered page IDs.

## 1.0.13 (2026-03-30)

Added the missing non-minified AMD build file and the default product ID setting.

## 1.0.12 (2026-03-29)

Added the `gateways_modal` AMD module required by core's payment modal.

## 1.0.10 (2026-03-28)

Set `$settings = null` to stop Moodle creating a duplicate admin page.
