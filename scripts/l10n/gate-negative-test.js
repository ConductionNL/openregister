#!/usr/bin/env node
/* eslint-disable n/no-process-exit */
/* eslint-disable no-console */
/* eslint-disable n/shebang */
/**
 * Prove that test:l10n:parity actually FAILS when a finished locale loses a key.
 *
 *   node scripts/l10n/gate-negative-test.js <loc> [key]
 *
 * Adding a locale to FINISHED_DEFAULT is a claim that the gate now holds it. This
 * checks the claim instead of assuming it, and it is the last step of every locale
 * pass. A gate nobody has seen fail is not known to work.
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
	check(`the failure names ${loc} as declared FINISHED`,
		out.includes(`(.js) ${loc} (declared FINISHED)`))
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

console.log(failures === 0
	? `\n${loc}: the parity gate genuinely holds this locale`
	: `\n${failures} CHECK(S) FAILED — the gate may not be holding ${loc}`)
process.exitCode = failures ? 1 : 0
