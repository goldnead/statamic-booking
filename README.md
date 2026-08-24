<!-- statamic:hide -->
# Statamic Booking
> Records Cal.com bookings in Statamic — signed, idempotent, and out of your way.
<!-- /statamic:hide -->

This addon does **not** build a calendar. Availability, time zones, reschedules and reminders are a
solved problem, and solving them again badly is the usual way a booking feature goes wrong. Cal.com
does that part; this records what it decided, and gives your site a place to react.

## Requirements

Statamic 6 · PHP 8.2+ · a database · a Cal.com account (the free plan is enough).

## Installation

```bash
composer require goldnead/statamic-booking
php artisan migrate
php please vendor:publish --tag=statamic-booking-config
```

Then define at least one endpoint and point Cal.com at it.

## Usage

Define an endpoint, point Cal.com at it, and listen for what arrives.

### Endpoints

One entry per funnel. A free consultation and a paid lesson are different things — different event
types, different secrets, usually different consequences.

```php
// config/statamic-booking.php
'endpoints' => [
    'beratung' => [
        'secret' => env('BOOKING_SECRET_BERATUNG'),
        'label' => 'Kostenloses Erstgespräch',
    ],
],
```

In Cal.com: **Settings → Webhooks → New**, subscriber URL
`https://your-site.test/!/statamic-booking/beratung`, secret the same value, triggers `BOOKING_CREATED`, `BOOKING_RESCHEDULED`, `BOOKING_CANCELLED` — and, if your event type
needs confirming, `BOOKING_REQUESTED` and `BOOKING_REJECTED` as well.

A requested booking is recorded but is **not** upcoming: it has been asked for, not agreed, and
showing it as an appointment is how a calendar tells its owner a lie. A rejected one is closed the
same way a cancellation is.

**An endpoint without a secret refuses every request.** That is deliberate: an unverified booking
webhook is an open write endpoint, and "the site has not been configured yet" must not mean "anyone
may post here".

### Reacting

Three events, each dispatched **once per real change** — never on a redelivery, so a listener may
assume it is being told something new:

```php
use Goldnead\StatamicBooking\Events\BookingMade;

Event::listen(BookingMade::class, function (BookingMade $event) {
    $event->booking->email;        // who booked
    $event->booking->scheduled_at; // when
    $event->booking->endpoint;     // which funnel
});
```

`BookingRescheduled` and `BookingCancelled` work the same way. A cancellation **keeps** the row and
stamps `cancelled_at`: "there was an appointment and it was cancelled" is a different fact from
"there never was one", and only one of them can be reconstructed later.

### Tags

| Tag | Parameters | What it does |
|---|---|---|
| `{{ bookings }}` | `endpoint`, `limit` | Upcoming bookings, soonest first; cancelled, rejected and merely requested ones left out |
| `{{ bookings:count }}` | `endpoint` | How many there are |

```antlers
{{ bookings endpoint="beratung" limit="3" }}
    {{ if no_results }}
        <li>Zurzeit sind keine Termine eingetragen.</li>
    {{ else }}
        <li>{{ scheduled_at format="d.m.Y H:i" }} — {{ duration_minutes }} min</li>
    {{ /if }}
{{ /bookings }}
```

**The tags carry no names, addresses — or titles.** Cal.com's default booking title is
"30 Min Meeting between {organiser} and {attendee}", which is a field that looks harmless and
carries the booker. One careless template is all it takes to publish the people who booked, so the
tag simply has nothing to publish. Whoever needs the rest has the model.

## Configuration

Every key lives in `config/statamic-booking.php`.

| Key | Default | What happens when it is wrong |
|---|---|---|
| `endpoints` | none | An endpoint without a `secret` refuses every request. Renaming a handle after the first booking orphans every row that carries it. |
| `signature.header` | `X-Cal-Signature-256` | A wrong header name means every delivery is refused as unsigned. |
| `signature.timestamp_header` | `null` | Without one, a captured delivery can be replayed forever. Naming a header your provider does not send refuses every delivery. |
| `signature.tolerance_seconds` | `300` | Too tight and clock drift refuses real deliveries; too loose and a replay window opens. |
| `rate_limit` | `60` | Per minute, per IP. |
| `keep_days` | `730` | Null keeps every name and address forever, which is the opposite of data minimisation. |

## What it stores

Endpoint, the provider's id, status, appointment time and time zone, duration, name, address,
meeting URL, and the event title. `php please booking:prune` deletes bookings whose appointment is
older than `keep_days` (default 730). Null keeps everything, which is a decision, not a default.

## Security

- **Every delivery is verified** with HMAC-SHA256 over the raw body, compared in constant time.
  A valid signature over a *different* body is refused — that is the forgery a naive check lets
  through.
- **Each endpoint has its own secret.** One leaked secret does not open the others.
- **Replay:** if your provider sends a timestamp, name the header in
  `signature.timestamp_header`. It is then **signed together with the body** (`timestamp.body`,
  Stripe's scheme) and deliveries outside `tolerance_seconds` are refused. Checking an *unsigned*
  timestamp header would be theatre: whoever replays a captured delivery simply writes the current
  time into it.
  **Cal.com sends no timestamp today.** Without one this endpoint is signature-authentic but not
  replay-proof: someone who captured a valid delivery can send it again. Said plainly rather than
  implied.
- The route drops CSRF on purpose. The caller is a server and authenticates with a signature,
  which is a stronger proof than a session token and the only one a provider can give.

## Multi-site

Bookings are not site-scoped. A booking is an appointment with a person, not a piece of content,
and it does not become a different appointment when read from another site.

## Support

Only the latest version is supported. <https://github.com/goldnead/statamic-booking/issues>

## Changelog · License

[CHANGELOG.md](CHANGELOG.md) · [LICENSE.md](LICENSE.md)
