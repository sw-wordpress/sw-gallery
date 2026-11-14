import js from '@eslint/js';
import globals from 'globals';
import tseslint from 'typescript-eslint';
import pluginVue from 'eslint-plugin-vue';
import vueParser from 'vue-eslint-parser';
import prettier from 'eslint-config-prettier';

const htmlSinks = ['innerHTML', 'outerHTML', 'insertAdjacentHTML'].map((property) => ({
  property,
  message: 'Never insert markup: render elements or text (CODE-STYLE §8).',
}));

export default tseslint.config(
  { ignores: ['node_modules/**', 'coverage/**', '../../assets/**'] },
  js.configs.recommended,
  ...tseslint.configs.recommended,
  ...pluginVue.configs['flat/recommended'],
  {
    files: ['**/*.vue', '**/*.ts'],
    languageOptions: {
      parser: vueParser,
      parserOptions: {
        parser: tseslint.parser,
        ecmaVersion: 'latest',
        sourceType: 'module',
        extraFileExtensions: ['.vue'],
      },
      globals: { ...globals.browser },
    },
    rules: {
      '@typescript-eslint/no-explicit-any': 'error',
      '@typescript-eslint/consistent-type-imports': 'error',
      '@typescript-eslint/no-unused-vars': [
        'error',
        { argsIgnorePattern: '^_', varsIgnorePattern: '^_' },
      ],
      'vue/multi-word-component-names': 'off',
      'vue/no-v-html': 'error',
      'vue/block-lang': ['error', { script: { lang: 'ts' } }],
      'vue/define-props-declaration': ['error', 'type-based'],
      'vue/define-emits-declaration': ['error', 'type-based'],
      'no-console': ['error', { allow: ['warn', 'error'] }],
      // Security sinks (docs/security.md): no markup insertion, no code from strings.
      'no-restricted-properties': [
        'error',
        ...htmlSinks,
        { object: 'document', property: 'write', message: 'document.write is forbidden.' },
        { object: 'document', property: 'writeln', message: 'document.writeln is forbidden.' },
      ],
      'no-eval': 'error',
      'no-implied-eval': 'error',
      'no-new-func': 'error',
      'no-script-url': 'error',
    },
  },
  {
    // Fixture DOMs of the unit tests are built from trusted literals; hostile `javascript:` samples are test data.
    files: ['**/*.test.ts', '**/tests/**/*.ts'],
    rules: { 'no-restricted-properties': 'off', 'no-script-url': 'off' },
  },
  {
    files: ['scripts/**/*.{ts,mjs}', 'vite.config.ts', 'eslint.config.js'],
    languageOptions: { globals: { ...globals.node } },
  },
  prettier,
);
