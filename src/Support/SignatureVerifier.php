<?php

namespace Goldnead\StatamicBooking\Support;

use Illuminate\Http\Request;

/**
 * Is this delivery really from the provider, and is it recent?
 *
 * Two separate questions, and both have to be answered before a single row is
 * written. A signature proves *who* wrote the body; only a timestamp proves
 * *when*. Without the second, anyone who has ever seen one valid delivery — a
 * proxy log, a mirrored request, an exported HAR — can replay it next year.
 */
class SignatureVerifier
{
    /**
     * @return true|string true, or the reason it was refused
     */
    public function verify(Request $request, string $secret): true|string
    {
        if (trim($secret) === '') {
            // Fail closed. An endpoint with no secret is an open write endpoint,
            // and "the site forgot to configure it" must not be the same thing
            // as "anyone may post here".
            return 'no secret configured for this endpoint';
        }

        $header = (string) config('statamic-booking.signature.header', 'X-Cal-Signature-256');
        $given = (string) $request->header($header, '');

        if ($given === '') {
            return 'missing signature header';
        }

        $algorithm = (string) config('statamic-booking.signature.algorithm', 'sha256');

        // A typo in the config would otherwise reach hash_hmac() as an unknown
        // algorithm and raise a ValueError — a 500 where a clean refusal
        // belongs.
        if (! in_array($algorithm, hash_hmac_algos(), true)) {
            return 'unknown signature algorithm ['.$algorithm.']';
        }

        $timestamp = $this->timestamp($request);

        if (is_string($timestamp)) {
            return $timestamp;
        }

        /*
         * The timestamp is signed WITH the body, or not used at all.
         *
         * The first version checked a timestamp from a free header against a
         * tolerance. That stops an honest sender from being late; it stops no
         * attacker at all, because whoever replays a captured delivery simply
         * writes the current time into that header. Signing `t.body` — Stripe's
         * scheme — is what makes the freshness claim as hard to forge as the
         * body claim.
         */
        $signed = $timestamp === null ? $request->getContent() : $timestamp.'.'.$request->getContent();

        $expected = hash_hmac($algorithm, $signed, $secret);

        // Constant time. A plain === leaks the position of the first wrong byte
        // through timing, which is enough to forge a digest given patience.
        if (! hash_equals($expected, $given)) {
            return 'signature does not match';
        }

        return true;
    }

    /**
     * The timestamp to sign with, null when the provider sends none, or a
     * reason when it sent an unusable one.
     *
     * Returning null is not a failure: Cal.com sends no timestamp today, and
     * this endpoint then has no replay protection. That is stated in the README
     * rather than implied by a check that could not deliver it.
     */
    protected function timestamp(Request $request): null|int|string
    {
        $header = config('statamic-booking.signature.timestamp_header');
        $tolerance = config('statamic-booking.signature.tolerance_seconds');

        if (! is_string($header) || $header === '') {
            return null;
        }

        $sent = $request->header($header);

        if (! is_numeric($sent)) {
            return 'missing or unreadable timestamp';
        }

        if (is_numeric($tolerance) && abs(now()->getTimestamp() - (int) $sent) > (int) $tolerance) {
            return 'timestamp outside tolerance';
        }

        return (int) $sent;
    }
}
