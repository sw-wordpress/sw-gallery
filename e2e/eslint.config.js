import js from '@eslint/js';
import globals from 'globals';
import tseslint from 'typescript-eslint';
import prettier from 'eslint-config-prettier';

export default tseslint.config(
  { ignores: ['node_modules/**', 'playwright-report/**', 'test-results/**', '.auth/**'] },
  js.configs.recommended,
  ...tseslint.configs.recommended,
  {
    files: ['**/*.ts'],
    languageOptions: { globals: { ...globals.node, ...globals.browser } },
    rules: {
      '@typescript-eslint/no-explicit-any': 'error',
      '@typescript-eslint/consistent-type-imports': 'error',
      '@typescript-eslint/no-unused-vars': [
        'error',
        { argsIgnorePattern: '^_', varsIgnorePattern: '^_' },
      ],
      // No sleeps: wait on conditions (web-first assertions).
      'no-restricted-properties': [
        'error',
        { object: 'page', property: 'waitForTimeout', message: 'Use web-first assertions.' },
      ],
    },
  },
  { files: ['*.js'], languageOptions: { globals: { ...globals.node } } },
  prettier,
);
