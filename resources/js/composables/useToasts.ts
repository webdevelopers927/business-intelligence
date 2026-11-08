import { ref } from 'vue';
import type { Toast } from '@/types/domain';

const toasts = ref<Toast[]>([]);
const timers = new Map<string, ReturnType<typeof setTimeout>>();

/** Errors stay until dismissed; everything else auto-dismisses after 5 s. */
export function useToasts() {
    function dismiss(id: string): void {
        toasts.value = toasts.value.filter((t) => t.id !== id);
        const timer = timers.get(id);
        if (timer) {
            clearTimeout(timer);
            timers.delete(id);
        }
    }

    function push(toast: Omit<Toast, 'id'> & { id?: string }): void {
        const id = toast.id ?? crypto.randomUUID();
        if (toasts.value.some((t) => t.id === id)) {
            return;
        }
        toasts.value = [...toasts.value.slice(-3), { ...toast, id }];
        if (toast.type !== 'error') {
            timers.set(id, setTimeout(() => dismiss(id), 5000));
        }
    }

    return { toasts, push, dismiss };
}
