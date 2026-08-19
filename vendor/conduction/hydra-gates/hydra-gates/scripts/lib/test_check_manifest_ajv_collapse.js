#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
//
// test_check_manifest_ajv_collapse.js — a finding count is not a defect count.
//
// Ajv with `allErrors: true` reports the APPLICATOR keyword alongside the leaf
// cause. The manifest schema states its `_note` rule as a NESTED if/then/else
// (nextcloud-vue#315 relaxed it so a `Cn[A-Z]\w+` component is
// self-documenting), so ONE missing `_note` on ONE page emitted THREE lines:
//
//   at /pages/0: must have required property '_note' (keyword=required)  ← real
//   at /pages/0: must match "else" schema (keyword=if)                   ← echo
//   at /pages/0: must match "then" schema (keyword=if)                   ← echo
//
// That is how a gate run reported "240 violations" for roughly 132 defects:
// 51 missing `_note` entries contributed 153 lines between them. An inflated
// count is not cosmetic — it is what makes a repo's manifest debt look
// insurmountable and stops people reading the log at all.
//
// WHAT MUST NOT HAPPEN
// --------------------
// Collapsing errors is deleting evidence unless it is provably lossless. The
// two properties below are the whole safety argument and each has a test:
//
//   * an applicator error is dropped ONLY when a concrete sibling exists at the
//     SAME instancePath — so a lone `if` failure, which really is the only
//     signal at that path, still surfaces;
//   * the set of DISTINCT (path, keyword, message) defects is unchanged, and
//     the exit code is unchanged. Fewer lines, never fewer defects.
//
// Run: node scripts/lib/test_check_manifest_ajv_collapse.js   (exit 0 = pass)

'use strict'

const { spawnSync } = require('child_process')
const fs = require('fs')
const os = require('os')
const path = require('path')

const LIB = __dirname
const VALIDATOR = path.join(LIB, 'check_manifest.js')

if (!fs.existsSync(VALIDATOR)) {
	console.log(`FAIL — the validator under test is missing at ${VALIDATOR}; this suite cannot assert anything`)
	process.exit(1)
}

const { collapseAjvErrors } = require('./check_manifest.js')

if (typeof collapseAjvErrors !== 'function') {
	console.log('FAIL — check_manifest.js does not export collapseAjvErrors; this suite cannot assert anything')
	console.log('(a missing export would make every assertion below vacuous — refusing to run)')
	process.exit(1)
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

const err = (instancePath, keyword, message) => ({ instancePath, keyword, message })

// --- unit: the collapse itself ----------------------------------------------

{
	// The exact shape Ajv emits for one missing `_note`.
	const triplet = [
		err('/pages/0', 'required', "must have required property '_note'"),
		err('/pages/0', 'if', 'must match "else" schema'),
		err('/pages/0', 'if', 'must match "then" schema'),
	]
	const out = collapseAjvErrors(triplet)
	assert(out.length === 1, `one missing _note collapses 3 findings to 1 (got ${out.length})`)
	assert(out.length === 1 && out[0].keyword === 'required',
		'the surviving line is the ACTIONABLE one (required), not the applicator echo')
}

{
	// THE LOSSLESSNESS CONTROL. A lone applicator failure is the only signal at
	// its path, so it must survive. If this ever goes red the collapse has
	// started deleting evidence, which is the bug it exists to prevent.
	const lone = [err('/pages/0', 'if', 'must match "else" schema')]
	assert(collapseAjvErrors(lone).length === 1,
		'a lone applicator error with NO concrete sibling at its path is KEPT')
}

{
	const mixed = [
		err('/pages/0', 'required', "must have required property '_note'"),
		err('/pages/1', 'if', 'must match "else" schema'),
	]
	assert(collapseAjvErrors(mixed).length === 2,
		'an applicator error at a DIFFERENT path than the concrete error is KEPT')
}

{
	// The merged AppHost schema can assert additionalProperties twice over the
	// same node, emitting the identical line twice.
	const dupes = [
		err('/', 'additionalProperties', 'must NOT have additional properties'),
		err('/', 'additionalProperties', 'must NOT have additional properties'),
		err('/', 'required', "must have required property 'version'"),
	]
	assert(collapseAjvErrors(dupes).length === 2, 'exact duplicate findings are collapsed')
}

{
	// Distinct defects at the same path are all real and all survive.
	const distinct = [
		err('/', 'required', "must have required property '$schema'"),
		err('/', 'required', "must have required property 'version'"),
		err('/', 'additionalProperties', 'must NOT have additional properties'),
	]
	assert(collapseAjvErrors(distinct).length === 3,
		'three DISTINCT defects at the same path all survive — dedupe is by content, not by path')
}

{
	assert(collapseAjvErrors([]).length === 0, 'an empty error list stays empty')
}

{
	// ANTI-WIDENING: the collapse must never turn a failing manifest into a
	// clean one. Any non-empty input with at least one concrete error must
	// yield at least one finding.
	const cases = [
		[err('/a', 'required', 'x')],
		[err('/a', 'if', 'y')],
		[err('/a', 'required', 'x'), err('/a', 'if', 'y'), err('/b', 'type', 'z')],
	]
	assert(cases.every((c) => collapseAjvErrors(c).length >= 1),
		'no non-empty error list collapses to zero findings — fewer lines, never fewer defects')
}

// --- end-to-end: the real validator on a real manifest -----------------------
//
// Requires Ajv; the structural-lint fallback does not run the schema at all.
// Skips with a notice exactly as the sibling suites do, rather than passing
// vacuously — "zero errors" and "nothing was validated" must not look alike.
{
	let ajvOk = true
	try { require.resolve('ajv/dist/2020') } catch (e) { ajvOk = false }
	if (!ajvOk) {
		console.log('SKIP — end-to-end: Ajv not resolvable (set NODE_PATH); the collapse is unit-covered above')
	} else {
		const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'gate22-collapse-'))
		const mf = path.join(tmp, 'manifest.json')
		fs.writeFileSync(mf, JSON.stringify({
			schemaVersion: '2.0',
			app: { id: 'trip', name: 'Triplet' },
			menu: [],
			pages: [
				{ id: 'A', route: 'A', type: 'custom', title: 'A', component: 'HostThing', config: {} },
				{ id: 'B', route: 'B', type: 'custom', title: 'B', component: 'OtherThing', config: {} },
			],
		}, null, 1))

		const r = spawnSync(process.execPath, [VALIDATOR, mf], { encoding: 'utf8' })
		const line = (r.stdout || '').trim().split('\n').map((l) => {
			try { return JSON.parse(l) } catch (e) { return {} }
		}).find((o) => Array.isArray(o.errors))
		const errors = line ? line.errors : []
		const noteErrors = errors.filter((e) => e.message.includes('_note'))
		const applicator = errors.filter((e) => e.message.includes('keyword=if'))

		assert(noteErrors.length === 2,
			`two pages missing _note produce exactly TWO findings, one each (got ${noteErrors.length})`)
		assert(applicator.length === 0,
			`zero "keyword=if" applicator echoes remain (got ${applicator.length})`)
		assert(r.status === 1,
			'the manifest still FAILS — collapsing lines must not change the verdict')

		// And the converse: supply the _note and the finding goes away entirely.
		const fixed = path.join(tmp, 'fixed.json')
		const m = JSON.parse(fs.readFileSync(mf, 'utf8'))
		for (const p of m.pages) p._note = 'Documented reason a standard page type was not feasible.'
		fs.writeFileSync(fixed, JSON.stringify(m, null, 1))
		const r2 = spawnSync(process.execPath, [VALIDATOR, fixed], { encoding: 'utf8' })
		const line2 = (r2.stdout || '').trim().split('\n').map((l) => {
			try { return JSON.parse(l) } catch (e) { return {} }
		}).find((o) => Array.isArray(o.errors))
		const note2 = (line2 ? line2.errors : []).filter((e) => e.message.includes('_note'))
		assert(note2.length === 0,
			'adding the _note clears the finding — the gate still measures the real rule')

		fs.rmSync(tmp, { recursive: true, force: true })
	}
}

console.log('')
if (fails === 0) {
	console.log('ALL gate-22 Ajv error-collapse assertions PASSED')
	process.exit(0)
}
console.log(`${fails} gate-22 Ajv error-collapse assertion(s) FAILED`)
process.exit(1)
