# Changelog

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
