<script setup>
import { computed, ref } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import {
    Header, Button, Badge, Panel, Card, Text, DocsCallout, Field, Input, Select,
    Textarea, Switch, Checkbox, ConfirmationModal, Alert, Icon,
} from '@statamic/cms/ui';

/**
 * One room: the person, what is to do, what was shared, what happened.
 *
 * Every write goes through the Inertia router and comes back as a full page
 * with fresh props, so nothing here keeps a second copy of the truth. Every
 * label arrives finished in `t`.
 */
const props = defineProps({
    room: { type: Object, required: true },
    tasks: { type: Array, default: () => [] },
    files: { type: Array, default: () => [] },
    timeline: { type: Array, default: () => [] },
    timelineMode: { type: String, default: 'fallback' },
    timelineTotal: { type: Number, default: 0 },
    timelineSources: { type: Array, default: () => [] },
    stats: { type: Object, default: () => ({}) },
    owners: { type: Array, default: () => [] },
    canEdit: { type: Boolean, default: false },
    urls: { type: Object, required: true },
    t: { type: Object, required: true },
});

const busy = ref(false);

function send(method, url, data = {}, options = {}) {
    busy.value = true;

    return router[method](url, data, {
        preserveScroll: true,
        ...options,
        onFinish: () => { busy.value = false; options.onFinish?.(); },
    });
}

// ── Head: owner, close / reopen ─────────────────────────────────────────────

const ownerOptions = computed(() => [
    { value: null, label: props.t.owner_none },
    ...props.owners,
]);

const owner = ref(props.room.owner_user_id ?? null);

function changeOwner(value) {
    owner.value = value;
    send('patch', props.urls.update, { owner_user_id: value });
}

const confirmClose = ref(false);

function closeRoom() {
    confirmClose.value = false;
    send('post', props.urls.close);
}

function reopenRoom() {
    send('post', props.urls.reopen);
}

// ── Timeline ────────────────────────────────────────────────────────────────

// Short on purpose: the working panels sit above it, and history that runs
// to two hundred lines is one click away rather than in the way.
const PAGE = 10;
const visibleCount = ref(PAGE);
const visibleTimeline = computed(() => (props.timeline || []).slice(0, visibleCount.value));
const hiddenTimeline = computed(() => Math.max((props.timeline || []).length - visibleCount.value, 0));
const activeSources = computed(() => (props.timelineSources || []).filter((s) => s.available && !s.failed));
const failedSources = computed(() => (props.timelineSources || []).filter((s) => s.failed));
const sourceLabel = (key) => (props.timelineSources || []).find((s) => s.key === key)?.label ?? key;

/** Source → Badge colour, the same map LeadHub's contact screen uses. */
const sourceColor = (source) => ({
    leadhub: 'default',
    payments: 'green',
    entitlements: 'blue',
    booking: 'purple',
    consent: 'amber',
}[source] ?? 'default');

const statLines = computed(() => [
    [props.t.stat_first_contact, props.stats?.first_contact_human ?? formatDate(props.stats?.first_contact_at)],
    [props.t.stat_last_contact, props.stats?.last_contact_human ?? formatDate(props.stats?.last_contact_at)],
    [props.t.stat_purchases, props.stats?.purchase_count ?? null],
].filter(([, value]) => value !== null && value !== undefined && value !== ''));

function formatDate(iso) {
    if (!iso) return null;

    const date = new Date(iso);

    return Number.isNaN(date.getTime())
        ? iso
        : date.toLocaleDateString(document.documentElement.lang || undefined, { dateStyle: 'medium' });
}

// ── Tasks ───────────────────────────────────────────────────────────────────

const newTask = ref({ title: '', due_at: '' });
const taskErrors = ref({});
const openTasks = computed(() => props.tasks.filter((t) => !t.done).length);

function addTask() {
    if (!newTask.value.title.trim()) return;

    send('post', props.urls.tasks, {
        title: newTask.value.title,
        due_at: newTask.value.due_at || null,
    }, {
        onError: (e) => { taskErrors.value = e || {}; },
        onSuccess: () => { newTask.value = { title: '', due_at: '' }; taskErrors.value = {}; },
    });
}

function toggleTask(task, done) {
    send('patch', task.update_url, { done });
}

const deletingTask = ref(null);

function removeTask() {
    const task = deletingTask.value;
    deletingTask.value = null;

    if (task) send('delete', task.delete_url);
}

// ── Files ───────────────────────────────────────────────────────────────────

const fileInput = ref(null);
const newFile = ref({ file: null, title: '', visible_to_client: true });
const fileErrors = ref({});

function pickFile(event) {
    newFile.value.file = event.target.files?.[0] ?? null;
}

function uploadFile() {
    if (!newFile.value.file) return;

    send('post', props.urls.files, {
        file: newFile.value.file,
        title: newFile.value.title,
        visible_to_client: newFile.value.visible_to_client ? 1 : 0,
    }, {
        forceFormData: true,
        onError: (e) => { fileErrors.value = e || {}; },
        onSuccess: () => {
            newFile.value = { file: null, title: '', visible_to_client: true };
            fileErrors.value = {};
            if (fileInput.value) fileInput.value.value = '';
        },
    });
}

function toggleVisible(file, visible) {
    send('patch', file.update_url, { visible_to_client: visible ? 1 : 0 });
}

const deletingFile = ref(null);

function removeFile() {
    const file = deletingFile.value;
    deletingFile.value = null;

    if (file) send('delete', file.delete_url);
}

// ── Notes ───────────────────────────────────────────────────────────────────

const notes = ref({ notes: props.room.notes ?? '', client_notes: props.room.client_notes ?? '' });
const notesDirty = computed(() => notes.value.notes !== (props.room.notes ?? '') || notes.value.client_notes !== (props.room.client_notes ?? ''));

function saveNotes() {
    send('patch', props.urls.update, { ...notes.value });
}
</script>

<template>
    <div class="max-w-page mx-auto" data-max-width-wrapper>
        <Head :title="[room.display_name, t.title]" />

        <Header :title="room.display_name" icon="users">
            <Badge :color="room.is_open ? 'green' : 'default'" :text="room.status_label" class="me-2" />
            <Button :href="urls.index" :text="t.back_to_list" />
            <Button
                v-if="canEdit && room.is_open"
                :text="t.action_close"
                :disabled="busy"
                @click="confirmClose = true"
            />
            <Button
                v-else-if="canEdit"
                variant="primary"
                :text="t.action_reopen"
                :disabled="busy"
                @click="reopenRoom"
            />
        </Header>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                <!-- Tasks -->
                <Panel :heading="t.panel_tasks" :subheading="openTasks ? t.tasks_open_count.replace(':count', String(openTasks)) : undefined">
                    <Card>
                        <div v-if="tasks.length === 0" class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                            {{ t.tasks_empty }}
                        </div>
                        <ul v-else class="-my-2 divide-y divide-content-border">
                            <li v-for="task in tasks" :key="task.id" class="flex items-start justify-between gap-3 py-2">
                                <div class="flex min-w-0 items-start gap-3">
                                    <!-- `solo`: a checkbox with no label of its own; the title beside it is the label. -->
                                    <Checkbox
                                        :model-value="task.done"
                                        solo
                                        :disabled="!canEdit || busy"
                                        @update:model-value="toggleTask(task, $event)"
                                    />
                                    <div class="min-w-0">
                                        <div class="text-sm" :class="task.done ? 'line-through text-gray-500 dark:text-gray-400' : ''">{{ task.title }}</div>
                                        <div class="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-gray-500 dark:text-gray-400">
                                            <span v-if="task.done && task.done_human">{{ t.task_done }} · {{ task.done_human }}</span>
                                            <span v-else-if="task.due_at" :title="task.due_at">{{ t.task_due }} {{ task.due_human }}</span>
                                            <Badge v-if="task.overdue" size="sm" color="red" :text="t.task_overdue" />
                                        </div>
                                    </div>
                                </div>
                                <Button
                                    v-if="canEdit"
                                    icon="trash"
                                    variant="ghost"
                                    size="sm"
                                    :aria-label="t.delete"
                                    @click="deletingTask = task"
                                />
                            </li>
                        </ul>

                        <form v-if="canEdit" class="mt-4 border-t border-content-border pt-4" @submit.prevent="addTask">
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-start">
                                <Field class="flex-1" :error="taskErrors.title">
                                    <Input v-model="newTask.title" :placeholder="t.task_title_placeholder" />
                                </Field>
                                <Field class="sm:w-44" :error="taskErrors.due_at">
                                    <Input v-model="newTask.due_at" type="date" />
                                </Field>
                                <Button type="submit" variant="primary" :text="t.task_add" :disabled="busy || !newTask.title.trim()" />
                            </div>
                        </form>
                    </Card>
                </Panel>

                <!-- Files -->
                <Panel :heading="t.panel_files">
                    <Card>
                        <div v-if="files.length === 0" class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                            {{ t.files_empty }}
                        </div>
                        <ul v-else class="-my-2 divide-y divide-content-border">
                            <li v-for="file in files" :key="file.id" class="flex flex-col gap-2 py-2 sm:flex-row sm:items-center sm:justify-between sm:gap-3">
                                <div class="flex min-w-0 items-center gap-3">
                                    <Icon name="file" class="size-4 shrink-0 text-gray-500" />
                                    <div class="min-w-0">
                                        <a :href="file.download_url" class="block truncate text-sm font-medium hover:underline">{{ file.title }}</a>
                                        <div class="flex flex-wrap items-center gap-x-2 text-xs text-gray-500 dark:text-gray-400">
                                            <span v-if="file.filename !== file.title" class="truncate">{{ file.filename }}</span>
                                            <span v-if="file.size_human">{{ file.size_human }}</span>
                                            <span v-if="file.uploaded_human">{{ file.uploaded_human }}{{ file.uploaded_by ? ' ' + t.file_uploaded_by.replace(':name', file.uploaded_by) : '' }}</span>
                                            <Badge v-if="file.missing" size="sm" color="red" :text="t.file_missing" />
                                        </div>
                                    </div>
                                </div>
                                <div class="flex shrink-0 items-center gap-3">
                                    <label class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                                        <Switch
                                            :model-value="file.visible_to_client"
                                            size="sm"
                                            :disabled="!canEdit || busy"
                                            @update:model-value="toggleVisible(file, $event)"
                                        />
                                        <span>{{ file.visible_to_client ? t.file_visible : t.file_hidden }}</span>
                                    </label>
                                    <Button
                                        v-if="canEdit"
                                        icon="trash"
                                        variant="ghost"
                                        size="sm"
                                        :aria-label="t.delete"
                                        @click="deletingFile = file"
                                    />
                                </div>
                            </li>
                        </ul>

                        <form v-if="canEdit" class="mt-4 border-t border-content-border pt-4" @submit.prevent="uploadFile">
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-start">
                                <Field class="flex-1" :error="fileErrors.file">
                                    <input
                                        ref="fileInput"
                                        type="file"
                                        class="block w-full text-sm text-gray-900 file:me-3 file:rounded-md file:border file:border-content-border file:bg-content-bg file:px-3 file:py-1.5 file:text-sm file:text-gray-900 dark:text-gray-100 dark:file:text-gray-100"
                                        @change="pickFile"
                                    >
                                </Field>
                                <Field class="flex-1" :error="fileErrors.title">
                                    <Input v-model="newFile.title" :placeholder="t.file_title_placeholder" />
                                </Field>
                                <label class="flex h-10 items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                                    <Switch v-model="newFile.visible_to_client" size="sm" />
                                    <span>{{ t.file_visible }}</span>
                                </label>
                                <Button type="submit" variant="primary" :text="t.file_upload" :disabled="busy || !newFile.file" />
                            </div>
                        </form>
                    </Card>
                </Panel>

                <!-- Timeline: LeadHub's merged list where it is installed, the
                     room's own short list from payments and bookings otherwise.
                     Both arrive in one shape and render with one template. -->
                <Panel :heading="t.panel_timeline">
                    <Card>
                        <Alert
                            v-if="timelineMode === 'fallback'"
                            variant="default"
                            :text="t.timeline_mode_fallback"
                            class="mb-3"
                        />

                        <div
                            v-if="activeSources.length"
                            class="mb-3 flex flex-wrap items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400"
                        >
                            <span>{{ t.timeline_sources }}:</span>
                            <Badge
                                v-for="source in activeSources"
                                :key="source.key"
                                size="sm"
                                :color="sourceColor(source.key)"
                                :text="source.label"
                            />
                        </div>

                        <div v-if="failedSources.length" class="mb-3 space-y-0.5">
                            <Text
                                v-for="source in failedSources"
                                :key="source.key"
                                as="div"
                                size="xs"
                                variant="warning"
                            >{{ t.timeline_failed.replace(':source', source.label) }}</Text>
                        </div>

                        <dl v-if="statLines.length" class="mb-4 flex flex-wrap gap-x-8 gap-y-2 text-xs">
                            <div v-for="[label, value] in statLines" :key="label">
                                <dt class="text-gray-500 dark:text-gray-400">{{ label }}</dt>
                                <dd class="font-medium tabular-nums">{{ value }}</dd>
                            </div>
                        </dl>

                        <div v-if="(timeline || []).length === 0" class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                            {{ t.timeline_empty }}
                        </div>
                        <ul v-else class="-my-3 divide-y divide-content-border">
                            <li v-for="entry in visibleTimeline" :key="entry.id" class="py-3">
                                <div class="flex items-start justify-between gap-3 text-sm">
                                    <div class="min-w-0">
                                        <component
                                            :is="entry.url ? 'a' : 'span'"
                                            :href="entry.url"
                                            class="font-medium"
                                            :class="entry.url ? 'hover:underline' : ''"
                                        >{{ entry.summary }}</component>
                                        <span v-if="entry.amount" class="ms-2 tabular-nums text-gray-900 dark:text-gray-100">{{ entry.amount.formatted }}</span>
                                    </div>
                                    <div class="flex shrink-0 items-center gap-2">
                                        <Badge v-if="entry.badge" size="sm" :color="entry.badge.color" :text="entry.badge.text" />
                                        <Text size="xs" variant="subtle" :title="entry.at">{{ entry.at_human }}</Text>
                                    </div>
                                </div>
                                <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-gray-500 dark:text-gray-400">
                                    <Badge size="sm" :color="sourceColor(entry.source)" :text="sourceLabel(entry.source)" />
                                    <span v-if="entry.actor">{{ entry.actor }}</span>
                                    <code>{{ entry.kind }}</code>
                                </div>
                                <dl
                                    v-if="entry.detail && entry.detail.length"
                                    class="mt-2 grid gap-x-3 gap-y-0.5 text-xs sm:grid-cols-[auto_1fr]"
                                >
                                    <template v-for="(line, i) in entry.detail" :key="i">
                                        <dt><Text size="xs" variant="subtle">{{ line.label }}</Text></dt>
                                        <dd><Text size="xs">{{ line.value }}</Text></dd>
                                    </template>
                                </dl>
                            </li>
                        </ul>
                        <div v-if="hiddenTimeline > 0" class="mt-4 pt-4 border-t border-content-border text-center">
                            <Button
                                :text="t.timeline_more + ' (' + hiddenTimeline + ')'"
                                size="sm"
                                variant="ghost"
                                @click="visibleCount += PAGE"
                            />
                        </div>
                    </Card>
                </Panel>
            </div>

            <!-- Sidebar -->
            <div class="space-y-6">
                <Panel :heading="room.display_name">
                    <Card class="p-0!">
                        <dl class="divide-y divide-content-border text-sm">
                            <div class="px-4 py-2.5">
                                <dt class="text-xs text-gray-500 dark:text-gray-400">{{ t.column_email }}</dt>
                                <dd class="break-all">{{ room.email }}</dd>
                            </div>
                            <div class="px-4 py-2.5">
                                <dt class="text-xs text-gray-500 dark:text-gray-400">{{ t.field_owner }}</dt>
                                <dd class="mt-1">
                                    <Select
                                        v-if="canEdit"
                                        :model-value="owner"
                                        :options="ownerOptions"
                                        size="sm"
                                        :disabled="busy"
                                        @update:model-value="changeOwner"
                                    />
                                    <span v-else>{{ room.owner_label || t.owner_none }}</span>
                                </dd>
                            </div>
                            <div class="px-4 py-2.5">
                                <dt class="text-xs text-gray-500 dark:text-gray-400">{{ room.is_open ? t.opened_since : t.closed_since }}</dt>
                                <dd :title="room.is_open ? room.opened_at : room.closed_at">{{ room.is_open ? room.opened_human : room.closed_human }}</dd>
                            </div>
                            <div v-if="room.brand_label" class="px-4 py-2.5">
                                <dt class="text-xs text-gray-500 dark:text-gray-400">{{ t.brand }}</dt>
                                <dd>{{ room.brand_label }}</dd>
                            </div>
                            <div v-if="room.opened_by === 'payments' || room.contact_url" class="px-4 py-2.5 space-y-1">
                                <Badge v-if="room.opened_by === 'payments'" color="green" :text="t.opened_by_payments" />
                                <div v-if="room.contact_url">
                                    <a :href="room.contact_url" class="text-sm hover:underline">{{ t.contact_link }} →</a>
                                </div>
                            </div>
                        </dl>
                    </Card>
                </Panel>

                <Panel :heading="t.panel_notes">
                    <Card class="space-y-4">
                        <Field :label="t.notes_internal" :instructions="t.notes_internal_help">
                            <Textarea v-model="notes.notes" :rows="5" :read-only="!canEdit" />
                        </Field>
                        <Field :label="t.notes_client" :instructions="t.notes_client_help">
                            <Textarea v-model="notes.client_notes" :rows="4" :read-only="!canEdit" />
                        </Field>
                        <div v-if="canEdit" class="flex justify-end">
                            <Button variant="primary" size="sm" :text="t.notes_save" :disabled="busy || !notesDirty" @click="saveNotes" />
                        </div>
                    </Card>
                </Panel>
            </div>
        </div>

        <!-- `:open`, not `v-if`: the modal owns its visibility and focus trap. -->
        <ConfirmationModal
            :open="confirmClose"
            :title="t.close_title"
            :body-text="t.close_body"
            :button-text="t.action_close"
            @update:open="confirmClose = $event"
            @confirm="closeRoom"
        />

        <ConfirmationModal
            :open="deletingTask !== null"
            :title="t.task_delete_title"
            :body-text="t.task_delete_body"
            :button-text="t.delete"
            danger
            @update:open="deletingTask = $event ? deletingTask : null"
            @confirm="removeTask"
        />

        <ConfirmationModal
            :open="deletingFile !== null"
            :title="t.file_delete_title"
            :body-text="t.file_delete_body"
            :button-text="t.delete"
            danger
            @update:open="deletingFile = $event ? deletingFile : null"
            @confirm="removeFile"
        />

        <DocsCallout
            :topic="t.title"
            url="https://docs.adriangoldner.dev/clientrooms/"
        />
    </div>
</template>
