/**
 * Control Panel entry.
 *
 * Statamic 6 mounts an Inertia + Vue SPA; an addon page is registered by the
 * exact name its controller passes to `Inertia::render()`.
 */

import BookingsIndex from './pages/Bookings/Index.vue';
import SetupRequired from './pages/SetupRequired.vue';

Statamic.booting(() => {
    Statamic.$inertia.register('statamic-booking::Bookings/Index', BookingsIndex);
    Statamic.$inertia.register('statamic-booking::SetupRequired', SetupRequired);
});
