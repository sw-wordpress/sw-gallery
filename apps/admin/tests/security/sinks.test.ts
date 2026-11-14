import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { ESLint } from 'eslint';
import { describe, expect, it } from 'vitest';

/**
 * Security guard (M5-04 e): the lint bans for markup and code sinks stay in force, and the shipped bundles contain no
 * code-from-string primitive. The lint cases lint hostile snippets with the project's own configuration.
 */
const root = resolve(import.meta.dirname, '..', '..');

async function messages(code: string, file: string): Promise<string[]> {
  const eslint = new ESLint({ cwd: root });
  const [result] = await eslint.lintText(code, { filePath: join(root, file) });
  return (result?.messages ?? []).map((message) => message.ruleId ?? 'parse-error');
}

describe('lint bans', () => {
  const cases: Array<[string, string, string]> = [
    ['innerHTML', 'const e = document.body; e.innerHTML = "<b>x</b>";', 'no-restricted-properties'],
    ['outerHTML', 'document.body.outerHTML = "x";', 'no-restricted-properties'],
    [
      'insertAdjacentHTML',
      'document.body.insertAdjacentHTML("beforeend", "x");',
      'no-restricted-properties',
    ],
    ['document.write', 'document.write("x");', 'no-restricted-properties'],
    ['eval', 'eval("1");', 'no-eval'],
    ['new Function', 'new Function("return 1");', 'no-new-func'],
    ['string timer', 'setTimeout("alert(1)", 0);', 'no-implied-eval'],
    ['javascript: url', 'const u = "javascript:alert(1)";', 'no-script-url'],
  ];
  it.each(cases)('%s is refused in application code', async (_name, code, rule) => {
    expect(await messages(code, 'src/security-probe.ts')).toContain(rule);
  });

  it('refuses v-html in templates', async () => {
    const template =
      '<template><div v-html="html" /></template>\n<script setup lang="ts">\nconst html = "";\n</script>\n';
    expect(await messages(template, 'src/Probe.vue')).toContain('vue/no-v-html');
  });
});

function files(dir: string): string[] {
  return readdirSync(dir).flatMap((entry) => {
    const path = join(dir, entry);
    return statSync(path).isDirectory() ? files(path) : [path];
  });
}

describe('built bundle', () => {
  const build = resolve(root, '..', '..', 'assets', 'admin');
  const present = existsSync(build);
  it.skipIf(!present)('contains no eval or new Function', () => {
    const offenders: string[] = [];
    for (const file of files(build).filter((path) => path.endsWith('.js'))) {
      const code = readFileSync(file, 'utf8');
      if (/\beval\s*\(|new\s+Function\s*\(|\bFunction\s*\(\s*["'`]/.test(code)) {
        offenders.push(file.slice(build.length + 1));
      }
    }
    expect(offenders).toEqual([]);
  });
});
