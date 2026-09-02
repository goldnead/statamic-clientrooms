<script setup>
import { computed, ref } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import {
    Header, Badge, Listing, EmptyStateMenu, EmptyStateItem, DocsCallout,
    Button, CommandPaletteItem, Stack, Heading, Field, Input, Select, DropdownItem, Icon,
} from '@statamic/cms/ui';

/**
 * Client rooms: one row per person the coach works with.
 *
 * Built as the sibling of the products screen next door, on purpose. Every
 * label arrives finished in `t`; nothing here composes a sentence.
 */
const props = defineProps({
    listingUrl: { type: String, required: true },
    storeUrl: { type: String, required: true },
    filters: { type: Array, default: () => [] },
    sortColumn: { type: String, default: 'last_activity_at' },
    sortDirection: { type: String, default: 'desc' },
    hasAny: { type: Boolean, default: false },
    canEdit: { type: Boolean, default: false },
    owners: { type: Array, default: () => [] },
    multiBrand: { type: Boolean, default: false },
    t: { type: Object, required: true },
});

const listing = ref(null);
const open = ref(false);
const saving = ref(false);
const errors = ref({});
const form = ref({ email: '', name: '', owner_user_id: null });

const ownerOptions = computed(() => [
    { value: null, label: props.t.owner_none },
    ...props.owners,
]);

function create() {
    form.value = { email: '', name: '', owner_user_id: null };
    errors.value = {};
    open.value = true;
}

function save() {
    saving.value = true;

    // `router`, not axios: the Inertia router drives the progress bar, the
    // flash toast and the back button. The server redirects to the new room.
    router.post(props.storeUrl, form.value, {
        onError: (e) => { errors.value = e || {}; },
        onSuccess: () => { open.value = false; errors.value = {}; },
        onFinish: () => { saving.value = false; },
    });
}
</script>

<template>
    <div class="max-w-page mx-auto" data-max-width-wrapper>
        <Head :title="[t.title]" />

        <template v-if="!hasAny">
            <!-- Empty-state header is a centered h1, not <Header>, like core's Forms index. -->
            <header class="py-8 pt-16 text-center">
                <h1 class="text-[25px] font-medium antialiased flex justify-center items-center gap-2 sm:gap-3">
                    <Icon name="users" class="size-5 text-gray-500" />{{ t.title }}
                </h1>
            </header>
            <EmptyStateMenu :heading="t.empty_heading">
                <EmptyStateItem
                    v-if="canEdit"
                    :heading="t.empty_title"
                    :description="t.empty_description"
                    icon="users"
                    @click="create"
                />
            </EmptyStateMenu>
        </template>

        <template v-else>
            <Header :title="t.title" icon="users">
                <CommandPaletteItem
                    v-if="canEdit"
                    category="Actions"
                    :text="t.new"
                    icon="users"
                    :url="storeUrl"
                    v-slot="{ text }"
                >
                    <Button variant="primary" :text="text" @click="create" />
                </CommandPaletteItem>
            </Header>

            <!-- The core listing, fed the way core feeds its own. No `actionUrl`:
                 there are no bulk actions, and passing one would turn on
                 checkboxes that do nothing. -->
            <Listing
                ref="listing"
                :url="listingUrl"
                :filters="filters"
                :sort-column="sortColumn"
                :sort-direction="sortDirection"
                preferences-prefix="statamic-clientrooms.rooms"
                push-query
            >
                <template #cell-name="{ row }">
                    <a :href="row.show_url" class="font-medium hover:text-primary">{{ row.name }}</a>
                </template>

                <template #cell-email="{ row }">
                    <span class="text-xs">{{ row.email }}</span>
                </template>

                <template #cell-status="{ row }">
                    <Badge :color="row.is_open ? 'green' : 'default'" :text="row.status_label" />
                </template>

                <template #cell-open_tasks="{ row }">
                    <span v-if="row.open_tasks" class="tabular-nums">{{ row.open_tasks }}</span>
                </template>

                <template #cell-last_activity_at="{ row }">
                    <span class="text-xs" :title="row.last_activity_at">{{ row.last_activity_human }}</span>
                </template>

                <template #cell-opened_at="{ row }">
                    <span class="text-xs" :title="row.opened_at">{{ row.opened_human }}</span>
                </template>

                <template #cell-brand="{ row }">
                    <span v-if="row.brand" class="text-xs">{{ row.brand }}</span>
                </template>

                <template #prepended-row-actions="{ row }">
                    <DropdownItem icon="eye" :text="t.view_action" :href="row.show_url" />
                </template>
            </Listing>
        </template>

        <Stack v-model:open="open" size="narrow">
            <div class="flex h-full flex-col bg-content-bg">
                <div class="flex items-center justify-between gap-3 border-b border-content-border px-6 py-4">
                    <Heading :text="t.open_room" size="lg" />
                </div>

                <div class="flex-1 space-y-5 overflow-y-auto px-6 py-5">
                    <Field :label="t.field_email" :error="errors.email" required>
                        <Input v-model="form.email" type="email" autofocus />
                    </Field>

                    <Field :label="t.field_name" :error="errors.name">
                        <Input v-model="form.name" />
                    </Field>

                    <Field :label="t.field_owner" :instructions="t.field_owner_help" :error="errors.owner_user_id">
                        <Select v-model="form.owner_user_id" :options="ownerOptions" />
                    </Field>
                </div>

                <div class="border-t border-content-border px-6 py-4">
                    <div class="flex justify-end gap-2">
                        <Button :text="t.cancel" @click="open = false" />
                        <Button variant="primary" :text="t.new" :disabled="saving" @click="save" />
                    </div>
                </div>
            </div>
        </Stack>

        <DocsCallout
            :topic="t.title"
            url="https://docs.adriangoldner.dev/clientrooms/"
        />
    </div>
</template>
