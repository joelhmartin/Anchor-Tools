#!/usr/bin/env node
/**
 * Generates *.min.css / *.min.js siblings for every CSS/JS source file in the
 * plugin. Idempotent. Run by .github/workflows/release.yml during a tagged
 * build so the Release ZIP ships minified assets. Safe to run locally too:
 *
 *   npm install --no-save csso terser
 *   node bin/build-assets.mjs
 *
 * Skips:
 *   - anything matching *.min.css / *.min.js
 *   - vendor/, node_modules/, .git/, .github/, bin/
 *   - any glob listed in .minifyignore
 */

import { readFile, writeFile, readdir, stat } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { join, relative, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

import { minify as csso } from 'csso';
import { minify as terser } from 'terser';

const ROOT = join(fileURLToPath(import.meta.url), '..', '..');
const ALWAYS_SKIP_DIRS = new Set(['vendor', 'node_modules', '.git', '.github', 'bin']);

function globToRegex(glob) {
  // Minimal glob: ** => .*, * => [^/]*, escape regex metachars.
  const escaped = glob
    .replace(/[.+^$()|[\]{}\\]/g, '\\$&')
    .replace(/\*\*/g, '__DOUBLESTAR__')
    .replace(/\*/g, '[^/]*')
    .replace(/__DOUBLESTAR__/g, '.*');
  return new RegExp('^' + escaped + '$');
}

async function loadIgnore() {
  const file = join(ROOT, '.minifyignore');
  if (!existsSync(file)) return [];
  const text = await readFile(file, 'utf8');
  return text
    .split('\n')
    .map((l) => l.trim())
    .filter((l) => l && !l.startsWith('#'))
    .map(globToRegex);
}

async function walk(dir, out = []) {
  const entries = await readdir(dir, { withFileTypes: true });
  for (const entry of entries) {
    if (entry.name.startsWith('.') && entry.name !== '.') continue;
    const full = join(dir, entry.name);
    if (entry.isDirectory()) {
      if (ALWAYS_SKIP_DIRS.has(entry.name)) continue;
      await walk(full, out);
    } else if (entry.isFile()) {
      out.push(full);
    }
  }
  return out;
}

function isMinifiable(rel) {
  if (/\.min\.(css|js)$/i.test(rel)) return false;
  return /\.(css|js)$/i.test(rel);
}

function minPath(rel) {
  return rel.replace(/\.(css|js)$/i, (_, ext) => `.min.${ext.toLowerCase()}`);
}

async function isFresh(srcAbs, minAbs) {
  if (!existsSync(minAbs)) return false;
  const [s, m] = await Promise.all([stat(srcAbs), stat(minAbs)]);
  return m.mtimeMs >= s.mtimeMs;
}

async function main() {
  const ignore = await loadIgnore();
  const all = await walk(ROOT);
  const targets = all
    .map((abs) => relative(ROOT, abs).split(sep).join('/'))
    .filter(isMinifiable)
    .filter((rel) => !ignore.some((re) => re.test(rel)));

  let built = 0;
  let skipped = 0;
  let failed = 0;

  for (const rel of targets) {
    const srcAbs = join(ROOT, rel);
    const minRel = minPath(rel);
    const minAbs = join(ROOT, minRel);

    if (await isFresh(srcAbs, minAbs)) {
      skipped++;
      continue;
    }

    try {
      const src = await readFile(srcAbs, 'utf8');
      if (rel.endsWith('.css')) {
        const out = csso(src).css;
        await writeFile(minAbs, out, 'utf8');
      } else {
        const out = await terser(src, {
          sourceMap: { filename: minRel.split('/').pop(), url: `${minRel.split('/').pop()}.map` },
          format: { comments: false },
        });
        if (out.error) throw out.error;
        await writeFile(minAbs, out.code, 'utf8');
        if (out.map) await writeFile(`${minAbs}.map`, out.map, 'utf8');
      }
      built++;
      console.log(`  built  ${minRel}`);
    } catch (err) {
      failed++;
      console.error(`  FAIL   ${rel}: ${err.message}`);
    }
  }

  console.log(`\nMinify summary: ${built} built, ${skipped} up-to-date, ${failed} failed, ${targets.length} total`);
  if (failed > 0) process.exit(1);
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
