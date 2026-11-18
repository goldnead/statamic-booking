<?php

namespace Goldnead\StatamicBooking\Http\Controllers;

use Goldnead\StatamicBooking\Support\BookingRecorder;
use Goldnead\StatamicBooking\Support\SignatureVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class WebhookController
{
    /**
     * One route, many endpoints.
     *
     * The handle in the path selects the configured funnel, and with it the
     * secret. An unknown handle answers 404 the same way a wrong signature
     * answers 401: a handle is a name, not a secret, and pretending otherwise
     * buys nothing while making a typo undebuggable.
     */
    public function __invoke(
        Request $request,
        string $endpoint,
        SignatureVerifier $verifier,
        BookingRecorder $recorder,
    ): JsonResponse {
        $config = config('statamic-booking.endpoints.'.$endpoint);

        if (! is_array($config)) {
            return response()->json(['message' => __('statamic-booking::messages.unknown_endpoint')], 404);
        }

        $verdict = $verifier->verify($request, (string) ($config['secret'] ?? ''));

        if ($verdict !== true) {
            // The reason goes to the log, not to the caller. Someone probing
            // this endpoint learns only that it refused; whoever runs the site
            // learns which of the four reasons it was.
            Log::warning('[statamic-booking] refused a delivery on ['.$endpoint.']: '.$verdict);

            return response()->json(['message' => __('statamic-booking::messages.unauthorized')], 401);
        }

        $payload = (array) $request->json()->all();
        $uid = $payload['payload']['uid'] ?? null;

        // A signed body that carries no uid (or is no JSON at all) cannot be
        // recorded and will not get better on a retry. 422 says so, and the
        // log tells the site owner why nothing appeared.
        if (! is_array($payload['payload'] ?? null) || ! is_string($uid) || $uid === '') {
            Log::warning('[statamic-booking] dropped a delivery on ['.$endpoint.']: no payload.uid in the body (unreadable JSON or a missing uid)');

            return response()->json(['message' => __('statamic-booking::messages.unprocessable')], 422);
        }

        $seen = $this->dedupeKey($endpoint, $payload);

        // Without a signed timestamp a captured delivery never expires. An
        // exact replay (same uid, trigger and updatedAt) is dropped here.
        if ($seen !== null && Cache::has($seen)) {
            Log::info('[statamic-booking] dropped a duplicate delivery on ['.$endpoint.']');

            return response()->json(['recorded' => false, 'duplicate' => true]);
        }

        $booking = $recorder->record($endpoint, $payload);

        // Marked only after record() returned: a delivery that threw gets its
        // provider retry as a first delivery.
        if ($seen !== null) {
            Cache::put($seen, true, now()->addDay());
        }

        // 200 either way once the signature holds. A trigger this addon does
        // not handle is not an error on the provider's side, and answering
        // anything else makes it retry forever.
        return response()->json(['recorded' => $booking !== null]);
    }

    /**
     * Only when the signature carries no timestamp and the payload has an
     * updatedAt to tell two real deliveries apart. Otherwise null.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function dedupeKey(string $endpoint, array $payload): ?string
    {
        $header = config('statamic-booking.signature.timestamp_header');

        if (is_string($header) && $header !== '') {
            return null;
        }

        $uid = $payload['payload']['uid'] ?? null;
        $updated = $payload['payload']['updatedAt'] ?? null;
        $trigger = $payload['triggerEvent'] ?? null;

        if (! is_string($uid) || $uid === '' || ! is_string($updated) || $updated === '' || ! is_string($trigger)) {
            return null;
        }

        return 'statamic-booking:seen:'.hash('sha256', implode('|', [$endpoint, $uid, $trigger, $updated]));
    }
}
