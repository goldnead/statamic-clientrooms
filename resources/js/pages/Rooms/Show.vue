<script setup>
import { computed, ref } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import {
    Header, Button, Badge, Panel, Card, Text, DocsCallout, Field, Input, Select,
    Textarea, Switch, Checkbox, ConfirmationModal, Alert, Icon, DatePicker,
    Table, TableColumn, TableColumns, TableRow, TableRows, TableCell,
    Stack, Heading,
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
    sessions: { type: Array, default: () => [] },
    sessionsSubheading: { type: String, default: null },
    timeline: { type: Array, default: () => [] },
    timelineMode: { type: String, default: 'fallback' },
    timelineTotal: { type: Number, default: 0 },
    timelineSources: { type: Array, default: () => [] },
    stats: { type: Object, default: () => ({}) },
    taskOptions: { type: Object, default: () => ({ types: [], statuses: [], priorities: [], published: [] }) },
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

const ownerErrors = ref({});

function changeOwner(value) {
    owner.value = value;
    send('patch', props.urls.update, { owner_user_id: value }, {
        // Without this the refusal was silent: the controller answers with a
        // field error, the page reloaded, and the picker showed the owner the
        // save had just declined.
        onError: (e) => { ownerErrors.value = e || {}; owner.value = props.room.owner_user_id ?? null; },
        onSuccess: () => { ownerErrors.value = {}; },
    });
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

const emptyTask = () => ({
    title: '',
    due_at: null,
    description: '',
    type: null,
    priority: null,
    estimated_minutes: null,
    // A task typed here is meant to arrive. Switching this off is how a coach
    // parks one on his desk, and the switch is the only place `draft` is
    // reachable from — hence the plain word on it, not the word `draft`.
    visible: true,
});

const newTask = ref(emptyTask());
const showTaskFields = ref(false);
const taskErrors = ref({});
const openTasks = computed(() => props.tasks.filter((t) => !t.done).length);
const draftTasks = computed(() => props.tasks.filter((t) => t.draft).length);

const typeOptions = computed(() => [
    { value: null, label: props.t.task_type_none },
    ...(props.taskOptions.types || []),
]);

const priorityOptions = computed(() => [
    { value: null, label: props.t.task_priority_none },
    ...(props.taskOptions.priorities || []),
]);

const statusOptions = computed(() => props.taskOptions.statuses || []);

/** Open first, drafts second, and nothing at all when there is neither. */
const taskSubheading = computed(() => {
    const parts = [];

    if (openTasks.value) parts.push(props.t.tasks_open_count.replace(':count', String(openTasks.value)));
    if (draftTasks.value) parts.push(props.t.tasks_draft_count.replace(':count', String(draftTasks.value)));

    return parts.length ? parts.join(' · ') : undefined;
});

/**
 * The small grey line under a task, assembled rather than concatenated: a
 * task with a duration and no date must not start with a stray separator.
 */
function taskMeta(task) {
    const parts = [];

    if (task.done && task.done_human) parts.push(`${props.t.task_done} · ${task.done_human}`);
    else if (task.due_at) parts.push(`${props.t.task_due} ${task.due_human}`);

    if (task.estimated_minutes) parts.push(`${task.estimated_minutes} ${props.t.task_minutes_unit}`);

    return parts.join(' · ');
}

/** A number field hands back a string, and '' has to stay null, not become 0. */
function toMinutes(value) {
    if (value === null || value === undefined || value === '') return null;

    const n = Number(value);

    return Number.isFinite(n) && n >= 0 ? Math.round(n) : null;
}

/**
 * The core DatePicker is reka-ui: its model is an @internationalized/date
 * value with `year`, `month`, `day`, never a string. Sent raw it arrives as an
 * object and fails the `date` rule, so it is flattened to `YYYY-MM-DD` here.
 */
function toDateString(value) {
    if (!value) return null;
    if (typeof value === 'string') return value;

    if (typeof value === 'object' && value.year) {
        const pad = (n) => String(n).padStart(2, '0');

        return `${value.year}-${pad(value.month)}-${pad(value.day)}`;
    }

    return null;
}

function addTask() {
    if (!newTask.value.title.trim()) return;

    const task = newTask.value;

    send('post', props.urls.tasks, {
        title: task.title,
        due_at: toDateString(task.due_at),
        description: task.description || null,
        type: task.type || null,
        priority: task.priority || null,
        estimated_minutes: toMinutes(task.estimated_minutes),
        published_status: task.visible ? 'published' : 'draft',
    }, {
        onError: (e) => { taskErrors.value = e || {}; },
        onSuccess: () => {
            newTask.value = emptyTask();
            showTaskFields.value = false;
            taskErrors.value = {};
        },
    });
}

function toggleTask(task, done) {
    send('patch', task.update_url, { done });
}

/** The one switch that decides whether the client sees this task at all. */
function toggleTaskVisible(task, visible) {
    send('patch', task.update_url, { published_status: visible ? 'published' : 'draft' });
}

// ── Editing one task ────────────────────────────────────────────────────────

const editingId = ref(null);
const editTask = ref(null);
const editErrors = ref({});

function startEdit(task) {
    editingId.value = task.id;
    editErrors.value = {};
    editTask.value = {
        title: task.title,
        due_at: task.due_at,
        description: task.description ?? '',
        type: task.type ?? null,
        status: task.status ?? 'assigned',
        priority: task.priority ?? null,
        estimated_minutes: task.estimated_minutes ?? null,
        published_status: task.published_status,
        update_url: task.update_url,
    };
}

function cancelEdit() {
    editingId.value = null;
    editTask.value = null;
    editErrors.value = {};
}

function saveEdit() {
    const edit = editTask.value;

    if (!edit || !edit.title.trim()) return;

    send('patch', edit.update_url, {
        title: edit.title,
        due_at: toDateString(edit.due_at),
        description: edit.description || null,
        type: edit.type || null,
        status: edit.status || null,
        priority: edit.priority || null,
        estimated_minutes: toMinutes(edit.estimated_minutes),
        published_status: edit.published_status,
    }, {
        onError: (e) => { editErrors.value = e || {}; },
        onSuccess: () => cancelEdit(),
    });
}

const deletingTask = ref(null);

const deletingSubmission = ref(null);

function removeSubmission() {
    const submission = deletingSubmission.value;
    deletingSubmission.value = null;

    if (submission) send('delete', submission.delete_url);
}

function removeTask() {
    const task = deletingTask.value;
    deletingTask.value = null;

    if (task) send('delete', task.delete_url);
}

/** Badge colour by what the task is actually doing, not by what is stored. */
const statusColor = (status) => ({
    assigned: 'default',
    'in-progress': 'blue',
    completed: 'green',
    overdue: 'red',
    cancelled: 'default',
}[status] ?? 'default');

const priorityColor = (priority) => ({
    low: 'default',
    medium: 'default',
    high: 'amber',
    urgent: 'red',
}[priority] ?? 'default');

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

// ── Sessions ────────────────────────────────────────────────────────────────
//
// Read, mostly. A sitting is recorded in the cockpit that ran it; the two
// things decided here are whether the client may read it and what the coach
// writes down for themselves. So there is no add form and no edit form, only
// a switch, a note and a way to remove a row that should not have come.

// Die Liste ist eine Satzliste, kein Aufgabenzettel: Datum, Dauer, Zustand in
// Spalten, wie der Kern es fuer Datensaetze tut. Das Ausfuehrliche — Agenda,
// Zusammenfassung, Protokoll, Notiz — liegt im Stack, dem Ort, an dem das
// Control Panel seit jeher das Einzelne zeigt. Vorher klappte die Zeile auf und
// schob den halben Bildschirm nach unten.
const openSession = ref(null);
const sessionNote = ref({});
const sessionErrors = ref({});
const deletingSession = ref(null);

const shownSession = computed(() =>
    props.sessions.find((s) => s.id === openSession.value) ?? null,
);

const sessionStackOpen = computed({
    get: () => openSession.value !== null,
    set: (v) => { if (! v) openSession.value = null; },
});

// The subheading arrives finished from the server, unlike the tasks panel's,
// which this screen assembles. A count has to be declined and no language
// does that with a colon: ":count Sitzungen" reads "1 Sitzungen".

function openSessionStack(session) {
    openSession.value = session.id;

    // Seeded on opening rather than up front, so a room with forty sittings
    // does not carry forty strings around for the one that gets read.
    if (sessionNote.value[session.id] === undefined) {
        sessionNote.value[session.id] = session.notes ?? '';
    }
}

function sessionNoteDirty(session) {
    return (sessionNote.value[session.id] ?? '') !== (session.notes ?? '');
}

function saveSessionNote(session) {
    send('patch', session.update_url, { notes: sessionNote.value[session.id] ?? '' }, {
        onError: (e) => { sessionErrors.value = e || {}; },
        onSuccess: () => { sessionErrors.value = {}; },
    });
}

function toggleSessionVisible(session, visible) {
    // Switching an archived sitting on and off again used to leave it a draft:
    // the switch knows two words and the column holds three, so the third was
    // spent the first time anybody touched it. Turning off returns a sitting
    // to where it was.
    const off = session.archived ? 'archived' : 'draft';

    send('patch', session.update_url, { published_status: visible ? 'published' : off }, {
        onError: (e) => { sessionErrors.value = e || {}; },
    });
}

function removeSession() {
    const session = deletingSession.value;
    deletingSession.value = null;

    if (session) send('delete', session.delete_url);
}

// A sitting that was recorded and whose link has run out is not a sitting
// without a recording. The row has to be able to say the difference — but
// quietly: "(Link abgelaufen)" written out behind each of the two made the
// oldest, least interesting row the longest line in the panel, and it wrapped.
// The live ones are links, the dead ones are dimmed words, and the reason is
// said once at the end.
// Three states, not two. "There is a recording and the link has run out" and
// "there is a transcript and there never was a link here" are different facts,
// and the server already tells them apart in `*_expired`. Reading only the
// `has_*` flags made the second one claim the first: a sitting imported with
// `has_transcript` and no URL said "Transkript (Link abgelaufen)", and a coach
// who reads that stops asking for a link that was never issued.
// Zwei Zeichen, eine Zeile, nie ein Umbruch.
//
// Vorher standen die Zustaende ausgeschrieben in der Zelle, und
// "Aufnahme (Link abgelaufen) · Transkript (kein Link)" brach mitten im Satz
// ueber drei Zeilen. Das machte die unwichtigste Spalte zur hoechsten und die
// aelteste Sitzung zur auffaelligsten Zeile.
//
// Jetzt traegt das Zeichen die Sache und der Tooltip den Zustand: da und
// anklickbar, da aber ohne gueltigen Link, oder gar nicht da. Ausgeschrieben
// steht es im Stack, wo Platz dafuer ist.
function sessionMedia(session) {
    const out = [];

    if (session.recording_url) {
        out.push({ key: 'rec', icon: 'computer-voice-mail-microphone', url: session.recording_url, title: props.t.session_recording });
    } else if (session.recording_expired) {
        out.push({ key: 'rec', icon: 'computer-voice-mail-microphone', url: null, title: props.t.session_recording + ' ' + props.t.session_link_expired });
    } else if (session.has_recording) {
        out.push({ key: 'rec', icon: 'computer-voice-mail-microphone', url: null, title: props.t.session_recording + ' ' + props.t.session_link_none });
    }

    if (session.transcript_url) {
        out.push({ key: 'tr', icon: 'file-content-list', url: session.transcript_url, title: props.t.session_transcript });
    } else if (session.transcript_expired) {
        out.push({ key: 'tr', icon: 'file-content-list', url: null, title: props.t.session_transcript + ' ' + props.t.session_link_expired });
    } else if (session.has_transcript) {
        out.push({ key: 'tr', icon: 'file-content-list', url: null, title: props.t.session_transcript + ' ' + props.t.session_link_none });
    }

    return out;
}

const sessionStatusColor = (status) => ({
    completed: 'green',
    scheduled: 'default',
    'in-progress': 'blue',
    processing: 'blue',
    'review-ready': 'amber',
    cancelled: 'red',
    'no-show': 'red',
}[status] ?? 'default');

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
                <Panel :heading="t.panel_tasks" :subheading="taskSubheading">
                    <Card>
                        <div v-if="tasks.length === 0" class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                            {{ t.tasks_empty }}
                        </div>
                        <ul v-else class="-my-2 divide-y divide-content-border">
                            <li v-for="task in tasks" :key="task.id" class="py-2">
                                <!-- Editing: the row becomes the form, in place. -->
                                <div v-if="editingId === task.id" class="space-y-3">
                                    <Field :label="t.task_title" :error="editErrors.title">
                                        <Input v-model="editTask.title" />
                                    </Field>
                                    <Field :label="t.task_description" :error="editErrors.description">
                                        <Textarea v-model="editTask.description" :rows="3" />
                                    </Field>
                                    <div class="grid gap-3 sm:grid-cols-2">
                                        <!-- A plain date input rather than core's `<DatePicker>`,
                                             and for the reason `statamic-offers` writes down:
                                             the component hands its `modelValue` to reka-ui,
                                             which calls `.copy()` on it. A stored date arrives
                                             here as a string, so the component throws during
                                             setup and the field renders as nothing at all. The
                                             add form above keeps the picker — its model starts
                                             empty and never sees a string. -->
                                        <Field :label="t.task_due" :error="editErrors.due_at">
                                            <Input v-model="editTask.due_at" type="date" />
                                        </Field>
                                        <Field :label="t.task_type" :error="editErrors.type">
                                            <Select v-model="editTask.type" :options="typeOptions" />
                                        </Field>
                                        <Field :label="t.task_status" :error="editErrors.status">
                                            <Select v-model="editTask.status" :options="statusOptions" />
                                        </Field>
                                        <Field :label="t.task_priority" :error="editErrors.priority">
                                            <Select v-model="editTask.priority" :options="priorityOptions" />
                                        </Field>
                                        <Field :label="t.task_minutes" :error="editErrors.estimated_minutes">
                                            <Input v-model="editTask.estimated_minutes" type="number" min="0" :append="t.task_minutes_unit" />
                                        </Field>
                                    </div>
                                    <Field :instructions="t.task_visible_help" :error="editErrors.published_status">
                                        <label class="flex items-center gap-2 text-sm">
                                            <Switch
                                                :model-value="editTask.published_status === 'published'"
                                                size="sm"
                                                @update:model-value="editTask.published_status = $event ? 'published' : 'draft'"
                                            />
                                            <span>{{ t.task_visible }}</span>
                                        </label>
                                    </Field>
                                    <div class="flex justify-end gap-2">
                                        <Button variant="ghost" size="sm" :text="t.cancel" :disabled="busy" @click="cancelEdit" />
                                        <Button variant="primary" size="sm" :text="t.save" :disabled="busy || !editTask.title.trim()" @click="saveEdit" />
                                    </div>
                                </div>

                                <div v-else class="flex items-start justify-between gap-3">
                                    <div class="flex min-w-0 items-start gap-3">
                                        <!-- `solo`: a checkbox with no label of its own; the title beside it is the label. -->
                                        <Checkbox
                                            :model-value="task.done"
                                            solo
                                            :disabled="!canEdit || busy"
                                            @update:model-value="toggleTask(task, $event)"
                                        />
                                        <div class="min-w-0">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span class="text-sm" :class="task.done ? 'line-through text-gray-500 dark:text-gray-400' : ''">{{ task.title }}</span>
                                                <!-- Not visible to the client: said plainly, next to the title, not hidden in a panel. -->
                                                <Badge v-if="task.draft" size="sm" color="amber" :text="t.task_draft" />
                                                <Badge v-else-if="task.published_status === 'archived'" size="sm" :text="t.task_archived" />
                                                <Badge v-if="task.type_label" size="sm" :text="task.type_label" />
                                                <Badge
                                                    v-if="task.priority && task.priority !== 'low' && task.priority !== 'medium'"
                                                    size="sm"
                                                    :color="priorityColor(task.priority)"
                                                    :text="task.priority_label"
                                                />
                                            </div>
                                            <p v-if="task.description" class="mt-1 text-xs text-gray-600 dark:text-gray-300">{{ task.description }}</p>
                                            <div class="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-gray-500 dark:text-gray-400">
                                                <span v-if="taskMeta(task)" :title="task.due_at || undefined">{{ taskMeta(task) }}</span>
                                                <Badge v-if="task.overdue" size="sm" color="red" :text="t.task_overdue" />
                                                <Badge
                                                    v-else-if="!task.done && task.workflow_status !== 'assigned'"
                                                    size="sm"
                                                    :color="statusColor(task.workflow_status)"
                                                    :text="task.status_label"
                                                />
                                            </div>

                                            <!-- What came back, indented under the task it
                                                 answers: a submission on its own is not a
                                                 thing anybody goes looking for. -->
                                            <div v-if="task.submissions && task.submissions.length" class="mt-2 space-y-2 border-s-2 border-content-border ps-3">
                                                <div v-for="submission in task.submissions" :key="submission.id">
                                                    <div class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                                                        <span>{{ t.submission_handed_in }} · {{ submission.submitted_human }}</span>
                                                        <Button
                                                            v-if="canEdit"
                                                            icon="trash"
                                                            variant="ghost"
                                                            size="sm"
                                                            :aria-label="t.delete"
                                                            @click="deletingSubmission = submission"
                                                        />
                                                    </div>
                                                    <p v-if="submission.body" class="mt-0.5 text-xs whitespace-pre-line text-gray-900 dark:text-gray-100">{{ submission.body }}</p>
                                                    <ul v-if="submission.files.length" class="mt-1 space-y-0.5">
                                                        <li v-for="file in submission.files" :key="file.id" class="flex items-center gap-2 text-xs">
                                                            <a :href="file.download_url" class="truncate hover:underline">{{ file.filename }}</a>
                                                            <span v-if="file.size_human" class="text-gray-500 dark:text-gray-400">{{ file.size_human }}</span>
                                                            <Badge v-if="file.missing" size="sm" color="red" :text="t.submission_file_missing" />
                                                        </li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div v-if="canEdit" class="flex shrink-0 items-center gap-1">
                                        <Switch
                                            :model-value="task.published"
                                            size="sm"
                                            :disabled="busy"
                                            :aria-label="task.published ? t.task_unpublish : t.task_publish"
                                            @update:model-value="toggleTaskVisible(task, $event)"
                                        />
                                        <Button icon="edit" variant="ghost" size="sm" :aria-label="t.task_edit" @click="startEdit(task)" />
                                        <Button icon="trash" variant="ghost" size="sm" :aria-label="t.delete" @click="deletingTask = task" />
                                    </div>
                                </div>
                            </li>
                        </ul>

                        <form v-if="canEdit" class="mt-4 border-t border-content-border pt-4" @submit.prevent="addTask">
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-start">
                                <Field class="flex-1" :error="taskErrors.title">
                                    <Input v-model="newTask.title" :placeholder="t.task_title_placeholder" />
                                </Field>
                                <Field class="sm:w-52" :error="taskErrors.due_at">
                                    <DatePicker v-model="newTask.due_at" granularity="day" clearable />
                                </Field>
                                <Button type="submit" variant="primary" :text="t.task_add" :disabled="busy || !newTask.title.trim()" />
                            </div>

                            <!-- Folded away: a title and a date is the everyday case, and
                                 five more fields in the way would make it the rare one. -->
                            <div class="mt-2">
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    :text="showTaskFields ? t.task_less : t.task_more"
                                    @click="showTaskFields = !showTaskFields"
                                />
                            </div>

                            <div v-if="showTaskFields" class="mt-3 space-y-3">
                                <Field :label="t.task_description" :error="taskErrors.description">
                                    <Textarea v-model="newTask.description" :rows="3" :placeholder="t.task_description_placeholder" />
                                </Field>
                                <div class="grid gap-3 sm:grid-cols-3">
                                    <Field :label="t.task_type" :error="taskErrors.type">
                                        <Select v-model="newTask.type" :options="typeOptions" />
                                    </Field>
                                    <Field :label="t.task_priority" :error="taskErrors.priority">
                                        <Select v-model="newTask.priority" :options="priorityOptions" />
                                    </Field>
                                    <Field :label="t.task_minutes" :error="taskErrors.estimated_minutes">
                                        <Input v-model="newTask.estimated_minutes" type="number" min="0" :append="t.task_minutes_unit" />
                                    </Field>
                                </div>
                                <Field :instructions="t.task_visible_help" :error="taskErrors.published_status">
                                    <label class="flex items-center gap-2 text-sm">
                                        <Switch v-model="newTask.visible" size="sm" />
                                        <span>{{ t.task_visible }}</span>
                                    </label>
                                </Field>
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

                <!-- Sessions: what happened. Written elsewhere, decided here.
                     Every sitting is listed, drafts included — this is the
                     coach's desk, and its job is to show what the client
                     cannot see yet. -->
                <Panel :heading="t.panel_sessions" :subheading="sessionsSubheading">
                    <Card :class="sessions.length ? 'p-0!' : ''">
                        <div v-if="sessions.length === 0" class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                            {{ t.sessions_empty }}
                        </div>

                        <!-- Core's own table, not a hand-rolled list. A sitting
                             is a record with a date, a length and a state, and
                             the Control Panel has one way of showing records. -->
                        <!-- `ps-4` auf der ersten und `pe-4` auf der letzten
                             Spalte: die Karte traegt hier kein Polster, damit
                             die Trennlinien durchlaufen, und ohne das klebte
                             das Datum an der Kante. -->
                        <Table v-else>
                            <TableColumns>
                                <TableColumn class="ps-4 whitespace-nowrap">{{ t.session_column_when }}</TableColumn>
                                <TableColumn>{{ t.session_column_session }}</TableColumn>
                                <TableColumn class="whitespace-nowrap">{{ t.session_column_media }}</TableColumn>
                                <TableColumn v-if="canEdit" class="whitespace-nowrap">{{ t.session_column_visible }}</TableColumn>
                                <TableColumn v-if="canEdit" class="pe-4"><span class="sr-only">{{ t.delete }}</span></TableColumn>
                            </TableColumns>
                            <TableRows>
                                <TableRow v-for="session in sessions" :key="session.id">
                                    <TableCell class="ps-4 align-top tabular-nums whitespace-nowrap">
                                        <button
                                            type="button"
                                            class="text-start hover:underline"
                                            :title="session.held_at || ''"
                                            @click="openSessionStack(session)"
                                        >
                                            {{ session.held_date || t.session_no_date }}
                                            <span v-if="session.held_time" class="block text-xs text-gray-500 dark:text-gray-400">{{ session.held_time }}</span>
                                        </button>
                                    </TableCell>

                                    <TableCell class="align-top">
                                        <button type="button" class="text-start" @click="openSessionStack(session)">
                                            <span class="font-medium hover:underline">{{ session.title }}</span>
                                            <!-- Kein Entwurfs-Abzeichen mehr: der
                                                 Schalter zwei Spalten weiter sagt
                                                 dasselbe, und die zweite Marke
                                                 machte diese Zeile hoeher als
                                                 alle anderen. `archived` bleibt,
                                                 den kann der Schalter nicht
                                                 zeigen. -->
                                            <Badge
                                                v-if="session.status_label"
                                                class="ms-2 align-middle"
                                                size="sm"
                                                :color="sessionStatusColor(session.status)"
                                                :text="session.status_label"
                                            />
                                            <Badge v-if="session.archived" class="ms-2 align-middle" size="sm" :text="t.session_archived" />
                                            <span class="block text-xs text-gray-500 dark:text-gray-400">
                                                <span v-if="session.duration_minutes">{{ session.duration_minutes }} {{ t.session_minutes_unit }}</span>
                                                <span v-if="session.duration_minutes && session.coach_name" aria-hidden="true"> · </span>
                                                <span v-if="session.coach_name">{{ session.coach_name }}</span>
                                            </span>
                                        </button>
                                    </TableCell>

                                    <TableCell class="align-top">
                                        <span class="flex items-center gap-2">
                                            <template v-for="m in sessionMedia(session)" :key="m.key">
                                                <a
                                                    v-if="m.url"
                                                    :href="m.url"
                                                    target="_blank"
                                                    rel="noopener"
                                                    :title="m.title"
                                                    :aria-label="m.title"
                                                ><Icon :name="m.icon" class="size-4" /></a>
                                                <!-- Da, aber kein gueltiger Weg
                                                     hin. Gedimmt statt weg: die
                                                     Aufnahme gibt es, nur der
                                                     Link nicht mehr. -->
                                                <span v-else :title="m.title" :aria-label="m.title" class="text-gray-400 opacity-60 dark:text-gray-500">
                                                    <Icon :name="m.icon" class="size-4" />
                                                </span>
                                            </template>
                                        </span>
                                    </TableCell>

                                    <TableCell v-if="canEdit" class="align-top">
                                        <Switch
                                            :model-value="session.published"
                                            size="sm"
                                            :disabled="busy"
                                            :title="session.published ? t.session_visible : t.session_draft"
                                            :aria-label="t.session_visible"
                                            @update:model-value="toggleSessionVisible(session, $event)"
                                        />
                                    </TableCell>

                                    <TableCell v-if="canEdit" class="pe-4 text-end align-top">
                                        <Button
                                            icon="trash"
                                            variant="ghost"
                                            size="sm"
                                            :aria-label="t.delete"
                                            @click="deletingSession = session"
                                        />
                                    </TableCell>
                                </TableRow>
                            </TableRows>
                        </Table>

                        <!-- The panel would otherwise stop mid-air. Its two
                             neighbours end in a form, and the absence of one
                             here is the thing worth explaining: sittings are
                             not typed, they arrive. -->
                        <p class="border-t border-content-border px-4 py-3 text-xs text-gray-500 dark:text-gray-400" :class="sessions.length ? '' : 'px-0'">
                            {{ t.sessions_footnote }}
                        </p>
                    </Card>
                </Panel>

                <!-- Was in der Zeile keinen Platz hat: Agenda, Zusammenfassung,
                     das Protokoll und die eigene Notiz. Im Stack, weil das
                     Control Panel das Einzelne seit jeher dort zeigt. -->
                <Stack v-model:open="sessionStackOpen" size="narrow">
                    <div v-if="shownSession" class="bg-content-bg flex h-full flex-col">
                        <div class="border-content-border border-b px-6 py-4">
                            <Heading :text="shownSession.title" size="lg" />
                            <p class="mt-0.5 text-sm text-gray-500 tabular-nums dark:text-gray-400">
                                <span>{{ shownSession.held_date ? shownSession.held_date + ', ' + shownSession.held_time : t.session_no_date }}</span>
                                <span v-if="shownSession.duration_minutes"> · {{ shownSession.duration_minutes }} {{ t.session_minutes_unit }}</span>
                                <span v-if="shownSession.coach_name"> · {{ shownSession.coach_name }}</span>
                            </p>
                        </div>

                        <div class="flex-1 space-y-5 overflow-y-auto px-6 py-5">
                            <!-- In der Liste tragen zwei Zeichen die Sache. Hier
                                 ist Platz fuer den Zustand in Worten. -->
                            <div v-if="sessionMedia(shownSession).length" class="flex flex-wrap gap-x-4 gap-y-1 text-sm">
                                <template v-for="m in sessionMedia(shownSession)" :key="m.key">
                                    <a v-if="m.url" :href="m.url" target="_blank" rel="noopener" class="flex items-center gap-1.5 underline underline-offset-2">
                                        <Icon :name="m.icon" class="size-4" />{{ m.title }}
                                    </a>
                                    <span v-else class="flex items-center gap-1.5 text-gray-500 dark:text-gray-400">
                                        <Icon :name="m.icon" class="size-4 opacity-60" />{{ m.title }}
                                    </span>
                                </template>
                            </div>

                            <div v-if="shownSession.agenda">
                                <div class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ t.session_agenda }}</div>
                                <p class="mt-0.5 text-sm whitespace-pre-line">{{ shownSession.agenda }}</p>
                            </div>

                            <div v-if="shownSession.summary">
                                <div class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ t.session_summary }}</div>
                                <p class="mt-0.5 text-sm whitespace-pre-line">{{ shownSession.summary }}</p>
                            </div>

                            <div>
                                <div class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ t.session_protocol }}</div>
                                <!-- Blocks, never markup. The write-up is HTML from a
                                     system this addon did not author and a Control Panel
                                     is a superuser session, so the server hands over the
                                     one distinction that carries the structure — heading
                                     or not — and the elements below are the screen's own.
                                     Flattened to one string, the subheadings sat in the
                                     same weight as their paragraphs and the longest block
                                     on the page ran together. -->
                                <div v-if="shownSession.protocol_blocks.length" class="mt-0.5 space-y-1">
                                    <template v-for="(block, i) in shownSession.protocol_blocks" :key="i">
                                        <p v-if="block.type === 'heading'" class="pt-1.5 text-sm font-semibold">{{ block.text }}</p>
                                        <p v-else class="text-sm whitespace-pre-line">{{ block.text }}</p>
                                    </template>
                                </div>
                                <p v-else class="mt-0.5 text-sm text-gray-500 italic dark:text-gray-400">{{ t.session_protocol_none }}</p>
                            </div>

                            <Field v-if="canEdit" :label="t.session_notes" :instructions="t.session_notes_help" :error="sessionErrors.notes">
                                <Textarea v-model="sessionNote[shownSession.id]" :rows="4" />
                            </Field>
                        </div>

                        <div v-if="canEdit" class="border-content-border flex items-center justify-between gap-3 border-t px-6 py-4">
                            <label class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                                <Switch
                                    :model-value="shownSession.published"
                                    size="sm"
                                    :disabled="busy"
                                    @update:model-value="toggleSessionVisible(shownSession, $event)"
                                />
                                <span>{{ shownSession.published ? t.session_visible : t.session_draft }}</span>
                            </label>
                            <Button
                                variant="primary"
                                :text="t.session_notes_save"
                                :disabled="busy || !sessionNoteDirty(shownSession)"
                                @click="saveSessionNote(shownSession)"
                            />
                        </div>
                    </div>
                </Stack>

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
                                    <Field v-if="canEdit" :error="ownerErrors.owner_user_id">
                                        <Select
                                            :model-value="owner"
                                            :options="ownerOptions"
                                            size="sm"
                                            :disabled="busy"
                                            @update:model-value="changeOwner"
                                        />
                                    </Field>
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
            :open="deletingSubmission !== null"
            :title="t.submission_delete_title"
            :body-text="t.submission_delete_body"
            :button-text="t.delete"
            danger
            @update:open="deletingSubmission = $event ? deletingSubmission : null"
            @confirm="removeSubmission"
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

        <ConfirmationModal
            :open="deletingSession !== null"
            :title="t.session_delete_title"
            :body-text="t.session_delete_body"
            :button-text="t.delete"
            danger
            @update:open="deletingSession = $event ? deletingSession : null"
            @confirm="removeSession"
        />

        <DocsCallout
            :topic="t.title"
            url="https://docs.adriangoldner.dev/clientrooms/"
        />
    </div>
</template>
