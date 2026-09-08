# Changelog

## 1.5.0

### Changed: the bookings screen shows an empty state instead of HTTP 500 when its table is missing

This addon can be installed without its migrations having run — composer pulls the package in, the
utility registers itself, the nav item appears, and `bookings` still does not exist. The first
thing the screen did was ask that table a question, so the visitor got HTTP 500 and a stack trace
for what is really an unfinished setup. The screen now checks before its first query and renders a
setup page that names the missing table and says to run `php artisan migrate`.

The reason does not vanish with the 500: the guarded page writes to the log why it turned somebody
away. Otherwise the site would look installed and never work.

## 1.4.0

### New: three operational values in the Control Panel

Under **Settings → Addon Settings** there is a section for this addon, with two groups:

- **Endpoint:** the requests per minute and IP the endpoint accepts, and the permitted age of a
  signature. The second is the limit below which a delivery still counts: a signature does not
  say when it was created, and without this limit a recorded delivery stays valid forever.
  Empty switches the check off and is only defensible for a counterpart that sends no
  timestamp.
- **Retention:** after how many days `php please booking:prune` deletes a past booking. A
  booking carries a name and an address, so this is a data protection decision and not a
  technical one. Empty means "keep everything".

Only what someone changes is stored; everything else keeps following
`config/statamic-booking.php`.

Not on the page, and the group texts say so: the endpoints themselves, because each carries a
secret and a secret in a database row sits in every backup and every export. Likewise the
signature header, the algorithm and the timestamp header — the protocol contract with Cal.com.
Change one of them without the other side following, and the endpoint is switched off silently,
because a rejected signature looks like an attack and not like a typo.

The rate limit is on the page despite a hit in the service provider: the call sits inside the
closure passed to `RateLimiter::for()`, and that closure runs per request, not at boot. Checked
on 07.09.2026.

**New permission `manage booking settings`.** Nobody holds it at first, and until it is assigned
to a role the section stays invisible. Existing permissions are unchanged.

**Requires `goldnead/statamic-brand-context` 1.13 or later.** Older versions show the page but
do not apply its values reliably: on an installation with a single brand, the settings of the
addons that registered last never reached the config at all, and up to 1.12 saving the same
section a second time deleted the first save's override, without a message. If you set values
before the update, check afterwards that they are still there.

## 1.3.0

### Changed: the Bookings screen leaves Utilities

The screen is registered as a Statamic utility and therefore sat under Utilities, between Cache and
PHP Info (Adrian, 03.09.2026, F36). It now hangs in the sales section of the sidebar. This addon
does not depend on `statamic-payments`: when that addon is installed, its `SuiteNav::section()` is
asked for the shared section name so both land in the same section (Statamic does not translate
section names, so two spellings would give two half-filled sections); when it is not, the entry
gets a section of its own.

Route and permission are unchanged. The entry under Utilities is removed with `Nav::remove`,
because the first attempt on 04.09. only added the new section next to it and the screen appeared
twice.

### Fixed: code style on a test stand-in

`tests/Fakes/insights-contracts.php` failed `pint --test` since it arrived in 1.2.0, which kept the
Code style job red. Formatted. The declarations are unchanged, and `InsightsContractsMatchTest`
compares signatures by reflection, so nothing it checks has moved.

## 1.2.0

### Added: this addon's figures appear in Insights

From 1.1.0 `statamic-insights` is no longer a revenue report but the family's reporting layer: an
addon registers what it can count and gets the period, the comparison against the period before,
the chart, the breakdowns and two finished screens in return.

The coupling is optional in **both** directions. Without Insights nothing here is missing; without
this addon only its own group is missing over there. `suggest`, never `require`.

Every figure follows the contract's house rules: **null is not zero** (a rate with no denominator
has no answer and does not print 0 %), `available()` decides existence and never the data, gaps in
a series are filled by Insights rather than by the metric, and a filter a metric does not
understand is ignored rather than fatal.

Four figures: bookings, cancellations, cancellation rate, lead time.

### Fixed: the cancellation rate no longer reads as a contradiction

On screen it sat beside the cancellations as "2 cancellations, 0 %". Both were right — they simply
window on different columns, one on the day of the booking and one on the day of the cancellation.
The description now says so itself, in both languages, instead of leaving the reader to work it out.

## 1.1.0

### What's new

- **A screen in the Control Panel.** Utilities → Bookings: when, who, status, duration, endpoint.
  Built on core's `Listing`, so search, sorting, column choice and saved views behave exactly like
  the Entries screen. Read-only, because Cal.com owns these appointments and a cancel button here
  would put the site and the calendar out of step.
- Access is the `access bookings utility` permission, which core registers along with the screen.
  **This is the only place names and addresses are shown**; the Antlers tags still carry none of it.
- **Filters for status and for upcoming vs. past**, built as real Statamic filters: they show a
  badge, survive sorting and paging, and can be kept as a saved view.
- The column picker works and is remembered per user, and the buyer's address is a column of its own
  that stays hidden until asked for. A listing shared on a projector should not carry it by default.
- CI now rebuilds the committed Control Panel bundle and fails if it differs from the sources. A
  source change shipping without a rebuild would leave every installed site running the old screen,
  with green tests and no symptom to trace.


## 1.0.0

Initial release. Cal.com webhook endpoint with per-funnel secrets, HMAC verification, idempotent
recording, three events, two Antlers tags, and a retention command.
