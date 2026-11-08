import { defineConfigWithVueTs, vueTsConfigs } from '@vue/eslint-config-typescript';
import vue from 'eslint-plugin-vue';

export default defineConfigWithVueTs(
    vue.configs['flat/recommended'],
    vueTsConfigs.recommended,
    {
        ignores: ['vendor', 'node_modules', 'public', 'bootstrap/ssr', 'resources/js/components/ui/*/index.ts'],
    },
    {
        rules: {
            'vue/multi-word-component-names': 'off',
            // TS optional props are intentionally `undefined` by default.
            'vue/require-default-prop': 'off',
            'vue/max-attributes-per-line': 'off',
            'vue/singleline-html-element-content-newline': 'off',
            'vue/html-self-closing': 'off',
            'vue/html-indent': 'off',
            'vue/html-closing-bracket-newline': 'off',
            'vue/multiline-html-element-content-newline': 'off',
            '@typescript-eslint/no-explicit-any': 'error',
        },
    },
);
