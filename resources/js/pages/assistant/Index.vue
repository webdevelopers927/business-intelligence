<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ArrowUp, CircleAlert, KeyRound, LoaderCircle, MessageSquarePlus, Sparkles, Trash2 } from 'lucide-vue-next';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import EvidencePanel, { type Evidence } from '@/components/assistant/EvidencePanel.vue';
import Button from '@/components/ui/Button.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { parseAnswer } from '@/lib/answer';
import { formatRelative } from '@/lib/format';
import { http, HttpError } from '@/lib/http';
import { route } from '@/lib/route';
import { cn } from '@/lib/utils';

interface ChatMessage {
    id: number | string;
    role: 'user' | 'assistant';
    content: string;
    evidence: Evidence[];
    stop_reason: string | null;
    created_at: string | null;
}

const props = defineProps<{
    status: 'ready' | 'unconfigured';
    model: string | null;
    hasData: boolean;
    canConfigure: boolean;
    conversations: { id: number; title: string; updated_at: string | null }[];
    conversation: { id: number; title: string; messages: ChatMessage[] } | null;
    suggestions: string[];
}>();

const messages = ref<ChatMessage[]>([...(props.conversation?.messages ?? [])]);
const conversationId = ref<number | null>(props.conversation?.id ?? null);
watch(
    () => props.conversation,
    (c) => {
        messages.value = [...(c?.messages ?? [])];
        conversationId.value = c?.id ?? null;
    },
);

const draft = ref('');
const pending = ref(false);
const error = ref<{ message: string; retry: string | null } | null>(null);
const elapsed = ref(0);
let timer: ReturnType<typeof setInterval> | null = null;
const scroller = ref<HTMLElement | null>(null);
const deleting = ref(false);

const ready = computed(() => props.status === 'ready');
const progress = computed(() => (elapsed.value < 4 ? 'Reading your question…' : elapsed.value < 12 ? 'Querying your data…' : 'Checking the figures…'));

async function scrollDown() {
    await nextTick();
    scroller.value?.scrollTo({ top: scroller.value.scrollHeight, behavior: 'smooth' });
}

async function send(text?: string) {
    const question = (text ?? draft.value).trim();
    if (!question || pending.value || !ready.value) return;

    error.value = null;
    draft.value = '';
    pending.value = true;
    elapsed.value = 0;
    timer = setInterval(() => elapsed.value++, 1000);
    const optimistic: ChatMessage = { id: `pending-${Date.now()}`, role: 'user', content: question, evidence: [], stop_reason: null, created_at: new Date().toISOString() };
    messages.value = [...messages.value, optimistic];
    void scrollDown();

    try {
        const res = await http.post<{ data: { conversation: { id: number; url: string }; messages: ChatMessage[] } }>(route('assistant.messages'), {
            message: question,
            conversation_id: conversationId.value,
        });
        messages.value = [...messages.value.filter((m) => m.id !== optimistic.id), ...res.data.messages];
        if (conversationId.value === null) {
            conversationId.value = res.data.conversation.id;
            window.history.replaceState(window.history.state, '', res.data.conversation.url);
        }
        router.reload({ only: ['conversations'] });
    } catch (e) {
        messages.value = messages.value.filter((m) => m.id !== optimistic.id);
        error.value = {
            message: e instanceof HttpError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : 'Something went wrong. Please try again.',
            retry: question,
        };
        draft.value = question;
    } finally {
        pending.value = false;
        if (timer) clearInterval(timer);
        void scrollDown();
    }
}

function onKeydown(e: KeyboardEvent) {
    if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
        e.preventDefault();
        void send();
    }
}

onBeforeUnmount(() => timer && clearInterval(timer));
void scrollDown();
</script>

<template>
    <AppLayout title="Assistant">
        <template #actions>
            <span v-if="model" class="hidden text-xs text-secondary sm:inline">{{ model }}</span>
            <Button v-if="ready" :href="route('assistant.index')"><MessageSquarePlus />New conversation</Button>
        </template>

        <!-- Not configured: no simulated answers (AI-3). -->
        <div v-if="!ready" class="mx-auto max-w-xl rounded-lg border border-border bg-surface p-6">
            <div class="flex items-start gap-3">
                <span class="flex size-9 shrink-0 items-center justify-center rounded-md bg-accent-subtle text-accent-ink"><KeyRound class="size-4" aria-hidden="true" /></span>
                <div>
                    <h2 class="text-md font-semibold text-ink">The AI analyst isn’t set up yet</h2>
                    <p class="mt-1 text-sm text-secondary">
                        The analyst answers questions about your data using Claude, Anthropic’s AI model. It only sees figures through ORBIT’s read-only analytics, and shows the data behind every answer.
                    </p>
                    <template v-if="canConfigure">
                        <p class="mt-4 text-sm text-ink">To enable it, add an Anthropic API key to the server’s <code class="rounded bg-surface-sunken px-1 font-mono text-xs">.env</code> file and restart the app:</p>
                        <pre class="mt-2 overflow-x-auto rounded-md bg-surface-sunken p-3 font-mono text-xs text-ink">ANTHROPIC_API_KEY=sk-ant-…
# optional
ORBIT_AI_MODEL=claude-opus-5-5
ORBIT_AI_EFFORT=medium</pre>
                        <p class="mt-2 text-xs text-secondary">Keys are created at platform.claude.com. Usage is billed to that Anthropic account; each person can ask up to 30 questions an hour by default.</p>
                    </template>
                    <p v-else class="mt-4 text-sm text-ink">Ask an organization owner or administrator to enable it.</p>
                </div>
            </div>
        </div>

        <div v-else class="grid min-h-0 gap-6 lg:grid-cols-[240px_minmax(0,1fr)]">
            <!-- Conversations -->
            <nav aria-label="Conversations" class="hidden lg:block">
                <p class="label-instrument mb-2">Your conversations</p>
                <ul class="flex flex-col gap-0.5">
                    <li v-for="c in conversations" :key="c.id">
                        <Link
                            :href="route('assistant.show', { conversation: c.id })"
                            :class="cn('block rounded-md px-2 py-1.5 text-sm hover:bg-surface-hover', c.id === conversationId ? 'bg-accent-subtle text-accent-ink' : 'text-ink')"
                        >
                            <span class="line-clamp-1">{{ c.title }}</span>
                            <span class="text-[11px] text-muted">{{ formatRelative(c.updated_at) }}</span>
                        </Link>
                    </li>
                    <li v-if="!conversations.length" class="px-2 text-xs text-secondary">None yet.</li>
                </ul>
            </nav>

            <!-- Chat -->
            <section class="flex min-h-[calc(100dvh-180px)] flex-col rounded-lg border border-border bg-surface" aria-label="Chat">
                <div ref="scroller" class="flex-1 overflow-y-auto px-4 py-5 md:px-6" aria-live="polite">
                    <div v-if="!messages.length && !pending" class="mx-auto flex max-w-2xl flex-col items-center py-8 text-center">
                        <span class="flex size-10 items-center justify-center rounded-full bg-accent-subtle text-accent-ink"><Sparkles class="size-5" aria-hidden="true" /></span>
                        <h2 class="mt-3 text-md font-semibold text-ink">Ask about your business</h2>
                        <p class="mt-1 max-w-md text-sm text-secondary">
                            Answers are computed from your imported data. Open “Evidence” under an answer to see the exact figures it used.
                        </p>
                        <p v-if="!hasData" class="mt-3 rounded-md bg-warning-subtle px-3 py-2 text-xs text-warning">No sales or expense data has been imported yet, so most questions can’t be answered.</p>
                        <div class="mt-6 grid w-full gap-2 sm:grid-cols-2">
                            <button
                                v-for="s in suggestions"
                                :key="s"
                                type="button"
                                class="rounded-md border border-border px-3 py-2.5 text-left text-sm text-ink transition-colors hover:border-accent hover:bg-surface-hover"
                                @click="send(s)"
                            >
                                {{ s }}
                            </button>
                        </div>
                    </div>

                    <ol class="mx-auto flex max-w-3xl flex-col gap-5">
                        <li v-for="m in messages" :key="m.id" :class="m.role === 'user' ? 'flex justify-end' : ''">
                            <div v-if="m.role === 'user'" class="max-w-[85%] rounded-lg bg-accent-subtle px-3.5 py-2 text-sm whitespace-pre-line text-ink">{{ m.content }}</div>
                            <article v-else class="flex gap-3">
                                <span class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full bg-surface-sunken text-accent-ink"><Sparkles class="size-3.5" aria-hidden="true" /></span>
                                <div class="min-w-0 flex-1 text-sm leading-relaxed text-ink">
                                    <template v-for="(b, i) in parseAnswer(m.content)" :key="i">
                                        <p v-if="b.type === 'p'" class="mb-2 last:mb-0">
                                            <template v-for="(part, j) in b.parts" :key="j"><strong v-if="part.bold" class="font-semibold">{{ part.text }}</strong><template v-else>{{ part.text }}</template></template>
                                        </p>
                                        <ul v-else class="mb-2 ml-4 list-disc space-y-1 last:mb-0">
                                            <li v-for="(item, j) in b.items" :key="j">
                                                <template v-for="(part, k) in item" :key="k"><strong v-if="part.bold" class="font-semibold">{{ part.text }}</strong><template v-else>{{ part.text }}</template></template>
                                            </li>
                                        </ul>
                                    </template>
                                    <p v-if="m.evidence.length === 0 && m.stop_reason === 'end_turn'" class="mt-2 text-xs text-secondary">No data was queried for this answer.</p>
                                    <EvidencePanel :items="m.evidence" />
                                </div>
                            </article>
                        </li>
                        <li v-if="pending" class="flex items-center gap-3 text-sm text-secondary" role="status">
                            <span class="flex size-7 items-center justify-center rounded-full bg-surface-sunken"><LoaderCircle class="size-3.5 animate-spin text-accent" aria-hidden="true" /></span>
                            {{ progress }} <span class="num text-xs text-muted">{{ elapsed }}s</span>
                        </li>
                    </ol>
                </div>

                <div class="border-t border-border p-3 md:p-4">
                    <div v-if="error" class="mx-auto mb-2 flex max-w-3xl items-start gap-2 rounded-md bg-negative-subtle px-3 py-2 text-sm text-negative" role="alert">
                        <CircleAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                        <span class="flex-1">{{ error.message }}</span>
                    </div>
                    <form class="mx-auto flex max-w-3xl items-end gap-2" @submit.prevent="send()">
                        <label for="question" class="sr-only">Ask a question about your data</label>
                        <textarea
                            id="question"
                            v-model="draft"
                            rows="2"
                            maxlength="2000"
                            :disabled="pending"
                            placeholder="e.g. Which branch grew fastest last quarter?"
                            class="min-h-11 flex-1 resize-none rounded-md border border-border-strong bg-surface px-3 py-2 text-sm text-ink placeholder:text-muted focus-visible:border-accent disabled:bg-surface-sunken"
                            @keydown="onKeydown"
                        />
                        <Button type="submit" variant="primary" size="icon" :disabled="!draft.trim() || pending" aria-label="Send question"><ArrowUp /></Button>
                    </form>
                    <div class="mx-auto mt-1.5 flex max-w-3xl items-center justify-between text-[11px] text-muted">
                        <span>AI can make mistakes — check the evidence. Enter to send, Shift+Enter for a new line.</span>
                        <button v-if="conversationId" type="button" class="inline-flex items-center gap-1 hover:text-negative" @click="deleting = true"><Trash2 class="size-3" aria-hidden="true" />Delete</button>
                    </div>
                </div>
            </section>
        </div>

        <ConfirmDialog
            :open="deleting"
            title="Delete this conversation?"
            description="The questions and answers in it are removed permanently."
            confirm-label="Delete conversation"
            @update:open="(o: boolean) => (deleting = o)"
            @confirm="conversationId && router.delete(route('assistant.destroy', { conversation: conversationId }))"
        />
    </AppLayout>
</template>
