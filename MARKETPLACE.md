# Statamic Booking — Marketplace

## Price

**$49, one edition.** Decided by Adrian on 2026-08-24.

One product, no Core/Pro split, so `extra.statamic.editions` stays absent from `composer.json`. The
field earns its place when a feature actually sits behind a tier; inventing one here would be
decoration.

### Why above the cookie addon at $39

Two reasons, and neither is "it took longer to build".

**It carries more responsibility.** A cookie banner that fails shows the wrong text. A booking
endpoint that fails loses an appointment somebody made, and the site owner finds out when the person
turns up and nobody is there. The signature check, the idempotency and the requested/rejected
handling exist for that difference.

**It replaces something visible.** The alternative is Cal.com's own embed, which loads Cal.com's
script into the visitor's browser on every page it sits on. This addon takes the booking through a
signed webhook instead, so the page loads nothing from Cal.com at all. For a site that has just
installed a consent banner, that is not a detail: an embed is a third party to declare, block and
explain, and this one removes the need.

### What it does that the alternatives do not

| Way to do it | Third-party script on the page | Bookings in your own site | Cost |
|---|---|---|---|
| **Statamic Booking** | **none** | **yes, in the database** | **$49** |
| Cal.com embed | yes, on every page with the embed | no | free |
| Cal.com iframe | yes, plus an iframe | no | free |
| Hand-built webhook | none | yes, once you build it | a day or two, then yours to maintain |

The honest comparison is the last row: this is a job a developer can do in a day. What is bought is
the day, plus the parts that are only obvious after something has gone wrong — a delivery replayed,
a booking rescheduled after it was cancelled, a name leaking into a public template through a field
that looked harmless.

## Editions

One. See above.

## Support

Latest version only. <https://github.com/goldnead/statamic-booking/issues>
