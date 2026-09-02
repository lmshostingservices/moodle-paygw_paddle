# Changelog

## [v1.0.39] - 2026-09-02

Coding style only. No settings, database schema, language string text or
payment behaviour changes. Every change here clears a Moodle Code Checker or
Grunt finding that the release workflow was hiding behind `continue-on-error`,
and that Moodle Marketplace runs and publishes on the plugin listing.

### Fixed

- **`classes/paddle_helper.php`: side effect at file scope.** Release 1.0.37
  put `global $CFG; require_once($CFG->libdir . '/filelib.php');` at the top of
  the file to fix the `Class "curl" not found` regression. A namespaced class
  file is not allowed side effects at file scope
  (`moodle.Files.MoodleInternal`). The `require_once` now sits inside
  `api_call()`, the one method that constructs `\curl`, which is where the
  dependency belongs. The 1.0.37 fix still holds.
- **Multi-line `if` conditions reformatted** in `classes/paddle_helper.php`,
  `classes/webhook_handler.php` and `classes/form/pricemap_form.php`: first
  expression on the line after the opening parenthesis, closing parenthesis on
  its own line.
- **`classes/privacy/provider.php`: interface order.** The `implements` list is
  now in the order `Universal.OOStructures.AlphabeticExtendsImplements`
  expects.
- **`db/upgrade.php`: removed the `MOODLE_INTERNAL` guard.** The file declares a
  function and has no side effects, so the check is flagged as unnecessary.
- **`lang/en/paygw_paddle.php`: strings sorted.** All 156 keys are now in the
  byte order `moodle.Files.LangFilesOrdering` requires, and the topic section
  comments have been removed — any comment between strings raises
  "Unexpected comment found" and stops the checker's own auto-fixer. No string
  key or value changed; only their order.
- **`amd/src/checkout.js`: comment capitalisation** on the RequireJS context
  comment (ESLint `capitalized-comments`). AMD build artefacts regenerated;
  the minified output is byte-identical, only the source map changed.

## [v1.0.38] - 2026-09-02

No settings or database schema changes. Both changes are required to pass
Moodle Plugin CI; neither alters payment behaviour.

### Fixed

- **Mustache Lint: invalid `autocomplete` value on the address field.** The
  address line in `templates/billing_form.mustache` used
  `autocomplete="street-address"`. That autofill token is defined for multiline
  controls only, so HTML validation rejects it on a single-line `<input>`. The
  field now uses `autocomplete="address-line1"`, which is the correct token for
  a single address line and matches the field's own label. Browser autofill
  behaviour is unchanged in practice.
- **PHPUnit: unasserted `debugging()` call in the refund test.**
  `webhook_handler_test::test_approved_refund_marks_the_transaction_refunded`
  builds a transaction whose item id has no payable behind it, so
  `get_refund_action()` correctly falls back to the site default and reports
  that through `debugging()`. Moodle's test runner fails any test that emits
  debugging output without asserting it. The test now asserts the call. No
  change to `webhook_handler`: the fallback and its diagnostic are the intended
  behaviour.

## [v1.0.37] - 2026-09-01

No settings or database schema changes.

### Fixed

- **Regression from 1.0.33: `Exception - Class "curl" not found` when starting a
  payment.** `paddle_helper` uses Moodle's `\curl` wrapper, which is defined in
  `lib/filelib.php` — a core library that is not autoloaded. Up to 1.0.32,
  `pay.php` and `process.php` included `course/lib.php`, which pulled
  `filelib.php` in as a side effect. The 1.0.33 rewrite removed that include as
  unused, and with it the only thing loading the `curl` class, so any site whose
  request had not otherwise loaded `filelib.php` hit a fatal error at checkout.
  `classes/paddle_helper.php` now requires `filelib.php` explicitly, which is
  where the dependency actually belongs.
- Anonymous functions in `amd/src` use `function(` without a space, matching
  Moodle's JavaScript style (`space-before-function-paren: never`) and the
  release pipeline's `amd_function_space` check. Release 1.0.34 changed these to
  `function (` by applying the PHP closure rule to JavaScript; PHP and JavaScript
  differ here, and the JavaScript spelling was wrong from 1.0.34 to 1.0.36.
  Affects 16 lines across `checkout.js`, `gateways_modal.js` and
  `poll_status.js`. AMD build artifacts regenerated to match.

## [v1.0.36] - 2026-09-01

Version reissue. The code is identical to 1.0.35, which was withdrawn from
staging before release: it had been built as an uploaded artifact with no
corresponding repository commit or immutable tag, so it could never satisfy the
release provenance gate. 1.0.36 carries the same changes under a number that has
never been staged.

See the 1.0.35 entry below for what actually changed. No PHP behaviour,
settings or database schema changes in either release.

## [v1.0.35] - 2026-09-01

JavaScript only. No PHP behaviour, settings or database schema changes.

### Fixed

- Paddle.js is loaded through a RequireJS context belonging to this plugin
  (`context: 'paygw_paddle'`) instead of by calling `requirejs.config()` on the
  default context. The default context is the one Moodle core uses, including
  for its jQuery isolation map, and reconfiguring it at runtime can disturb
  module resolution elsewhere on the page. The named context is created fresh
  and shares nothing with it, while still letting RequireJS request the script
  so Paddle.js's anonymous `define()` is attributed correctly.

  A plain `<script>` tag was considered and rejected: Paddle.js is a UMD bundle
  that registers through `define()` whenever one exists, which is what produced
  the "Mismatched anonymous define() module" failures in 1.0.17 and 1.0.18.

### Changed

- Changelog headings use the `## [vX.Y.Z] - YYYY-MM-DD` form, and the file is
  named `CHANGELOG.md`.
- The webhook's `$_SERVER` signature-header read carries a comment explaining
  why no Moodle wrapper is used and how the value is treated.

## [v1.0.34] - 2026-08-29

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
- Added an explicit note in each entry-point script explaining why it does or
  does not need a capability check.

## [v1.0.33] - 2026-08-29

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

## [v1.0.32] - 2026-07-22

Reports page fatal error fixed: `html_writer::tag('br')` replaced with
`html_writer::empty_tag('br')`.

## [v1.0.31] - 2026-07-22

Checkout no longer passes both `customer.id` and a raw `customer.address` to
`Paddle.Checkout.open()`, which Paddle rejected with "Something went wrong".

## [v1.0.30] - 2026-07-21

Country is asked for when the learner's profile has none, so the checkout overlay no
longer fails for those learners.

## [v1.0.29] - 2026-06-04

Savepoint marker for a clean upgrade path.

## [v1.0.27] - 2026-05-12

Paddle API calls moved from raw `curl_init()` to Moodle's `curl` wrapper.

## [v1.0.25] - 2026-05-07

Address line 1, suburb and postcode made mandatory on the billing form.

## [v1.0.24] - 2026-05-06

Customer lookup by email fixed. The previous `email[]` filter is not a valid Paddle
parameter, so Paddle ignored it and returned the most recently created customer
regardless of email, mixing up customer records.

## [v1.0.22] - 2026-05-01

Buyer name now appears on Paddle invoices, by creating a customer and address through
the Paddle API rather than sending the individual's name as a business name.

## [v1.0.20] - 2026-04-29

Pre-checkout billing details form added.

## [v1.0.19] - 2026-04-29

Moodle profile data passed to Paddle so checkout is prefilled.

## [v1.0.18] - 2026-04-17

Worked around Paddle.js's UMD wrapper conflicting with RequireJS. Superseded in
1.0.33.

## [v1.0.17] - 2026-04-16

Removed `js_init_code()` from the checkout page, which caused an AMD conflict and an
over-length cache key.

## [v1.0.16] - 2026-04-04

Resynchronised the stale `amd/build/gateways_modal.min.js`.

## [v1.0.15] - 2026-03-30

Removed `admin_externalpage_setup()` calls referring to unregistered page IDs.

## [v1.0.13] - 2026-03-30

Added the missing non-minified AMD build file and the default product ID setting.

## [v1.0.12] - 2026-03-29

Added the `gateways_modal` AMD module required by core's payment modal.

## [v1.0.10] - 2026-03-28

Set `$settings = null` to stop Moodle creating a duplicate admin page.
