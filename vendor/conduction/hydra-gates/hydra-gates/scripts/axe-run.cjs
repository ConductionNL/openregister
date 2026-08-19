// SPDX-License-Identifier: EUPL-1.2
//
// Produce tests/axe/report.json for hydra-gates gate-33 (axe-core).
//
// Gate-33 reads ONE key: `violations`, and fails on entries whose
// `impact` is `serious` or `critical`. Everything else written here is
// for humans and for the guards below.
//
// The design point of this file is that it must never write a report
// it has not earned. A `{}` — or a `{"violations": []}` produced by a
// browser that never loaded anything — parses fine and reads to
// gate-33 as a CLEAN accessibility run. (Verified: gate-33 reports
// PASS on a file containing exactly `{}`.) That is strictly worse than
// the loud skip gate-33 emits when the file is absent, because it
// converts "unverified" into "verified clean". So every exit path
// below either writes a report that provably came from a real axe run
// against a real rendered page, or writes NOTHING and exits non-zero.
//
// Three guards enforce that, in order:
//
//   1. SELF-TEST (positive control). Before touching the app, axe is
//      run against a scratch page carrying a KNOWN serious/critical
//      violation (`<button></button>` -> rule `button-name`, impact
//      critical). If axe does not report it, axe is not working —
//      misconfigured tags, a broken injection, a version whose rule
//      set moved — and every subsequent empty `violations` array would
//      be an artefact of the harness, not evidence about the app. A
//      gate whose green has never been shown capable of going red is
//      not a gate. This is the check that runs on every CI run, not
//      just once at authoring time.
//
//   2. HTTP STATUS. A route that answers >= 400 renders Nextcloud's
//      error page, and axe would happily report that page as clean.
//      Analysing the wrong document is indistinguishable from
//      analysing the right one at the JSON layer.
//
//   3. NON-EMPTY RESULT SET — a backstop, and stated as one. The
//      failure it describes (axe returns `violations: []` AND
//      `passes: []` because there was no document to analyse) is not
//      what @axe-core/playwright 4.12.1 actually does: measured
//      against `about:blank` it THROWS ("Please use
//      browser.newContext()") rather than returning empty, and that
//      throw is caught at the bottom of this file, which also writes
//      no report. The guard is kept because which of the two a given
//      version does is a library implementation detail, and the one
//      that returns empty is the one that would be indistinguishable
//      from a clean run.
//
// Env:
//   AXE_BASE_URL  base URL of the instance under test (required)
//   AXE_ROUTES    newline- or comma-separated paths (required)
//   AXE_OUT       where to write the report (required)
//   AXE_USER      login user (optional; no login when empty)
//   AXE_PASSWORD  login password
//   AXE_WAIT_MS   settle time per route after mount (default 1500)

const fs = require('fs');
const path = require('path');

// `@playwright/test` re-exports the browser types, so chromium is
// resolved from the package this job already installs rather than from
// `playwright`, which is only present as a hoisted transitive
// dependency and is not ours to rely on.
const { chromium } = require('@playwright/test');

// 4.x exposes both a named `AxeBuilder` and a `default`. Resolve
// either, and say so loudly if neither is a constructor: a silently
// undefined AxeBuilder throws deep in the run as `new undefined`.
const axeModule = require('@axe-core/playwright');
const AxeBuilder = axeModule.AxeBuilder || axeModule.default || axeModule;

// WCAG 2.2 AA and its predecessors. `best-practice` is deliberately
// excluded: it is not a conformance requirement, and gate-33 is a
// conformance gate.
const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

// The scratch page for guard 1. `<button></button>` has no accessible
// name: axe rule `button-name`, impact critical, tags wcag2a/wcag412.
const CONTROL_HTML =
  '<!DOCTYPE html><html lang="en"><head><title>axe self-test</title></head>' +
  '<body><main><button></button></main></body></html>';
const CONTROL_RULE = 'button-name';

const die = (msg) => {
  console.error('::error::axe-runner: ' + msg);
  process.exit(2);
};

const routes = (process.env.AXE_ROUTES || '')
  .split(/[\n,]/)
  .map((r) => r.trim())
  .filter((r) => r.length > 0);

const baseUrl = (process.env.AXE_BASE_URL || '').replace(/\/+$/, '');
const outPath = process.env.AXE_OUT || '';
const waitMs = parseInt(process.env.AXE_WAIT_MS || '1500', 10);

// ── Scope ────────────────────────────────────────────────────────
// The whole point of this runner is to report the APP's defects. An
// app page is Nextcloud's chrome with the app mounted inside it, and
// axe cannot tell the two apart: unscoped, `#header`'s unified-search
// contrast and a `role-img-alt` on `:root` are attributed to whoever
// owns the repo. Measured on a live instance, that was the ENTIRE
// serious/critical result for openregister (2 nodes) and hermiq (1).
//
// Splitting on commas rather than handing axe one grouped selector is
// deliberate. Both work, but a per-selector `.include()` lets the log
// below say WHICH selectors matched and how many elements each found,
// so a scope that has silently narrowed to one stray element is
// visible rather than inferred from a suspiciously small `passes`.
const splitSel = (raw) => (raw || '')
  .split(',')
  .map((s) => s.trim())
  .filter((s) => s.length > 0);

const includeSel = splitSel(process.env.AXE_INCLUDE);
const excludeSel = splitSel(process.env.AXE_EXCLUDE);

// Build the ONE builder every analysis in this file goes through.
// Nothing calls `new AxeBuilder` directly after this point: a second
// construction site is how a scope stops being applied to the run
// that actually writes the report while every guard still passes.
const buildAxe = (page) => {
  let b = new AxeBuilder({ page }).withTags(TAGS);
  for (const s of includeSel) b = b.include(s);
  for (const s of excludeSel) b = b.exclude(s);
  return b;
};

if (typeof AxeBuilder !== 'function') {
  die('@axe-core/playwright did not export a constructor (got ' + typeof AxeBuilder + '). The installed version changed its export shape.');
}
if (!baseUrl) die('AXE_BASE_URL is empty.');
if (!outPath) die('AXE_OUT is empty.');
if (routes.length === 0) die('AXE_ROUTES resolved to zero routes. Nothing would be analysed, and an empty report would read as a clean accessibility run.');
if (includeSel.length === 0) {
  die(
    'axe-include-selector resolved to zero selectors. An empty scope is not "scope to everything", ' +
    'it is a silent revert to analysing all of Nextcloud\'s chrome and reporting it as this app\'s. ' +
    'Set it to a selector that wraps the app (default "#content-vue, #content"), or to "body" if ' +
    'analysing the whole document really is what this repo wants.'
  );
}

// Keep the report readable: violation nodes carry the full outerHTML,
// which for a Vue app is routinely tens of KB per node.
const trimNode = (n) => ({
  target: n.target,
  html: typeof n.html === 'string' ? n.html.slice(0, 400) : '',
  failureSummary: n.failureSummary,
});

// Remove any pre-existing report FIRST. Every guard exits without
// writing, and "did not write" only means "no report" if there was
// nothing there to begin with — otherwise a stale file from an earlier
// attempt is what gate-33 reads, and it reads as a clean run of code
// that just refused to certify itself.
fs.rmSync(outPath, { force: true });

(async () => {
  const browser = await chromium.launch();
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await context.newPage();

  // ── Guard 1: positive control ──────────────────────────────────
  // Deliberately UNSCOPED: the scratch page is not a Nextcloud page
  // and carries none of the app containers, so a scoped run here
  // would abort on "No elements found for include" and tell us
  // nothing about axe. This guard answers one question only — can
  // axe report a violation at all in this environment. Whether the
  // SCOPE works is guard 4's question, and guard 4 asks it on the
  // real page, because that is the only place it can be asked.
  await page.setContent(CONTROL_HTML);
  const control = await new AxeBuilder({ page }).withTags(TAGS).analyze();
  const controlHit = (control.violations || []).find(
    (v) => v.id === CONTROL_RULE && ['serious', 'critical'].includes(v.impact)
  );
  if (!controlHit) {
    const seen = (control.violations || []).map((v) => v.id + '/' + v.impact).join(', ') || '<none>';
    await browser.close();
    die(
      'SELF-TEST FAILED. axe did not report the deliberate `' + CONTROL_RULE +
      '` violation on the scratch page. It reported: ' + seen +
      '. axe is therefore not capable of failing in this environment, so an empty ' +
      'violations array here would be evidence about the harness, not about the app. ' +
      'Refusing to write a report — gate-33 will skip loudly instead of passing falsely.'
    );
  }
  console.log(
    'axe self-test OK: rule=' + controlHit.id + ' impact=' + controlHit.impact +
    ' nodes=' + controlHit.nodes.length + ' (axe-core ' + control.testEngine.version + ')'
  );

  // ── Login ──────────────────────────────────────────────────────
  if (process.env.AXE_USER) {
    const loginUrl = baseUrl + '/index.php/login';
    const resp = await page.goto(loginUrl, { waitUntil: 'domcontentloaded' });
    if (!resp || resp.status() >= 400) {
      await browser.close();
      die('login page ' + loginUrl + ' returned HTTP ' + (resp ? resp.status() : 'no-response') + '.');
    }
    await page.fill('input[name="user"]', process.env.AXE_USER);
    await page.fill('input[name="password"]', process.env.AXE_PASSWORD || '');
    await Promise.all([
      page.waitForURL((u) => !u.toString().includes('/login'), { timeout: 30000 }).catch(() => {}),
      page.click('button[type="submit"], input[type="submit"]'),
    ]);
    if (page.url().includes('/login')) {
      await browser.close();
      die('login did not leave /login — still at ' + page.url() + '. Every route would be analysed as the LOGIN page, not the app.');
    }
    console.log('Logged in as ' + process.env.AXE_USER + '; landed on ' + page.url());
  }

  // ── Analyse each route ─────────────────────────────────────────
  const violations = [];
  const analysed = [];
  let engine = null;
  let scopeControl = null;

  console.log(
    'axe scope: include=[' + includeSel.join(' | ') + ']' +
    (excludeSel.length ? ' exclude=[' + excludeSel.join(' | ') + ']' : ' exclude=<none>')
  );

  for (const route of routes) {
    const url = baseUrl + (route.startsWith('/') ? route : '/' + route);
    const resp = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60000 });
    const status = resp ? resp.status() : 0;

    // Guard 2.
    if (status === 0 || status >= 400) {
      await browser.close();
      die(
        'route ' + url + ' returned HTTP ' + status + '. axe would have analysed an ' +
        'error page and reported it as clean. Set the axe-routes input to routes ' +
        'this app actually serves.'
      );
    }

    // Give the SPA a chance to mount. A Nextcloud page shell always
    // has #content, including when the bundle never mounts — which is
    // why guard 3 is a separate check and not folded into this wait.
    await page.waitForSelector('#content, main, [role="main"]', { timeout: 60000 }).catch(() => {});
    await page.waitForTimeout(waitMs);

    // What did the scope actually match on THIS page? Reported per
    // selector, because the failure that matters is not "nothing
    // matched" (axe throws on that, loudly) but "one of two matched,
    // and it was the small one" — which produces a real report over a
    // fraction of the app and reads exactly like a clean one.
    const matched = await page.evaluate(
      (sels) => sels.map((s) => {
        try { return { selector: s, count: document.querySelectorAll(s).length }; }
        catch (e) { return { selector: s, count: -1, error: String(e && e.message) }; }
      }),
      includeSel
    );
    const badSel = matched.filter((m) => m.count === -1);
    if (badSel.length > 0) {
      await browser.close();
      die(
        'axe-include-selector contains a selector the browser cannot parse: ' +
        badSel.map((m) => m.selector + ' (' + m.error + ')').join(', ')
      );
    }
    console.log(
      '  scope on ' + route + ': ' +
      matched.map((m) => m.selector + '=' + m.count).join(', ') +
      ' (total ' + matched.reduce((a, m) => a + m.count, 0) + ' element(s))'
    );

    // A scope that matches NOTHING makes axe throw
    // ("No elements found for include in page Context"), which the
    // outer .catch turns into a die. Named here so the reason in the
    // log is the app's, not a stack trace from inside axe-core.
    if (matched.every((m) => m.count === 0)) {
      await browser.close();
      die(
        'route ' + url + ' matched NONE of the axe-include-selector selectors [' +
        includeSel.join(' | ') + ']. axe cannot analyse an empty scope, and a report ' +
        'written anyway would say this app has no accessibility defects because nothing ' +
        'of it was looked at. Set axe-include-selector to the element this app mounts into.'
      );
    }

    const results = await buildAxe(page).analyze();
    engine = results.testEngine;

    const nPass = (results.passes || []).length;
    const nViol = (results.violations || []).length;
    const nIncomplete = (results.incomplete || []).length;

    // Guard 3.
    if (nPass === 0 && nViol === 0) {
      await browser.close();
      die(
        'route ' + url + ' produced ZERO passes and ZERO violations inside the scope [' +
        includeSel.join(' | ') + ']. axe ran against a document with nothing in it — a ' +
        'blank page, an SPA that never mounted, or a scope that matched an empty shell. ' +
        'That is not a clean accessibility result, it is an absent one.'
      );
    }

    console.log(
      'axe ' + url + ' -> HTTP ' + status + ' | passes=' + nPass +
      ' violations=' + nViol + ' incomplete=' + nIncomplete
    );

    analysed.push({ route, url, status, passes: nPass, violations: nViol, incomplete: nIncomplete });

    for (const v of results.violations || []) {
      violations.push({
        id: v.id,
        impact: v.impact,
        help: v.help,
        helpUrl: v.helpUrl,
        tags: v.tags,
        route,
        url,
        nodes: (v.nodes || []).map(trimNode),
      });
    }

    // ── Guard 4: the scope control ───────────────────────────────
    // Runs ONCE, on the first route, AFTER that route's real result
    // has been collected — the probes below mutate the page, and a
    // report must never contain a violation this runner injected.
    //
    // Scoping and muting produce the same shape: fewer violations. So
    // both halves are asserted, on the live page, every run:
    //
    //   (a) a deliberate `button-name` (critical) injected INSIDE the
    //       scope container MUST still be reported. If it is not, the
    //       scope is not narrowing the analysis, it is suppressing it,
    //       and every green from here on is meaningless.
    //   (b) the SAME violation injected OUTSIDE every container MUST
    //       NOT be reported. If it IS, the scope is not in effect at
    //       all — the app is being blamed for core's chrome again,
    //       which is the entire defect this scope exists to fix.
    //
    // (b) is the half that distinguishes the two, and it is the half
    // that quietly stops being executed when someone sets the scope
    // to `body`. That case is not silently tolerated: it is recorded
    // as `outsideProbeExecuted: false` in the report and announced,
    // because a control that did not run must never look like one
    // that passed.
    if (scopeControl === null) {
      const placed = await page.evaluate((sels) => {
        const containers = [];
        for (const s of sels) containers.push(...document.querySelectorAll(s));
        if (containers.length === 0) return { placed: false, why: 'no scope container matched' };

        const inside = document.createElement('button');
        inside.id = 'axe-scope-control-inside';
        containers[0].appendChild(inside);

        const outside = document.createElement('button');
        outside.id = 'axe-scope-control-outside';
        document.body.appendChild(outside);

        // Only a control if it really landed outside EVERY container.
        const leaked = containers.some((c) => c.contains(outside));
        if (leaked) outside.remove();

        return { placed: true, outsideProbeExecuted: !leaked, containers: containers.length };
      }, includeSel);

      if (!placed.placed) {
        await browser.close();
        die('could not place the scope control probes: ' + placed.why + '.');
      }

      const ctl = await buildAxe(page).analyze();
      const targets = [];
      for (const v of ctl.violations || []) {
        for (const n of v.nodes || []) {
          const t = Array.isArray(n.target) ? n.target : [n.target];
          for (const one of t) targets.push(String(one));
        }
      }
      const sawInside = targets.some((t) => t.includes('axe-scope-control-inside'));
      const sawOutside = targets.some((t) => t.includes('axe-scope-control-outside'));

      await page.evaluate(() => {
        for (const id of ['axe-scope-control-inside', 'axe-scope-control-outside']) {
          const el = document.getElementById(id);
          if (el) el.remove();
        }
      });

      if (!sawInside) {
        await browser.close();
        die(
          'SCOPE CONTROL FAILED (a). A `<button></button>` injected INSIDE the scope [' +
          includeSel.join(' | ') + '] on ' + url + ' was NOT reported as a `button-name` ' +
          'violation. The scope is suppressing this app\'s own defects, not attributing ' +
          'core\'s elsewhere. Refusing to write a report.'
        );
      }
      if (placed.outsideProbeExecuted && sawOutside) {
        await browser.close();
        die(
          'SCOPE CONTROL FAILED (b). A `<button></button>` injected OUTSIDE every scope ' +
          'container on ' + url + ' WAS reported. The include scope [' + includeSel.join(' | ') +
          '] is not in effect, so Nextcloud\'s own chrome is still being counted against ' +
          'this app. Refusing to write a report.'
        );
      }

      scopeControl = {
        route,
        url,
        containers: placed.containers,
        insideProbeReported: sawInside,
        outsideProbeExecuted: placed.outsideProbeExecuted === true,
        outsideProbeReported: placed.outsideProbeExecuted === true ? sawOutside : null,
      };

      if (scopeControl.outsideProbeExecuted) {
        console.log(
          'axe scope control OK on ' + route + ': inside probe reported (scoping does not mute), ' +
          'outside probe not reported (scope is in effect), ' + placed.containers + ' container(s).'
        );
      } else {
        console.log(
          '::warning::axe scope control: only half executed on ' + route + '. The inside probe ' +
          'was reported, but the scope covers the whole document (every place the outside probe ' +
          'could go is inside a container), so nothing proves the scope excludes anything. ' +
          'This is expected when axe-include-selector is `body` — and it means this run is, ' +
          'in effect, unscoped.'
        );
      }
    }
  }

  await browser.close();

  const report = {
    // The key gate-33 reads.
    violations,
    // Everything below is provenance, so a reader — and the validator
    // in the hydra-gates job — can tell a clean run from a run that
    // never happened.
    testEngine: engine,
    generatedAt: new Date().toISOString(),
    baseUrl,
    tags: TAGS,
    routesAnalysed: analysed,
    // The scope is provenance too, and the most important kind: two
    // reports with the same empty `violations` array mean opposite
    // things depending on what was looked at. Recorded so a reader —
    // and any future validator — can tell "this app is clean" from
    // "this scope excluded the app".
    scope: { include: includeSel, exclude: excludeSel },
    scopeControl,
    selfTest: { rule: controlHit.id, impact: controlHit.impact, detected: true },
    blocking: violations.filter((v) => ['serious', 'critical'].includes(v.impact)).length,
  };

  fs.mkdirSync(path.dirname(outPath), { recursive: true });
  fs.writeFileSync(outPath, JSON.stringify(report, null, 2));

  console.log(
    'Wrote ' + outPath + ': ' + analysed.length + ' route(s), ' + violations.length +
    ' violation(s), ' + report.blocking + ' of them serious/critical (gate-33 blocking).'
  );
})().catch((err) => {
  die('crashed before writing a report: ' + (err && err.stack ? err.stack : err));
});
