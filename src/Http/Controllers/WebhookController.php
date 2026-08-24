<?php

namespace Goldnead\StatamicBooking\Http\Controllers;

use Goldnead\StatamicBooking\Support\BookingRecorder;
use Goldnead\StatamicBooking\Support\SignatureVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        $booking = $recorder->record($endpoint, (array) $request->json()->all());

        // 200 either way once the signature holds. A trigger this addon does
        // not handle is not an error on the provider's side, and answering
        // anything else makes it retry forever.
        return response()->json(['recorded' => $booking !== null]);
    }
}
