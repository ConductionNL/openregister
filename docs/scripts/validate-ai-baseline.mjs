#!/usr/bin/env node
/**
 * scripts/validate-ai-baseline.mjs
 *
 * Generic AI-crawler baseline validator. Runs as a postbuild step on
 * every Conduction Docusaurus site that consumes
 * @conduction/docusaurus-preset >= 3.4.0. Asserts the SSG output
 * carries the contract AI crawlers (GPTBot, ClaudeBot, PerplexityBot,
 * OAI-SearchBot, Claude-SearchBot, Google AI Overviews) expect.
 *
 * Universal checks only - no site-specific routes. Sites that want
 * additional gates (per-app SoftwareApplication, FAQPage on specific
 * pages, etc.) extend this script in place. See conduction-website's
 * version for an example of additional checks.
 *
 * Exit codes:
 *   0   all checks passed
 *   1   one or more checks failed (CI should block)
 *   2   build directory not found (script invoked before build)
 */

import {readFileSync, existsSync, statSync} from 'node:fs';
import {join, resolve} from 'node:path';

const buildDir = resolve(process.argv[2] || 'build');

if (!existsSync(buildDir)) {
  console.error(`✗ build directory not found: ${buildDir}`);
  console.error(`  Run \`npx docusaurus build\` first.`);
  process.exit(2);
}

const results = [];

function check(name, fn) {
  try {
    const r = fn();
    results.push({name, ok: r.ok, msg: r.msg});
  } catch (e) {
    results.push({name, ok: false, msg: `threw: ${e.message}`});
  }
}

function readBuild(p) {
  return readFileSync(join(buildDir, p), 'utf8');
}

/* robots.txt - shipped by the preset's ai-crawling plugin (or the
   site's own static/robots.txt). Either way, the file must exist
   and name at least one AI search bot so a `grep` audit can confirm
   the posture at a glance. */
check('robots.txt exists and is non-empty', () => {
  const path = join(buildDir, 'robots.txt');
  if (!existsSync(path)) return {ok: false, msg: 'missing'};
  const size = statSync(path).size;
  if (size < 50) return {ok: false, msg: `too small (${size} bytes)`};
  return {ok: true, msg: `${size} bytes`};
});

check('robots.txt names at least one AI search bot', () => {
  const body = readBuild('robots.txt');
  const candidates = ['OAI-SearchBot', 'Claude-SearchBot', 'PerplexityBot', 'ChatGPT-User', 'Claude-User'];
  const found = candidates.filter(ua => body.includes(`User-agent: ${ua}`));
  if (found.length === 0) {
    return {ok: false, msg: `none of [${candidates.join(', ')}] referenced`};
  }
  return {ok: true, msg: `${found.length} bot(s): ${found.join(', ')}`};
});

check('robots.txt has a Sitemap line', () => {
  const body = readBuild('robots.txt');
  const matches = body.match(/^Sitemap:\s+https?:\/\//gm) || [];
  if (matches.length === 0) return {ok: false, msg: 'no Sitemap: line'};
  return {ok: true, msg: `${matches.length} sitemap line(s)`};
});

/* sitemap.xml - emitted by @docusaurus/plugin-sitemap (loaded via
   the classic preset). Locale-specific sitemaps (e.g. /nl/sitemap.xml)
   are present for i18n builds; we only check the canonical one
   because some sites are single-locale. */
check('sitemap.xml exists and has at least 1 URL', () => {
  const path = join(buildDir, 'sitemap.xml');
  if (!existsSync(path)) return {ok: false, msg: 'missing'};
  const body = readBuild('sitemap.xml');
  const n = (body.match(/<loc>/g) || []).length;
  if (n < 1) return {ok: false, msg: 'no <loc> entries'};
  return {ok: true, msg: `${n} URLs`};
});

/* Helper for the JSON-LD checks below. Docusaurus emits ld+json
   tags via two paths with different attribute ordering: top-level
   headTags renders <script type="..."> first, while Helmet (used
   by <Head> from inside React components like <DetailHero>, <FAQ>)
   prefixes data-rh="true". The regex matches either ordering. */
function extractJsonLdBlocks(html) {
  const out = [];
  const re = /<script\b[^>]*\btype="application\/ld\+json"[^>]*>([\s\S]*?)<\/script>/g;
  let m;
  while ((m = re.exec(html)) !== null) {
    out.push(m[1]);
  }
  return out;
}

check('homepage emits >= 2 JSON-LD blocks, all valid JSON', () => {
  if (!existsSync(join(buildDir, 'index.html'))) return {ok: false, msg: 'no index.html'};
  const html = readBuild('index.html');
  const blocks = extractJsonLdBlocks(html);
  if (blocks.length < 2) return {ok: false, msg: `only ${blocks.length} block(s)`};
  for (const [i, b] of blocks.entries()) {
    try {JSON.parse(b);} catch (e) {
      return {ok: false, msg: `block ${i} invalid JSON: ${e.message}`};
    }
  }
  return {ok: true, msg: `${blocks.length} blocks, all valid`};
});

check('homepage JSON-LD includes Organization and WebSite', () => {
  const html = readBuild('index.html');
  const types = extractJsonLdBlocks(html).map(b => {
    try {return JSON.parse(b)['@type'];} catch {return null;}
  });
  const want = ['Organization', 'WebSite'];
  const missing = want.filter(t => !types.includes(t));
  if (missing.length) return {ok: false, msg: `missing @type: ${missing.join(', ')}`};
  return {ok: true, msg: types.filter(Boolean).join(' + ')};
});

/* Social-card meta. og:image is the one that breaks LinkedIn /
   Slack / AI previews when it 404s, so we also resolve the URL to
   a local file in the build output. */
function metaTag(html, key) {
  const re = new RegExp(`<meta[^>]+(?:name|property)="${key}"[^>]+content="([^"]+)"`, 'i');
  const m = html.match(re);
  return m ? m[1] : null;
}

check('homepage has og:image, og:type, twitter:site, twitter:card', () => {
  const html = readBuild('index.html');
  const checks = {
    'og:image': metaTag(html, 'og:image'),
    'og:type': metaTag(html, 'og:type'),
    'twitter:site': metaTag(html, 'twitter:site'),
    'twitter:card': metaTag(html, 'twitter:card'),
  };
  const missing = Object.entries(checks).filter(([, v]) => !v).map(([k]) => k);
  if (missing.length) return {ok: false, msg: `missing: ${missing.join(', ')}`};
  return {ok: true, msg: 'all four present'};
});

check('og:image URL resolves to a file in the build', () => {
  const html = readBuild('index.html');
  const url = metaTag(html, 'og:image');
  if (!url) return {ok: false, msg: 'no og:image meta'};
  const path = url.replace(/^https?:\/\/[^/]+\//, '');
  const local = join(buildDir, path);
  if (!existsSync(local)) return {ok: false, msg: `og:image refers to ${url}, not found at ${local}`};
  const size = statSync(local).size;
  if (size < 1024) return {ok: false, msg: `og:image file suspiciously small (${size} bytes)`};
  return {ok: true, msg: `${path} (${size} bytes)`};
});

/* Report */
let failed = 0;
for (const {name, ok, msg} of results) {
  const icon = ok ? '✓' : '✗';
  console.log(`${icon} ${name} - ${msg}`);
  if (!ok) failed++;
}
console.log('');
if (failed) {
  console.error(`${failed} of ${results.length} checks failed.`);
  console.error('AI-crawler baseline regressed. Fix the failures above before merging.');
  process.exit(1);
} else {
  console.log(`All ${results.length} AI-baseline checks passed.`);
}
