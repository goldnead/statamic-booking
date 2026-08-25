<script setup>
import { Head } from '@statamic/cms/inertia';
import { Header, Badge, Listing, EmptyStateMenu, EmptyStateItem, DocsCallout, CommandPaletteItem } from '@statamic/cms/ui';

/**
 * The bookings listing.
 *
 * Core's <Listing> rather than a table of its own: search, sorting, column
 * choice, saved preferences and paging all come with it, and every one of them
 * is missing the moment somebody writes their own <table>.
 *
 * No rows are passed in. The Listing fetches them from `listingUrl`, which is
 * what core's own listings do — handing them over as well would query the same
 * list twice on every page load.
 */
defineProps({
    listingUrl: { type: String, required: true },
    filters: { type: Array, default: () => [] },
    sortColumn: { type: String, default: 'scheduled_at' },
    sortDirection: { type: String, default: 'asc' },
    // Whether any booking exists at all — deliberately not "did this search
    // find something". A fruitless search must not claim the endpoint is
    // broken.
    hasAny: { type: Boolean, default: false },
});

const statusColor = (status) => ({
    booked: 'green',
    rescheduled: 'blue',
    requested: 'amber',
    cancelled: 'red',
    rejected: 'red',
}[status] ?? 'default');
</script>

<template>
    <div class="max-w-page mx-auto" data-max-width-wrapper>
        <Head :title="[__('statamic-booking::messages.utility_title')]" />

        <Header :title="__('statamic-booking::messages.utility_title')" icon="calendar" />

        <!-- Reachable from ⌘K like every core screen. There is no primary
             action to register here — the screen only reads — so what the
             palette gets is the screen itself. -->
        <CommandPaletteItem
            :text="[__('Utilities'), __('statamic-booking::messages.utility_title')]"
            :url="listingUrl"
            icon="calendar"
            prioritize
        />

        <EmptyStateMenu v-if="!hasAny" :heading="__('statamic-booking::messages.empty_heading')">
            <EmptyStateItem
                :heading="__('statamic-booking::messages.empty_title')"
                :description="__('statamic-booking::messages.empty_description')"
                icon="calendar"
            />
        </EmptyStateMenu>

        <Listing
            v-else
            :url="listingUrl"
            :filters="filters"
            :sort-column="sortColumn"
            :sort-direction="sortDirection"
            preferences-prefix="statamic-booking.bookings"
            push-query
        >
            <template #cell-scheduled_at="{ row }">
                <span v-if="row.scheduled_at" class="tabular-nums">
                    <date-time :of="row.scheduled_at" />
                </span>
                <span v-else class="text-gray-500 dark:text-gray-400">&mdash;</span>
            </template>

            <template #cell-name="{ row }">
                <span class="font-medium">{{ row.name || '—' }}</span>
            </template>

            <template #cell-email="{ row }">
                <span class="text-gray-600 dark:text-gray-400">{{ row.email || '—' }}</span>
            </template>

            <template #cell-status="{ row }">
                <Badge :color="statusColor(row.status)" :text="row.status_label" />
            </template>

            <template #cell-duration_minutes="{ row }">
                <span class="tabular-nums text-gray-600 dark:text-gray-400">
                    {{ row.duration_minutes ? row.duration_minutes + ' min' : '—' }}
                </span>
            </template>

            <template #cell-endpoint="{ row }">
                <span class="font-mono text-xs">{{ row.endpoint }}</span>
            </template>

            <template #cell-created_at="{ row }">
                <date-time :of="row.created_at" />
            </template>
        </Listing>

        <DocsCallout
            :topic="__('statamic-booking::messages.utility_title')"
            url="https://github.com/goldnead/statamic-booking#readme"
        />
    </div>
</template>
