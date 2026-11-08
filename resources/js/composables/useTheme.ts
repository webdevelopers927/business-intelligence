import { router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { route } from '@/lib/route';
import type { Theme } from '@/types/domain';

const prefersDark = () => window.matchMedia('(prefers-color-scheme: dark)').matches;

export function applyTheme(theme: Theme): void {
    const dark = theme === 'dark' || (theme === 'system' && prefersDark());
    document.documentElement.classList.toggle('dark', dark);
    document.documentElement.dataset.themePreference = theme;
}

/** Theme preference persisted on the user; "system" follows the OS live. */
export function useTheme() {
    const page = usePage();
    const preference = computed<Theme>(() => page.props.auth.user?.theme ?? 'system');
    const isDark = ref(false);

    let media: MediaQueryList | null = null;
    const onChange = () => {
        if (preference.value === 'system') {
            applyTheme('system');
        }
        isDark.value = document.documentElement.classList.contains('dark');
    };

    onMounted(() => {
        media = window.matchMedia('(prefers-color-scheme: dark)');
        media.addEventListener('change', onChange);
        isDark.value = document.documentElement.classList.contains('dark');
    });
    onBeforeUnmount(() => media?.removeEventListener('change', onChange));

    function setTheme(theme: Theme): void {
        applyTheme(theme);
        isDark.value = document.documentElement.classList.contains('dark');
        router.patch(route('profile.theme'), { theme }, { preserveScroll: true, preserveState: true });
    }

    return { preference, isDark, setTheme };
}
