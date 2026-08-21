#!/usr/bin/env node
/* eslint-disable n/no-process-exit */
/* eslint-disable no-console */
/* eslint-disable n/shebang */
/**
 * Prove that test:l10n:parity actually FAILS when a locale loses a key.
 *
 *   node scripts/l10n/gate-negative-test.js <loc> [key]
 *
 * A gate nobody has seen fail is not known to work, so this breaks one bundle on
 * purpose and asserts the gate notices and names it. Run it after any change to the
 * gate itself, and on any locale whose parity you are relying on.
 *
 * ## Why this exists as a committed script
 *
 * The obvious way to run this test by hand is: delete a key, run the gate, then
 * `git checkout -- l10n/<loc>.js` to undo. That last step is a trap. During a locale
 * pass the bundle is UNCOMMITTED for hours, so `git checkout` does not undo the
 * deletion — it reverts to pre-pass HEAD and silently discards the entire pass. That
 * happened, and cost a full re-apply of 999 values.
 *
 * So this script owns the whole cycle: it snapshots the file, mutates it, runs the
 * gate, restores from its own snapshot, and asserts the restore is byte-identical.
 * There is no window in which a human has to remember to put the file back, and no
 * reason to reach for git.
 *
 * It leaves the bundle exactly as it found it, including on failure.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

'use strict'

const fs = require('fs')
const os = require('os')
const path = require('path')
const { execFileSync } = require('child_process')
const { loadJsTranslations, serializeJs, APP_ROOT } = require('./lib.js')

const loc = process.argv[2]
if (!loc) {
	console.error('usage: gate-negative-test.js <loc> [key]')
	process.exit(2)
}

const locFile = path.join(APP_ROOT, 'l10n', `${loc}.js`)
if (!fs.existsSync(locFile)) {
	console.error(`no such bundle: ${locFile}`)
	process.exit(2)
}

/**
 * Run the parity gate once and return both its exit code and its combined output.
 *
 * BOTH streams are concatenated on purpose: the gate prints its summary to stdout but
 * the per-locale failure detail to stderr, so a check that reads only stdout sees the
 * exit code change and none of the reason. That is how the first version of this
 * script reported a false negative.
 *
 * @return {{code: number, out: string}} Exit code and stdout+stderr.
 */
function runGate() {
	try {
		const out = execFileSync('node', [path.join(APP_ROOT, 'tests/l10n/check-l10n-parity.js')],
			{ cwd: APP_ROOT, stdio: 'pipe' })
		return { code: 0, out: String(out) }
	} catch (e) {
		return {
			code: e.status === undefined ? 1 : e.status,
			out: String(e.stdout || '') + String(e.stderr || ''),
		}
	}
}

const original = fs.readFileSync(locFile)
const snapshot = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'l10n-gate-')), `${loc}.js`)
fs.writeFileSync(snapshot, original)

let failures = 0
/**
 * Report one assertion.
 *
 * @param {string} name What was asserted.
 * @param {boolean} ok Whether it held.
 * @param {string} [detail] Extra context for the line.
 */
function check(name, ok, detail) {
	console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? ' — ' + detail : ''}`)
	if (!ok) {
		failures++
	}
}

try {
	// 0. The gate must be green to begin with, or the rest proves nothing: a gate
	// that was already red would "fail with a key missing" for an unrelated reason.
	check('gate is green before the test', runGate().code === 0)

	const cur = loadJsTranslations(locFile)
	const victim = process.argv[3] || 'Save'
	if (!(victim in cur.translations)) {
		// A KEY, not a value. Passing a translated value here is an easy slip and
		// would otherwise delete nothing and silently "pass".
		throw new Error(`no such KEY in ${loc}.js (did you pass a value?): ${JSON.stringify(victim)}`)
	}

	const before = Object.keys(cur.translations).length
	delete cur.translations[victim]
	fs.writeFileSync(locFile, serializeJs({
		app: cur.app,
		translations: cur.translations,
		pluralForm: cur.pluralForm,
	}))
	console.log(`      removed key ${JSON.stringify(victim)} — ${before} -> ${before - 1} keys`)

	// 1. The gate must now fail, AND name this locale. The exit code alone is not
	// enough: any other locale regressing would also make it non-zero, so a green
	// exit code is necessary but the named line is what ties the failure to us.
	const { code, out } = runGate()
	check(`gate FAILS with a key missing from ${loc}`, code !== 0, `exit ${code}`)
	// The failure must NAME the locale, not just exit non-zero: a gate that fails
	// without saying which bundle broke sends the reader through 36 files.
	check(`the failure names ${loc}`,
		out.includes(`(.js) ${loc}:`))
} catch (e) {
	check('test ran', false, e.message)
} finally {
	// Always restore, including on a thrown assertion. cp semantics, never git.
	fs.writeFileSync(locFile, original)
	check('bundle restored byte-identical',
		Buffer.compare(fs.readFileSync(locFile), original) === 0)
	fs.rmSync(path.dirname(snapshot), { recursive: true, force: true })
}

// 2. And it must be green again, which also proves the restore was complete.
check('gate is green again after the restore', runGate().code === 0)

// ------------------------------------------------------------- the {plural} ban
//
// `{plural}` is banned in four places (MORPHOLOGY_PLACEHOLDER in lib.js), and a
// ban nobody has watched refuse something is exactly the kind of gate this script
// exists to distrust. The five source-hack keys it used to describe were removed
// when the call sites became real n() calls, so there is no longer any live
// example in the tree to reason from — which makes proving it by injection the
// only evidence available.
//
// Each phase mutates ONE thing, asserts the specific gate refuses it AND says
// enough for a reader to act, then restores from its own snapshot. As above:
// never git, because during a locale pass the bundle is uncommitted for hours.

/**
 * Run a node script from the app root and return its exit code plus both streams.
 *
 * @param {string[]} argv Script path (relative to APP_ROOT) and its arguments.
 * @return {{code: number, out: string}} Exit code and stdout+stderr.
 */
function run(argv) {
	try {
		const out = execFileSync('node', [path.join(APP_ROOT, argv[0]), ...argv.slice(1)],
			{ cwd: APP_ROOT, stdio: 'pipe' })
		return { code: 0, out: String(out) }
	} catch (e) {
		return {
			code: e.status === undefined ? 1 : e.status,
			out: String(e.stdout || '') + String(e.stderr || ''),
		}
	}
}

console.log('\n--- the {plural} ban')

// Phase A — a poisoned VALUE. Covers check-l10n-parity.js (CI) and selfcheck.js.
try {
	const cur = loadJsTranslations(locFile)
	const victim = Object.keys(cur.translations).find((k) => typeof cur.translations[k] === 'string')
	cur.translations[victim] = `${cur.translations[victim]}{plural}`
	fs.writeFileSync(locFile, serializeJs({
		app: cur.app, translations: cur.translations, pluralForm: cur.pluralForm,
	}))
	console.log(`      poisoned value of ${JSON.stringify(victim)} with {plural}`)

	const parity = runGate()
	check('parity gate FAILS on {plural} in a value', parity.code !== 0, `exit ${parity.code}`)
	check('parity names the locale and the key',
		parity.out.includes(`(.js) ${loc}:`) && parity.out.includes('{plural}'))

	// selfcheck is the fast local mirror. It reports per-check lines rather than
	// exiting on the first problem, so assert on the named check, not just the code.
	const self = run(['scripts/l10n/selfcheck.js', loc])
	check('selfcheck FAILS on {plural} in a value', self.code !== 0, `exit ${self.code}`)
	check('selfcheck names the {plural} check',
		/FAIL\s+no \{plural\} in any value/.test(self.out))
} catch (e) {
	check('poisoned-value phase ran', false, e.message)
} finally {
	fs.writeFileSync(locFile, original)
	check('bundle restored byte-identical after the value phase',
		Buffer.compare(fs.readFileSync(locFile), original) === 0)
}

// Phase B — a poisoned CALL SITE. Covers tests/l10n/check-l10n.js (CI).
//
// Both spellings are injected, because they fail for different reasons: the key
// form is caught by reading the extracted key, the ternary form only by reading
// the call's argument span. A guard that caught one and not the other would look
// identical from the outside.
const probe = path.join(APP_ROOT, 'src', '__gate_negative_probe__.vue')
for (const [shape, body] of [
	['{plural} in the key', "t('openregister', 'widget{plural}', { plural: count !== 1 ? 's' : '' })"],
	["? 's' : '' in the arguments", "t('openregister', 'widget', { suffix: count !== 1 ? 's' : '' })"],
]) {
	try {
		fs.writeFileSync(probe, `<script setup>\nconst x = ${body}\n</script>\n`)
		const res = run(['tests/l10n/check-l10n.js'])
		check(`check-l10n FAILS on ${shape}`, res.code !== 0, `exit ${res.code}`)
		check(`check-l10n names the probe file for ${shape}`,
			res.out.includes('__gate_negative_probe__.vue')
			&& res.out.includes('build English morphology in JS'))
		// --write must refuse rather than extracting the defect into en.js.
		const enBefore = fs.readFileSync(path.join(APP_ROOT, 'l10n', 'en.js'))
		run(['tests/l10n/check-l10n.js', '--write'])
		check(`--write does not extract ${shape} into en.js`,
			Buffer.compare(fs.readFileSync(path.join(APP_ROOT, 'l10n', 'en.js')), enBefore) === 0)
	} catch (e) {
		check(`call-site phase (${shape}) ran`, false, e.message)
	} finally {
		fs.rmSync(probe, { force: true })
	}
}
check('probe file removed', !fs.existsSync(probe))

// Phase C — a poisoned PATCH. Covers apply.js, the only writer into l10n/*.js.
// apply.js refuses a patch whole rather than landing part of it, so a dry run is
// enough here and nothing needs restoring.
try {
	const tmp = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'l10n-gate-')), 'patch.json')
	const cur = loadJsTranslations(locFile)
	const victim = Object.keys(cur.translations).find((k) => typeof cur.translations[k] === 'string')
	fs.writeFileSync(tmp, JSON.stringify({ [victim]: 'iets{plural}' }))
	const res = run(['scripts/l10n/apply.js', loc, tmp])
	check('apply.js REFUSES a patch carrying {plural}', res.code !== 0, `exit ${res.code}`)
	check('apply.js says {plural} is banned', /\{plural\} is banned/.test(res.out))
	fs.rmSync(path.dirname(tmp), { recursive: true, force: true })
} catch (e) {
	check('poisoned-patch phase ran', false, e.message)
}

// Phase D — a plural form that is ALSO a key. The defect translatePlural's
// re-translation causes; see the collision check in check-l10n-parity.js. Proved
// by injection for the same reason as the ban: there is deliberately no live
// example left in the tree.
try {
	const cur = loadJsTranslations(locFile)
	const arrayKey = Object.keys(cur.translations).find((k) => Array.isArray(cur.translations[k]))
	// Borrow a real key whose value differs from itself, so the substitution is
	// observable rather than a no-op.
	const donor = Object.keys(cur.translations).find((k) => typeof cur.translations[k] === 'string'
		&& cur.translations[k] !== k && k.length > 3)
	const forms = [...cur.translations[arrayKey]]
	forms[0] = donor
	cur.translations[arrayKey] = forms
	fs.writeFileSync(locFile, serializeJs({
		app: cur.app, translations: cur.translations, pluralForm: cur.pluralForm,
	}))
	console.log(`      set ${JSON.stringify(arrayKey)} form 0 to ${JSON.stringify(donor)}, `
		+ 'which is itself a key')

	const res = runGate()
	check('parity gate FAILS on a plural form that is also a key', res.code !== 0, `exit ${res.code}`)
	check('parity explains the collision', res.out.includes('which is also a key in this bundle'))
} catch (e) {
	check('collision phase ran', false, e.message)
} finally {
	fs.writeFileSync(locFile, original)
	check('bundle restored byte-identical after the collision phase',
		Buffer.compare(fs.readFileSync(locFile), original) === 0)
}

// Everything above mutated something. Prove the tree is clean again.
check('parity gate is green after every phase', runGate().code === 0)

console.log(failures === 0
	? `\n${loc}: the parity gate genuinely holds this locale, and the {plural} ban genuinely refuses`
	: `\n${failures} CHECK(S) FAILED — the gate may not be holding ${loc}`)
process.exitCode = failures ? 1 : 0
