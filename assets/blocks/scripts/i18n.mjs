#!/usr/bin/env node
// Translation tooling for the block (used by bin/i18n.sh; no npm packages needed, plain Node).
//
//   node scripts/i18n.mjs pot  <out.pot>           strings of src/**/*.ts(x) and of block.json as a POT fragment
//   node scripts/i18n.mjs json <in.po> <out-dir>   Jed JSON catalog of the block script for one locale
//
// JS strings are read from literal `__( 'text', 'sw-gallery' )` and `_x( 'text', 'context', 'sw-gallery' )` calls.
// Any other i18n call (`_n`, `_e`, ...), a non-literal text or a non-literal text domain is an ERROR, so a string can never be
// silently missed. WordPress finds the JSON catalog of a script by the md5 of its path relative to the plugin
// (`languages/<domain>-<locale>-<md5>.json`); the script is assets/blocks/build/index.js (Block/BlockRegistrar).
import { createHash } from 'node:crypto';
import { mkdirSync, readdirSync, readFileSync, statSync, writeFileSync } from 'node:fs';
import { dirname, join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const BLOCKS_DIR = resolve(HERE, '..');
const ROOT = resolve(BLOCKS_DIR, '../..');
const DOMAIN = 'sw-gallery';
const SCRIPT_PATH = 'assets/blocks/build/index.js';
const SRC_PREFIX = 'assets/blocks/';

const fail = (message) => {
  console.error(`i18n: ${message}`);
  process.exit(1);
};

function sourceFiles(dir) {
  const files = [];
  for (const name of readdirSync(dir).sort()) {
    const path = join(dir, name);
    if (statSync(path).isDirectory()) files.push(...sourceFiles(path));
    else if (/\.(ts|tsx)$/.test(name) && !name.endsWith('.d.ts')) files.push(path);
  }
  return files;
}

const UNESCAPES = { n: '\n', t: '\t', r: '\r', '\\': '\\', "'": "'", '"': '"', '`': '`' };
const unescapeJs = (text) => text.replace(/\\(.)/g, (_m, ch) => UNESCAPES[ch] ?? ch);
const quote = (text) =>
  `"${text.replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/\n/g, '\\n').replace(/\t/g, '\\t')}"`;

const STRING = String.raw`(?:'((?:\\.|[^'\\])*)'|"((?:\\.|[^"\\])*)")`;
const CALL = new RegExp(String.raw`(?<![\w.$])(__|_x|_n|_nx|_e|esc_html__|esc_attr__)\(\s*`, 'g');
const TEXT = new RegExp(String.raw`^${STRING}\s*,\s*`);
const DOMAIN_ARG = new RegExp(String.raw`^${STRING}\s*[,)]`);

function lineOf(source, index) {
  return source.slice(0, index).split('\n').length;
}

/** The `translators:` comment directly above (or right before) the call. */
function translatorComment(source, index) {
  const before = source.slice(0, index);
  const match = /\/\*\s*translators:\s*([\s\S]*?)\*\/[\s{(?:&|,=]*$/.exec(before);
  return match === null ? null : `translators: ${match[1].replace(/\s+/g, ' ').trim()}`;
}

function extractSource(file) {
  const source = readFileSync(file, 'utf8');
  const reference = relative(ROOT, file);
  const entries = [];
  for (const call of source.matchAll(CALL)) {
    const where = `${reference}:${lineOf(source, call.index)}`;
    const rest = source.slice(call.index + call[0].length);
    if (call[1] !== '__' && call[1] !== '_x') fail(`${where}: ${call[1]}() is not supported by this extractor`);
    const text = TEXT.exec(rest);
    if (text === null) fail(`${where}: the text of ${call[1]}() must be a string literal`);
    const msgid = unescapeJs(text[1] ?? text[2] ?? '');
    let context = null;
    let tail = rest.slice(text[0].length);
    if (call[1] === '_x') {
      const ctx = TEXT.exec(tail);
      if (ctx === null) fail(`${where}: the context of _x() must be a string literal`);
      context = unescapeJs(ctx[1] ?? ctx[2] ?? '');
      tail = tail.slice(ctx[0].length);
    }
    const domain = DOMAIN_ARG.exec(tail);
    if (domain === null || (domain[1] ?? domain[2]) !== DOMAIN) {
      fail(`${where}: the text domain must be the literal '${DOMAIN}'`);
    }
    entries.push({ msgid, context, reference: where, comment: translatorComment(source, call.index) });
  }
  return entries;
}

function extractBlockJson() {
  const file = join(BLOCKS_DIR, 'block.json');
  const reference = relative(ROOT, file);
  const block = JSON.parse(readFileSync(file, 'utf8'));
  if (block.textdomain !== DOMAIN) fail(`${reference}: textdomain must be "${DOMAIN}"`);
  const entries = [];
  if (typeof block.title === 'string') entries.push({ msgid: block.title, context: 'block title' });
  if (typeof block.description === 'string') {
    entries.push({ msgid: block.description, context: 'block description' });
  }
  for (const keyword of block.keywords ?? []) entries.push({ msgid: keyword, context: 'block keyword' });
  return entries.map((entry) => ({ ...entry, reference, comment: null }));
}

function writePot(outFile) {
  const merged = new Map();
  const all = [...sourceFiles(join(BLOCKS_DIR, 'src')).flatMap(extractSource), ...extractBlockJson()];
  for (const entry of all) {
    const key = `${entry.context ?? ''}\u0004${entry.msgid}`;
    const existing = merged.get(key);
    if (existing === undefined) merged.set(key, { ...entry, references: [entry.reference] });
    else {
      existing.references.push(entry.reference);
      existing.comment ??= entry.comment;
    }
  }
  const lines = ['msgid ""', 'msgstr ""', '"Content-Type: text/plain; charset=UTF-8\\n"', ''];
  for (const entry of merged.values()) {
    if (entry.comment !== null) lines.push(`#. ${entry.comment}`);
    lines.push(`#: ${entry.references.join(' ')}`);
    if (entry.context !== null) lines.push(`msgctxt ${quote(entry.context)}`);
    lines.push(`msgid ${quote(entry.msgid)}`, 'msgstr ""', '');
  }
  writeFileSync(outFile, lines.join('\n'));
}

/** Minimal .po reader: msgctxt / msgid / msgstr (multi-line strings), references and the fuzzy flag. */
function parsePo(text) {
  const entries = [];
  let current = null;
  let field = null;
  const finish = () => {
    if (current !== null && current.msgid !== undefined) entries.push(current);
    current = null;
    field = null;
  };
  for (const raw of text.split('\n')) {
    const line = raw.trim();
    if (line === '') finish();
    else if (line.startsWith('#:')) {
      current ??= { references: [] };
      current.references.push(...line.slice(2).trim().split(/\s+/));
    } else if (line.startsWith('#,')) {
      current ??= { references: [] };
      current.fuzzy = line.includes('fuzzy');
    } else if (line.startsWith('#')) current ??= { references: [] };
    else if (/^(msgctxt|msgid|msgid_plural|msgstr)\b/.test(line)) {
      const [, name, value] = /^(msgctxt|msgid_plural|msgid|msgstr(?:\[\d+\])?)\s+(".*")$/.exec(line) ?? [];
      if (name === undefined) fail(`cannot parse po line: ${line}`);
      if (name === 'msgctxt' && current?.msgid !== undefined) finish();
      current ??= { references: [] };
      field = name;
      current[field] = JSON.parse(value);
    } else if (line.startsWith('"') && current !== null && field !== null) {
      current[field] += JSON.parse(line);
    }
  }
  finish();
  return entries;
}

function writeJson(poFile, outDir) {
  const po = readFileSync(poFile, 'utf8');
  const entries = parsePo(po);
  const header = entries.find((entry) => entry.msgid === '' && entry.msgctxt === undefined);
  const locale = /^Language:\s*(\S+)/m.exec(header?.msgstr ?? '')?.[1];
  if (locale === undefined) fail(`${poFile}: no Language header`);
  const plural = /^Plural-Forms:\s*(.+)$/m.exec(header?.msgstr ?? '')?.[1] ?? 'nplurals=2; plural=n != 1;';

  const messages = {};
  for (const entry of entries) {
    // Plural entries (`msgid_plural`) only exist in the PHP catalog; the script has no plural strings.
    if (entry.msgid === '' || entry.fuzzy === true || !entry.msgstr || entry.msgid_plural) continue;
    // Only strings the script uses (block.json strings are translated by PHP).
    if (!entry.references.some((ref) => ref.startsWith(SRC_PREFIX) && /\.(ts|tsx)(:|$)/.test(ref))) continue;
    const key = entry.msgctxt === undefined ? entry.msgid : `${entry.msgctxt}\u0004${entry.msgid}`;
    messages[key] = [entry.msgstr];
  }
  const catalog = {
    'translation-revision-date': 'YEAR-MO-DA HO:MI+ZONE',
    generator: 'sw-gallery/assets/blocks/scripts/i18n.mjs',
    source: SCRIPT_PATH,
    domain: 'messages',
    locale_data: {
      messages: { '': { domain: 'messages', lang: locale, 'plural-forms': plural }, ...messages },
    },
  };
  const hash = createHash('md5').update(SCRIPT_PATH).digest('hex');
  mkdirSync(outDir, { recursive: true });
  writeFileSync(join(outDir, `${DOMAIN}-${locale}-${hash}.json`), `${JSON.stringify(catalog)}\n`);
}

const [command, first, second] = process.argv.slice(2);
if (command === 'pot' && first) writePot(resolve(first));
else if (command === 'json' && first && second) writeJson(resolve(first), resolve(second));
else fail('usage: i18n.mjs pot <out.pot> | json <in.po> <out-dir>');
