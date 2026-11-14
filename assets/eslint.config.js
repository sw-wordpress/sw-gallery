import js from '@eslint/js';
import globals from 'globals';
import tseslint from 'typescript-eslint';
import prettier from 'eslint-config-prettier';

const htmlSinks = ['innerHTML', 'outerHTML', 'insertAdjacentHTML'].map((property) => ({
  property,
  message: 'Never insert markup: use textContent / DOM APIs (CODE-STYLE §8).',
}));

export default tseslint.config(
  { ignores: ['node_modules/**', 'coverage/**', 'build/**', 'admin/**', 'blocks/**'] },
  js.configs.recommended,
  ...tseslint.configs.recommended,
  {
    files: ['**/*.ts'],
    languageOptions: { globals: { ...globals.browser } },
    rules: {
      '@typescript-eslint/no-explicit-any': 'error',
      '@typescript-eslint/consistent-type-imports': 'error',
      '@typescript-eslint/no-unused-vars': [
        'error',
        { argsIgnorePattern: '^_', varsIgnorePattern: '^_' },
      ],
      'no-console': ['error', { allow: ['warn', 'error'] }],
      // Code from strings and javascript: URLs (docs/security.md).
      'no-eval': 'error',
      'no-implied-eval': 'error',
      'no-new-func': 'error',
      'no-script-url': 'error',
      'no-restricted-properties': [
        'error',
        ...htmlSinks,
        { object: 'document', property: 'write', message: 'document.write is forbidden.' },
      ],
    },
  },
  {
    // Classic script of the native Media Library screens (no build step).
    files: ['media-library/*.js'],
    languageOptions: { sourceType: 'script', globals: { ...globals.browser } },
    rules: {
      'no-restricted-properties': ['error', ...htmlSinks],
    },
  },
  {
    // Tests build fixture DOMs from trusted literals.
    files: ['**/tests/**/*.ts'],
    rules: { 'no-restricted-properties': 'off', 'no-script-url': 'off' },
  },
  {
    files: ['scripts/**/*.mjs', 'vite.config.ts', 'eslint.config.js'],
    languageOptions: { globals: { ...globals.node } },
  },
  prettier,
);
