import js from '@eslint/js';
import globals from 'globals';
import tseslint from 'typescript-eslint';
import prettier from 'eslint-config-prettier';

const htmlSinks = ['innerHTML', 'outerHTML', 'insertAdjacentHTML'].map((property) => ({
  property,
  message: 'Never insert markup: render elements (CODE-STYLE §8).',
}));

export default tseslint.config(
  { ignores: ['node_modules/**', 'coverage/**', 'build/**'] },
  js.configs.recommended,
  ...tseslint.configs.recommended,
  {
    files: ['**/*.ts', '**/*.tsx'],
    languageOptions: { globals: { ...globals.browser } },
    rules: {
      '@typescript-eslint/no-explicit-any': 'error',
      '@typescript-eslint/consistent-type-imports': 'error',
      '@typescript-eslint/no-unused-vars': [
        'error',
        // The classic JSX runtime calls the imported `createElement` / `Fragment` implicitly.
        { argsIgnorePattern: '^_', varsIgnorePattern: '^(_|createElement$|Fragment$)' },
      ],
      'no-console': ['error', { allow: ['warn', 'error'] }],
      'no-restricted-properties': [
        'error',
        ...htmlSinks,
        { object: 'document', property: 'write', message: 'document.write is forbidden.' },
      ],
      'no-restricted-syntax': [
        'error',
        {
          selector: 'JSXAttribute[name.name="dangerouslySetInnerHTML"]',
          message: 'Never insert markup (CODE-STYLE §8).',
        },
      ],
    },
  },
  {
    // Tests build fixture DOMs from trusted literals.
    files: ['**/tests/**/*.ts', '**/tests/**/*.tsx'],
    rules: { 'no-restricted-properties': 'off' },
  },
  {
    files: ['scripts/**/*.mjs', 'vite.config.ts', 'eslint.config.js'],
    languageOptions: { globals: { ...globals.node } },
  },
  prettier,
);
