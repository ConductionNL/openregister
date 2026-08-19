#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
//
// test_check_manifest_crossref.js — gate-30 (effective-manifest-crossref)
// fixture-based self-test.
//
// Proves the gate contract over scripts/test-fixtures/effective-manifest/:
//   good/   → assembles, structurally validates (when Ajv is resolvable),
//             and cross-resolves: checker exit 0, summary "passed", exactly
//             ONE warn-severity finding (the open-modal registry WARN) and
//             ZERO error findings — warnings never set the exit code.
//   broken/ → checker exit 1, summary "failed", EXACTLY one error finding
//             per seeded defect class (menu-route, action-target open-page,
//             slug-resolution zaakafhandelapp-shape, deeplink-route,
//             removals-invariant) — none missed, none extra — plus the
//             open-modal WARN; and the ASSEMBLED manifest fails
//             check_manifest.js on the fragment-introduced `layout[]`
//             violation (structural stage, Ajv path only).
//
// THE FIXTURES ARE PART OF THIS TEST. Until 2026-08-04 this file referenced
// ../test-fixtures/effective-manifest/{good,broken}/ — a directory that had
// never existed in this repository, and nothing in CI ran the file, so nobody
// found out. Its sibling test_check_manifest.sh had the same defect and was
// WORSE: a missing manifest path makes the validator print "Tier 0, skipping"
// and exit 0, which is exactly what two of its three assertions expected, so
// it reported GREEN for its whole life while inspecting nothing.
//
// This file failed loudly instead (13 of 21 assertions red) — but four of the
// eight it "passed" it passed for the wrong reason: with no fixture, the
// checker found zero findings, and "zero errors" is indistinguishable from
// "nothing was ever loaded". The guard below refuses to run at all rather than
// let that shape recur.
//
// Run: node scripts/lib/test_check_manifest_crossref.js   (exit 0 = pass)

'use strict'

const { spawnSync } = require('child_process')
const fs = require('fs')
const os = require('os')
const path = require('path')

const LIB = __dirname
const FIX = path.resolve(LIB, '..', 'test-fixtures', 'effective-manifest')
const BUILDER = path.join(LIB, 'build_effective_manifest.js')
const CHECKER = path.join(LIB, 'check_manifest_crossref.js')
const VALIDATOR = path.join(LIB, 'check_manifest.js')

// --- guard the guard --------------------------------------------------------
// If the fixtures go missing, say so and stop. A suite whose inputs are absent
// cannot assert anything, and several of the assertions below would otherwise
// be satisfied by the empty result an absent fixture produces.
{
	const required = [
		'good/src/manifest.json',
		'good/src/manifest.d/10-archive.json',
		'good/src/manifest.d/20-settings.json',
		'good/src/menu-layout.json',
		'good/lib/Settings/items-register.json',
		'broken/src/manifest.json',
		'broken/src/manifest.d/10-besluiten.json',
		'broken/src/menu-layout.json',
		'broken/lib/Settings/zaken-register.json',
		'registry-wired/src/manifest.json',
		'registry-wired/src/registry.js',
		'registry-orphan/src/manifest.json',
		'registry-orphan/src/registry.js',
		'registry-missing/src/manifest.json',
		'registry-missing/src/registry.js',
		'registry-dialects/src/manifest.json',
		'registry-dialects/src/registry.js',
		'registry-dialects/src/customComponents.js',
	]
	const missing = required.filter((rel) => !fs.existsSync(path.join(FIX, rel)))
	if (missing.length > 0) {
		console.log(`FAIL — ${missing.length} gate-30 fixture file(s) MISSING under ${FIX}; this suite cannot assert anything:`)
		for (const rel of missing) console.log(`    ${rel}`)
		console.log('')
		console.log('Refusing to run. Without the fixtures the checker reports zero findings,')
		console.log('and "zero errors" would satisfy several assertions below while inspecting')
		console.log('nothing at all — the exact defect this gate exists to catch, one level down.')
		process.exit(1)
	}
	// The helpers under test must also be present; requiring them via spawn
	// would otherwise surface as a confusing non-zero exit rather than a cause.
	for (const [label, p] of [['builder', BUILDER], ['checker', CHECKER], ['validator', VALIDATOR]]) {
		if (!fs.existsSync(p)) {
			console.log(`FAIL — the ${label} under test is missing at ${p}; this suite cannot assert anything`)
			process.exit(1)
		}
	}
}

let fails = 0
function assert(cond, label) {
	if (cond) {
		console.log(`PASS — ${label}`)
	} else {
		console.log(`FAIL — ${label}`)
		fails++
	}
}

// Run a node helper, returning { status, stdout, stderr } without throwing.
function run(args) {
	const r = spawnSync(process.execPath, args, { encoding: 'utf8' })
	return { status: r.status === null ? -1 : r.status, stdout: r.stdout || '', stderr: r.stderr || '' }
}

// Parse the checker stdout: every line must be valid JSON; last line is the
// summary; an optional preceding line carries the findings.
function parseReport(stdout) {
	const lines = stdout.trim().split('\n').filter((l) => l !== '')
	const parsed = lines.map((l) => {
		try { return JSON.parse(l) } catch (e) { return { __invalid: l } }
	})
	const invalid = parsed.filter((p) => p.__invalid)
	const summary = parsed[parsed.length - 1]
	const findingsLine = parsed.find((p) => Array.isArray(p.findings))
	return { invalid, summary, findings: findingsLine ? findingsLine.findings : [] }
}

// --- good fixture ------------------------------------------------------------
{
	const tmp = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'gate30-test-')), 'good-effective.json')
	const build = run([BUILDER, '--app-dir', path.join(FIX, 'good'), '--out', tmp])
	assert(build.status === 0, 'good: effective manifest assembles (builder exit 0)')

	const check = run([CHECKER, '--app-dir', path.join(FIX, 'good'), '--manifest', tmp])
	const rep = parseReport(check.stdout)
	assert(check.status === 0, 'good: checker exits 0')
	assert(rep.invalid.length === 0, 'good: every stdout line is valid JSON')
	assert(rep.summary && rep.summary.status === 'passed' && rep.summary.checked === 1 && rep.summary.failed === 0,
		'good: summary line is {"status":"passed","checked":1,"failed":0}')
	const errors = rep.findings.filter((f) => f.severity === 'error')
	const warns = rep.findings.filter((f) => f.severity === 'warn')
	assert(errors.length === 0, 'good: zero error findings')
	assert(warns.length === 1 && warns[0].check === 'action-target', 'good: exactly one WARN (open-modal registry not statically checkable)')
	assert(/^at .*: WARN /m.test(check.stderr), 'good: WARN reported as "at <path>: WARN …" on stderr')
	fs.rmSync(path.dirname(tmp), { recursive: true, force: true })
}

// --- broken fixture ------------------------------------------------------------
{
	const tmp = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'gate30-test-')), 'broken-effective.json')
	const build = run([BUILDER, '--app-dir', path.join(FIX, 'broken'), '--out', tmp])
	assert(build.status === 0, 'broken: effective manifest still assembles (defects are semantic, not merge failures)')

	const check = run([CHECKER, '--app-dir', path.join(FIX, 'broken'), '--manifest', tmp])
	const rep = parseReport(check.stdout)
	assert(check.status === 1, 'broken: checker exits 1')
	assert(rep.invalid.length === 0, 'broken: every stdout line is valid JSON')
	assert(rep.summary && rep.summary.status === 'failed' && rep.summary.checked === 1 && rep.summary.failed === 1,
		'broken: summary line is {"status":"failed","checked":1,"failed":1}')

	const errors = rep.findings.filter((f) => f.severity === 'error')
	const warns = rep.findings.filter((f) => f.severity === 'warn')
	const byCheck = (name) => errors.filter((f) => f.check === name)

	assert(byCheck('menu-route').length === 1
		&& byCheck('menu-route')[0].message.includes("'cases-overview'"),
	'broken: exactly one menu-route error (dangling route cases-overview)')
	assert(byCheck('action-target').length === 1
		&& byCheck('action-target')[0].message.includes("'missing-page'"),
	'broken: exactly one action-target error (open-page → missing-page)')
	assert(byCheck('slug-resolution').length === 1
		&& byCheck('slug-resolution')[0].message.includes("'besluit'")
		&& byCheck('slug-resolution')[0].message.includes("'zaak-besluiten'"),
	'broken: exactly one slug-resolution error naming the widget and the missing schema (zaakafhandelapp shape)')
	assert(byCheck('deeplink-route').length === 1
		&& byCheck('deeplink-route')[0].message.includes('/besluiten/{id}'),
	'broken: exactly one deeplink-route error (unroutable /besluiten prefix)')
	assert(byCheck('removals-invariant').length === 1
		&& byCheck('removals-invariant')[0].message.includes("'cases-index'"),
	'broken: exactly one removals-invariant error (orphaned route cases-index, ADR-044)')
	assert(errors.length === 5, `broken: exactly 5 error findings — none missed, none extra (got ${errors.length})`)
	assert(warns.length === 1 && warns[0].check === 'action-target', 'broken: the open-modal WARN present, warn severity')
	fs.rmSync(path.dirname(tmp), { recursive: true, force: true })
}

// --- structural stage on the ASSEMBLED broken manifest (Ajv path) ---------------
// The fragment-introduced `layout[]` page property is invisible to the base
// gate-22 run and to the crossref checker; it must fail check_manifest.js on
// the assembled manifest. Requires Ajv (the structural-lint fallback does not
// re-check page rules) — skip with a notice when Ajv is unresolvable, exactly
// as test_check_manifest.sh skips its no-Ajv leg.
{
	const ajvProbe = run(['-e', "require('ajv/dist/2020')"])
	if (ajvProbe.status !== 0) {
		console.log('SKIP — structural stage: Ajv not resolvable (set NODE_PATH); gate-30 itself fails closed in this state')
	} else {
		const tmpDir = fs.mkdtempSync(path.join(os.tmpdir(), 'gate30-test-'))
		const goodTmp = path.join(tmpDir, 'good-effective.json')
		const brokenTmp = path.join(tmpDir, 'broken-effective.json')
		run([BUILDER, '--app-dir', path.join(FIX, 'good'), '--out', goodTmp])
		run([BUILDER, '--app-dir', path.join(FIX, 'broken'), '--out', brokenTmp])
		const goodVal = run([VALIDATOR, goodTmp])
		assert(goodVal.status === 0, 'good: ASSEMBLED manifest passes canonical validation (check_manifest.js exit 0)')
		const brokenVal = run([VALIDATOR, brokenTmp])
		assert(brokenVal.status === 1, 'broken: ASSEMBLED manifest fails canonical validation (fragment-introduced layout[] violation)')
		assert(/additionalProperties/.test(brokenVal.stderr + brokenVal.stdout),
			'broken: the structural failure is the additionalProperties (layout) violation')
		fs.rmSync(tmpDir, { recursive: true, force: true })
	}
}

// --- (f) component-registry cross-reference (larpingapp#286) -----------------
//
// The acceptance test for ConductionNL/.github#238. Both directions, each with
// its opposite as the control: a gate that only ever fires is as useless as one
// that never does, so `registry-wired` must stay silent while the other two
// speak.
{
	const REG = (name) => path.join(FIX, name)

	// registry-wired — everything registered is positioned. Silence expected.
	{
		const check = run([CHECKER, '--app-dir', REG('registry-wired')])
		const rep = parseReport(check.stdout)
		const rx = rep.findings.filter((f) => f.check === 'registry-crossref')
		assert(rx.length === 0, `registry-wired: zero registry-crossref findings (got ${rx.length})`)
		assert(check.status === 0, 'registry-wired: checker exits 0')
	}

	// DIRECTION 1 — registered, positioned by nothing. larpingapp#286 as shipped.
	{
		const check = run([CHECKER, '--app-dir', REG('registry-orphan')])
		const rep = parseReport(check.stdout)
		const rx = rep.findings.filter((f) => f.check === 'registry-crossref')
		assert(rx.length === 1 && rx[0].message.includes("'EventRoster'"),
			`registry-orphan: exactly one registry-crossref finding naming EventRoster (got ${rx.length})`)
		assert(rx.length === 1 && rx[0].severity === 'warn',
			'registry-orphan: DIRECTION 1 is a WARN — an orphan is either wired or deleted and the gate cannot know which')
		assert(check.status === 0,
			'registry-orphan: a warn does not set the exit code')
	}

	// DIRECTION 2 — positioned, registered by nothing. Renders a blank tab.
	{
		const check = run([CHECKER, '--app-dir', REG('registry-missing')])
		const rep = parseReport(check.stdout)
		const errs = rep.findings.filter((f) => f.check === 'registry-crossref' && f.severity === 'error')
		assert(errs.length === 1 && errs[0].message.includes("'ThisComponentDoesNotExistAnywhere'"),
			`registry-missing: exactly one registry-crossref ERROR naming the unresolvable component (got ${errs.length})`)
		assert(errs.length === 1 && errs[0].path === '/pages/0/config/sidebar/tabs/0/component',
			'registry-missing: the error points at the exact manifest position, not just the page')
		assert(check.status === 1,
			'registry-missing: DIRECTION 2 sets the exit code — a component that resolves to nothing renders nothing')
	}

	// THE FALSE-POSITIVE CONTROLS. Each of these, if it regressed, would fail
	// every well-formed manifest in the fleet — the widening that would make
	// this check useless on arrival rather than after a slow drift.
	{
		const check = run([CHECKER, '--app-dir', REG('registry-wired')])
		const rep = parseReport(check.stdout)
		const msgs = rep.findings.map((f) => f.message).join(' | ')
		assert(!msgs.includes('CnSearchPage'),
			'control: a Cn* lib component is NOT reported unresolved — it resolves from nextcloud-vue, not the app registry')
		assert(!msgs.includes('ConfirmDialog'),
			"control: a kind:'modal' entry is NOT reported orphaned — open-modal targets are runtime-resolved and gate (b) already warns")
		assert(!msgs.includes('featureFlags'),
			'control: a metadata-only registry entry with no kind is NOT reported orphaned')
	}

	// The parser must not count a COMMENTED-OUT registration. A commented-out
	// prelude counting as a prelude was a real false-GREEN in gate-64; here it
	// would let a deleted component vouch for a manifest reference that
	// resolves to nothing at runtime.
	{
		const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'gate30-reg-'))
		fs.mkdirSync(path.join(tmp, 'src'), { recursive: true })
		fs.copyFileSync(path.join(REG('registry-wired'), 'src', 'manifest.json'),
			path.join(tmp, 'src', 'manifest.json'))
		fs.writeFileSync(path.join(tmp, 'src', 'registry.js'),
			'export default {\n' +
			'\t// EventRoster: { kind: "section", component: EventRoster },\n' +
			'\t/* SkillTree: { kind: "page", component: SkillTree }, */\n' +
			'}\n')
		const check = run([CHECKER, '--app-dir', tmp])
		const errs = parseReport(check.stdout).findings
			.filter((f) => f.check === 'registry-crossref' && f.severity === 'error')
		assert(errs.length === 2
			&& errs.some((e) => e.message.includes("'EventRoster'"))
			&& errs.some((e) => e.message.includes("'SkillTree'")),
		`commented-out registrations do NOT count as registrations (expected 2 errors, got ${errs.length})`)
		fs.rmSync(tmp, { recursive: true, force: true })
	}

	// THE TWO REGISTRATION DIALECTS. Both of these produced FALSE FAILs on
	// live repos after the first version of check (f) landed, because the
	// blast radius was measured on five repos and none of them used either
	// dialect. 34 false FAILs across hermiq and softwarecatalog.
	{
		const check = run([CHECKER, '--app-dir', REG('registry-dialects')])
		const rep = parseReport(check.stdout)
		const rx = rep.findings.filter((f) => f.check === 'registry-crossref')
		const errs = rx.filter((f) => f.severity === 'error')
		const msgs = rx.map((f) => f.message).join(' | ')

		assert(!msgs.includes("'agent-form'"),
			"a QUOTED HYPHENATED registry key resolves — 'agent-form' (hermiq's dialect, 25 false FAILs)")
		assert(!msgs.includes("'agent-skills'"),
			"a double-quoted hyphenated key resolves too — \"agent-skills\"")
		assert(!msgs.includes("'LegacyOnlyPanel'"),
			'a component registered ONLY in src/customComponents.js resolves — the second source (9 false FAILs on softwarecatalog)')
		assert(errs.length === 1 && errs[0].message.includes("'NotAnywherePanel'"),
			`THE CONTROL: a component in NEITHER file still FAILS (got ${errs.length} error(s))`)
		assert(check.status === 1,
			'registry-dialects: the one genuine miss still sets the exit code')
	}

	// No src/registry.js at all → check (f) is simply not applicable. The
	// `good` fixture has none, so this also pins that the existing assertions
	// above were not silently altered by adding this check.
	{
		const check = run([CHECKER, '--app-dir', path.join(FIX, 'good')])
		const rx = parseReport(check.stdout).findings.filter((f) => f.check === 'registry-crossref')
		assert(rx.length === 0, 'no src/registry.js → check (f) not applicable, zero findings')
	}
}

console.log('')
if (fails === 0) {
	console.log('ALL gate-30 effective-manifest-crossref assertions PASSED')
	process.exit(0)
}
console.log(`${fails} gate-30 assertion(s) FAILED`)
process.exit(1)
