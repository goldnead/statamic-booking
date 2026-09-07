<?php

/*
 * Labels for the settings screen.
 *
 * Field keys are the config path with the dots flattened
 * (`signature.tolerance_seconds` → `signature_tolerance_seconds`).
 */

return [

    'permission_group' => 'Bookings',
    'permission_manage' => 'Manage booking settings',

    'groups' => [

        'endpoint' => [
            'title' => 'Endpoint',
            'description' => 'How the endpoint accepts incoming bookings. The endpoints themselves stay in config/statamic-booking.php, because each one carries a secret and a secret in a database row ends up in every backup. So do the signature header, the algorithm and the timestamp header: that is the protocol contract with Cal.com.',
        ],

        'retention' => [
            'title' => 'Retention',
            'description' => 'How long a booking is kept. A booking carries a name and an address, so the period is a data-protection decision.',
        ],

    ],

    'fields' => [

        'rate_limit' => [
            'label' => 'Requests per minute per IP',
            'description' => 'Further requests from the same address are refused. Too low loses a burst of real bookings; too high lets a script write unchecked. Applies from the next request.',
        ],

        'signature_tolerance_seconds' => [
            'label' => 'Permitted age of a signature',
            'description' => 'An older delivery is refused. A signature says nothing about when it was made: without this bound a captured delivery stays valid forever. Empty switches the check off, which is only defensible for a provider that sends no timestamp.',
        ],

        'keep_days' => [
            'label' => 'Keep bookings for (days)',
            'description' => 'Bookings whose appointment is older are deleted on the next run of php please booking:prune. Empty means keep everything, which is the opposite of data minimisation.',
        ],

    ],

];
