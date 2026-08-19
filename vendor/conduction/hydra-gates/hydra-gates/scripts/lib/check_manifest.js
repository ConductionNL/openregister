#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
//
// check_manifest.js — Gate-22 manifest validator.
//
// Validates an app's src/manifest.json with the AppHost blocks (`observability`,
// `deepLinks`, ADR-040) checked FOR-REAL against the CANONICAL, hydra-vendored
// schema definitions (scripts/schemas/app-manifest-v2.schema.json) — regardless
// of the app's pinned @conduction/nextcloud-vue version.
//
// Why (mirrors how OpenRegister vendors its own schema in
// tests/validate-manifest.js): AppHost adopters add an `observability` block
// (+ some a `deepLinks` block) to src/manifest.json. The PUBLISHED
// @conduction/nextcloud-vue (beta.111) PREDATES the `observability` schema — it
// only lives on nextcloud-vue `development` (PR #21). The pinned-lib schema is
// `additionalProperties:false` at the top level and lacks `observability` /
// `deepLinks`, so the old gate either rejected the blocks outright or — via a
// broken `require('@conduction/nextcloud-vue/utils/validateManifest')` subpath
// that never resolved — fell straight into a catch → exit(0), i.e. fail-OPEN.
// Either way the AppHost blocks were never validated.
//
// Strategy — MERGE the canonical AppHost definitions into the schema the gate
// loads, then validate ADDITIVELY (a strict superset):
//   1. Resolve a BASE schema (first hit wins):
//        a. the hydra-vendored CANONICAL schema (always present — since the
//           2026-07-06 audit this wins, so "pass" means canonically valid,
//           not "valid against whatever generation the app has installed"),
//        b. the app's own pinned node_modules copy (fallback only),
//        c. a sibling ../nextcloud-vue working tree (fallback only).
//   2. MERGE the canonical `observability` + `deepLink` $defs and the top-level
//      `observability` / `deepLinks` properties from the vendored schema into
//      the base. observability/deepLinks become OPTIONAL top-level properties,
//      so a manifest valid under the base stays valid; a malformed AppHost block
//      now FAILS for-real (closed enums: health check types, metric source
//      kinds, gauge/counter; deepLink required keys).
//   3. Validate the merged schema with Ajv (draft 2020-12). When Ajv is
//      unavailable, fall back to a structural lint that STILL validates the
//      AppHost blocks for-real against the ADR-040 closed enums.
//
// Usage:   node scripts/lib/check_manifest.js [path/to/manifest.json]
//          (defaults to ./src/manifest.json relative to CWD — the app repo root)
//
// Exit codes:
//   0 — manifest validates with zero errors (or no manifest → caller skips)
//   1 — manifest fails validation (errors printed one per line: "at <path>: …")
//   2 — vendored canonical schema could not be loaded (gate misconfiguration)
//   3 — DEGRADED: Ajv was not resolvable (or the merged schema would not
//       compile), so only the AppHost structural lint ran and it found nothing.
//       This is NOT a pass: the schema was never applied. Callers must surface
//       it as a distinct verdict — a silent downgrade to a weaker check is the
//       failure mode this whole package exists to remove. Exit 1 still wins
//       when the structural lint DID find something, so a real finding is
//       never masked by the degradation.

'use strict'

const fs = require('fs')
const path = require('path')

// The vendored canonical schema lives next to this helper: scripts/schemas/.
// It is the ADR-040 SUPERSET (published v2 base + observability + deepLinks).
const CANONICAL_SCHEMA_PATH = path.resolve(__dirname, '..', 'schemas', 'app-manifest-v2.schema.json')

// `--scope-ids FILE` (ADR-020): findings on manifest entries the PR did not
// touch are reported as PRE-EXISTING instead of blocking. Absent → full-repo.
const _argv = process.argv.slice(2)
let SCOPE_IDS_FILE = null
const _positional = []
for (let i = 0; i < _argv.length; i++) {
	if (_argv[i] === '--scope-ids') { SCOPE_IDS_FILE = _argv[++i]; continue }
	if (_argv[i].startsWith('--scope-ids=')) { SCOPE_IDS_FILE = _argv[i].slice('--scope-ids='.length); continue }
	_positional.push(_argv[i])
}

const MANIFEST_PATH = _positional[0]
	? path.resolve(_positional[0])
	: path.resolve(process.cwd(), 'src', 'manifest.json')

const scopeFilter = require('./manifest_scope_filter.js')

// Base-schema candidates, in priority order. The CANONICAL hydra-vendored
// schema wins (2026-07-06 manifest audit, item 4): pinned-first meant "pass"
// certified against whatever schema generation the app happened to have
// installed — beta.30…beta.146 across the fleet — contradicting this gate's
// own contract ("validated against the CANONICAL schema"). The app's pinned
// copy and a sibling nextcloud-vue tree remain as fallbacks only for a
// broken hydra checkout; APP_MANIFEST_SCHEMA stays first as the explicit
// test/debug override.
const BASE_SCHEMA_CANDIDATES = [
	process.env.APP_MANIFEST_SCHEMA,
	CANONICAL_SCHEMA_PATH,
	path.resolve(process.cwd(), 'node_modules', '@conduction', 'nextcloud-vue', 'src', 'schemas', 'app-manifest-v2.schema.json'),
	path.resolve(process.cwd(), 'node_modules', '@conduction', 'nextcloud-vue', 'dist', 'schemas', 'app-manifest-v2.schema.json'),
	path.resolve(process.cwd(), '..', 'nextcloud-vue', 'src', 'schemas', 'app-manifest-v2.schema.json'),
].filter(Boolean)

// --- Post-schema semantic checks (2026-07-06 audit, item 4) ----------------
// Ported from @conduction/nextcloud-vue validateManifestV2()'s programmatic
// checks — the two rules the gate spec mandates but JSON Schema cannot
// express. Kept in sync with nextcloud-vue src/utils/validateManifest.js and
// src/utils/resolveSlotColumns.js.
const BUILT_IN_WIDGET_KEYS = new Set(['object-table', 'card-grid', 'form-renderer', 'map-viewer', 'chart', 'stats-block'])
const SLOT_COLUMNS_DEFAULTS = { body: 12, sidebar: 1, 'header-actions': 12, footer: 12, modal: 12 }

function slotColumns(slotName, overrides) {
	if (overrides && typeof overrides === 'object' && Number.isInteger(overrides[slotName]) && overrides[slotName] > 0) {
		return overrides[slotName]
	}
	if (Object.prototype.hasOwnProperty.call(SLOT_COLUMNS_DEFAULTS, slotName || '')) return SLOT_COLUMNS_DEFAULTS[slotName]
	return 12
}

// Grid arithmetic (gridX + gridWidth ≤ resolved slot columns) and the
// ADR-036 Decision 1 single-12×12-custom-widget dashboard rule, with the
// canonical error message from the gate-manifest-validates spec.
// Returns [{ path, message }].
// A FINDING COUNT IS NOT A DEFECT COUNT.
//
// Ajv with `allErrors: true` reports the APPLICATOR keyword alongside the leaf
// cause. The manifest schema states the `_note` rule as a nested if/then/else
// (ConductionNL/nextcloud-vue#315 relaxed it so a `Cn[A-Z]\w+` component is
// self-documenting), so ONE missing `_note` on ONE page emits THREE errors:
//
//   at /pages/0: must have required property '_note' (keyword=required)  ← real
//   at /pages/0: must match "else" schema (keyword=if)                   ← echo
//   at /pages/0: must match "then" schema (keyword=if)                   ← echo
//
// That is how shillinq's gate-53 run reported "240 violations" for roughly 132
// defects: 51 missing `_note` entries contributed 153 lines between them. The
// inflated number is not a cosmetic problem — it is what makes a repo's
// manifest debt look insurmountable and drives people to stop reading the log.
//
// The `if` lines carry no information the leaf error does not already state in
// actionable terms ("must match else schema" tells you nothing you can fix).
// They are dropped ONLY when a concrete sibling error exists at the same
// instancePath, so an applicator failure that is genuinely the only signal at
// that path still surfaces rather than vanishing — an error silently deleted
// would be the same class of bug one level down.
//
// Exact duplicates are also collapsed: the merged AppHost schema can assert
// `additionalProperties` twice over the same node, which emitted the identical
// line twice at `/`.
function collapseAjvErrors(errs) {
	const APPLICATOR = new Set(['if', 'then', 'else'])
	const concreteByPath = new Set()
	for (const e of errs) {
		if (!APPLICATOR.has(e.keyword)) concreteByPath.add(e.instancePath || '/')
	}
	const seen = new Set()
	const out = []
	for (const e of errs) {
		const at = e.instancePath || '/'
		if (APPLICATOR.has(e.keyword) && concreteByPath.has(at)) continue
		const key = `${at} ${e.keyword} ${e.message}`
		if (seen.has(key)) continue
		seen.add(key)
		out.push(e)
	}
	return out
}

function semanticChecks(manifest) {
	const errors = []
	const pages = Array.isArray(manifest.pages) ? manifest.pages : []
	pages.forEach((page, pIndex) => {
		if (!page || typeof page !== 'object') return
		const widgets = Array.isArray(page.widgets) ? page.widgets : []
		const overrides = (page.config && typeof page.config === 'object') ? page.config.slotColumns : null
		widgets.forEach((widget, wIndex) => {
			if (!widget || typeof widget !== 'object') return
			if (typeof widget.gridX === 'number' && typeof widget.gridWidth === 'number') {
				const cols = slotColumns(widget.slot, overrides)
				if (widget.gridX + widget.gridWidth > cols) {
					errors.push({
						path: `/pages/${pIndex}/widgets/${wIndex}`,
						message: `Widget '${widget.widgetKey}' in slot '${widget.slot}': gridX (${widget.gridX}) + gridWidth (${widget.gridWidth}) exceeds ${cols}`,
					})
				}
			}
		})
		if (page.type === 'dashboard' && widgets.length === 1 && widgets[0] && typeof widgets[0] === 'object') {
			const widget = widgets[0]
			// Omitted coordinates default to the full body grid, so authors
			// cannot circumvent the rule by leaving the fields out.
			const gx = typeof widget.gridX === 'number' ? widget.gridX : 0
			const gy = typeof widget.gridY === 'number' ? widget.gridY : 0
			const gw = typeof widget.gridWidth === 'number' ? widget.gridWidth : 12
			const gh = typeof widget.gridHeight === 'number' ? widget.gridHeight : 12
			const widgetKey = typeof widget.widgetKey === 'string' ? widget.widgetKey : ''
			if (widget.slot === 'body' && gx === 0 && gy === 0 && gw === 12 && gh === 12
				&& widgetKey !== '' && BUILT_IN_WIDGET_KEYS.has(widgetKey) === false) {
				const pageId = typeof page.id === 'string' ? page.id : `[${pIndex}]`
				errors.push({
					path: `/pages/${pIndex}/widgets/0`,
					message: `pages[${pageId}] is type:"dashboard" with a single 12×12 custom widget — this is always a custom page in disguise.\n`
						+ 'Valid alternatives:\n'
						+ `  (a) declare as type:"custom" with component:"${widgetKey}" and register the component with kind:"page"\n`
						+ '  (b) split into N>1 widgets if this is genuinely a multi-widget dashboard\n'
						+ 'See ADR-036 Decision 1 (single-widget dashboard anti-pattern).',
				})
			}
		}
	})
	return errors
}

// Emit the report mandated by the gate-manifest-validates spec: on failure,
// one machine-parseable per-file JSON line, then always the JSON summary
// line — both on stdout (every stdout line is valid JSON). Human-readable
// `at <path>: <first line>` diagnostics go to stderr, which also keeps
// run-hydra-gates.sh's `grep -cE '^at /'` failure count working.
function report(allErrors, degradedReason, manifest) {
	// ADR-020: answer over the whole manifest, block only on entries the PR
	// touched. `scope` is null on a full-repo run and everything blocks.
	const scope = scopeFilter.loadScope(SCOPE_IDS_FILE)
	const parts = scopeFilter.partition(allErrors, manifest || {}, scope)
	scopeFilter.reportScope('check_manifest', parts)
	const errors = parts.blocking
	const failed = errors.length > 0 ? 1 : 0
	if (failed === 1) {
		for (const e of errors) console.error(`at ${e.path || '/'}: ${String(e.message).split('\n')[0]}`)
		console.log(JSON.stringify({ file: path.relative(process.cwd(), MANIFEST_PATH), schemaVersion: 'v2', errors }))
	}
	// A zero-finding run that never applied the schema is NOT a pass. Say so on
	// both channels and exit 3 so the caller cannot mistake it for one.
	if (failed === 0 && degradedReason) {
		console.error(`[check_manifest] DEGRADED — SCHEMA VALIDATION DID NOT HAPPEN: ${degradedReason}`)
		console.error('[check_manifest] The AppHost structural lint found nothing, but it checks only the observability/deepLinks blocks.')
		console.error('[check_manifest] Reporting this as a pass would certify a manifest that was never validated against the canonical schema.')
		console.log(JSON.stringify({ status: 'degraded', checked: 1, failed: 0, reason: degradedReason }))
		process.exit(3)
	}
	console.log(JSON.stringify({ status: failed === 1 ? 'failed' : 'passed', checked: 1, failed }))
	process.exit(failed)
}

// Closed enums mirrored from the vendored ADR-040 schema for the structural
// fallback. Kept in sync with scripts/schemas/app-manifest-v2.schema.json.
const HEALTH_CHECK_TYPES = new Set(['database', 'filesystem', 'appEnabled', 'appConfig', 'orAvailable'])
const HEALTH_SEVERITIES = new Set(['critical', 'degraded'])
const HEALTH_STATUS_POLICIES = new Set(['adr006', 'always200'])
const METRIC_TYPES = new Set(['gauge', 'counter'])
const METRIC_SOURCE_KINDS = new Set(['tableCount', 'objectCount', 'objectSum', 'appConfig', 'provider'])

function loadJson(file) {
	return JSON.parse(fs.readFileSync(file, 'utf8'))
}

function findFirst(candidates) {
	for (const c of candidates) {
		try {
			if (c && fs.existsSync(c) && fs.statSync(c).isFile()) return c
		} catch (_) { /* next */ }
	}
	return null
}

// Merge the canonical observability + deepLinks definitions into the base
// schema. Additive only — if the base already declares them (a newer pinned
// lib), the canonical definitions win so the contract is uniform across apps.
function mergeAppHostBlocks(base, canonical) {
	const merged = JSON.parse(JSON.stringify(base))
	merged.$defs = merged.$defs || {}
	merged.properties = merged.properties || {}

	if (canonical.$defs && canonical.$defs.observability) {
		merged.$defs.observability = canonical.$defs.observability
	}
	if (canonical.$defs && canonical.$defs.deepLink) {
		merged.$defs.deepLink = canonical.$defs.deepLink
	}
	if (canonical.properties && canonical.properties.observability) {
		merged.properties.observability = canonical.properties.observability
	}
	if (canonical.properties && canonical.properties.deepLinks) {
		merged.properties.deepLinks = canonical.properties.deepLinks
	}
	// observability / deepLinks are OPTIONAL — never add them to `required`.
	return merged
}

// WHERE THE GATE PACKAGE HAPPENS TO SIT IS NOT A PROPERTY OF THE MANIFEST
// (.github#271).
//
// A bare `require('ajv/dist/2020')` resolves relative to THIS FILE — Node walks
// `node_modules` up from `__dirname`, then falls back to NODE_PATH. It never
// looks at the repository being validated. So the gate's verdict depended on
// where the gates were checked out:
//
//   vendor/conduction/hydra-gates/…   the walk passes through the app root and
//                                     finds the app's ajv           -> validated
//   a sibling clone of ConductionNL/.github
//                                     the walk never reaches the app -> exit 3,
//                                     "SCHEMA VALIDATION DID NOT HAPPEN"
//
// Measured 2026-08-08: run from openregister's own root, with
// `node_modules/ajv` PRESENT one directory up from cwd, the validator printed
// "Ajv is not resolvable from this process (no node_modules, no NODE_PATH)" —
// a statement that was simply false — and gate-22 went FAIL. Exporting
// NODE_PATH to the very same directory flipped it to PASS. Same tree, same
// package, two verdicts: that is not a gate.
//
// Resolution is now anchored on the SUBJECT: the manifest's own repo root and
// the process cwd come first, the gate package's own tree last. All three are
// stated in the degradation message so "not resolvable" can be checked rather
// than believed.
function _ajvSearchPaths() {
	const roots = []
	// The repo that owns the manifest under validation: <root>/src/manifest.json
	const manifestRepoRoot = path.resolve(path.dirname(MANIFEST_PATH), '..')
	roots.push(manifestRepoRoot)
	roots.push(process.cwd())
	roots.push(__dirname)
	const seen = new Set()
	const out = []
	for (const r of roots) {
		let dir = r
		// Walk up from each root so a manifest in a monorepo sub-package still
		// finds the hoisted install.
		for (;;) {
			const nm = path.join(dir, 'node_modules')
			if (!seen.has(nm)) { seen.add(nm); out.push(nm) }
			const parent = path.dirname(dir)
			if (parent === dir) break
			dir = parent
		}
	}
	return out
}

function _requireFrom(name, paths) {
	try {
		return require(require.resolve(name, { paths }))
	} catch (_) {
		try {
			// Last resort: the ambient resolution (NODE_PATH / this file's own
			// ancestry). Kept so a working setup never regresses.
			return require(name)
		} catch (__) {
			return null
		}
	}
}

function loadAjv() {
	// The schema is JSON Schema draft 2020-12; Ajv needs the ajv/dist/2020
	// entry point. ajv-formats is best-effort (the "uri" format on $schema).
	const paths = _ajvSearchPaths()
	let Ajv = null
	let addFormats = null
	const mod2020 = _requireFrom('ajv/dist/2020', paths)
	if (mod2020) {
		Ajv = mod2020.default || mod2020
	} else {
		const modPlain = _requireFrom('ajv', paths)
		if (!modPlain) {
			return { Ajv: null, addFormats: null, searched: paths }
		}
		Ajv = modPlain.default || modPlain
	}
	const fmt = _requireFrom('ajv-formats', paths)
	addFormats = fmt ? (fmt.default || fmt) : null
	return { Ajv, addFormats, searched: paths }
}

// Structural fallback: validates the AppHost observability + deepLinks blocks
// FOR-REAL against the closed ADR-040 enums. Used only when Ajv is unavailable.
// Returns an array of "at <path>: <msg>" strings. Deliberately does NOT re-check
// non-AppHost page rules (those are owned by the merged Ajv schema path); it
// never fail-opens on the AppHost blocks.
function structuralLintAppHost(m) {
	const errors = []
	const at = (p, msg) => errors.push({ path: p, message: msg })

	if (m.observability !== undefined) {
		const o = m.observability
		if (!o || typeof o !== 'object' || Array.isArray(o)) {
			at('/observability', 'must be an object')
		} else {
			if (o.health !== undefined) {
				const h = o.health
				if (!h || typeof h !== 'object' || Array.isArray(h)) {
					at('/observability/health', 'must be an object')
				} else {
					if (h.statusCodePolicy !== undefined && !HEALTH_STATUS_POLICIES.has(h.statusCodePolicy)) {
						at('/observability/health/statusCodePolicy', `"${h.statusCodePolicy}" is not a valid status-code policy`)
					}
					if (h.cors !== undefined && typeof h.cors !== 'boolean') at('/observability/health/cors', 'must be a boolean')
					const checks = Array.isArray(h.checks) ? h.checks : (h.checks === undefined ? [] : null)
					if (checks === null) {
						at('/observability/health/checks', 'must be an array')
					} else {
						checks.forEach((c, i) => {
							const base = `/observability/health/checks/${i}`
							if (!c || typeof c !== 'object') { at(base, 'must be an object'); return }
							if (typeof c.id !== 'string') at(`${base}/id`, 'is required and must be a string')
							if (typeof c.type !== 'string' || !HEALTH_CHECK_TYPES.has(c.type)) {
								at(`${base}/type`, `"${c.type}" is not one of the allowed health check types`)
							}
							if (c.severity !== undefined && !HEALTH_SEVERITIES.has(c.severity)) {
								at(`${base}/severity`, `"${c.severity}" is not a valid severity`)
							}
						})
					}
				}
			}
			if (o.metrics !== undefined) {
				const metrics = Array.isArray(o.metrics) ? o.metrics : null
				if (metrics === null) {
					at('/observability/metrics', 'must be an array')
				} else {
					metrics.forEach((mt, i) => {
						const base = `/observability/metrics/${i}`
						if (!mt || typeof mt !== 'object') { at(base, 'must be an object'); return }
						if (typeof mt.name !== 'string') at(`${base}/name`, 'is required and must be a string')
						if (typeof mt.type !== 'string' || !METRIC_TYPES.has(mt.type)) {
							at(`${base}/type`, `"${mt.type}" is not one of the allowed metric types (gauge, counter)`)
						}
						if (!mt.source || typeof mt.source !== 'object') {
							at(`${base}/source`, 'is required and must be an object')
						} else if (typeof mt.source.kind !== 'string' || !METRIC_SOURCE_KINDS.has(mt.source.kind)) {
							at(`${base}/source/kind`, `"${mt.source.kind}" is not one of the allowed metric source kinds`)
						}
					})
				}
			}
		}
	}

	if (m.deepLinks !== undefined) {
		if (!Array.isArray(m.deepLinks)) {
			at('/deepLinks', 'must be an array')
		} else {
			m.deepLinks.forEach((d, i) => {
				const base = `/deepLinks/${i}`
				if (!d || typeof d !== 'object') { at(base, 'must be an object'); return }
				for (const req of ['registerSlug', 'schemaSlug', 'urlTemplate']) {
					if (typeof d[req] !== 'string') at(`${base}/${req}`, 'is required and must be a string')
				}
			})
		}
	}

	return errors
}

function main() {
	if (!fs.existsSync(MANIFEST_PATH)) {
		// Tier 0 — no manifest. The caller (gate-22) only invokes us when a
		// manifest exists, but stay defensive: skip cleanly.
		console.error('[check_manifest] no src/manifest.json — Tier 0, skipping')
		process.exit(0)
	}
	if (!fs.existsSync(CANONICAL_SCHEMA_PATH)) {
		console.error(`[check_manifest] vendored canonical schema missing at ${CANONICAL_SCHEMA_PATH} — gate misconfiguration`)
		process.exit(2)
	}

	let manifest
	try {
		manifest = loadJson(MANIFEST_PATH)
	} catch (e) {
		console.error(`at /: src/manifest.json is not valid JSON (${e.message})`)
		process.exit(1)
	}

	const canonical = loadJson(CANONICAL_SCHEMA_PATH)
	const basePath = findFirst(BASE_SCHEMA_CANDIDATES)
	const base = basePath ? loadJson(basePath) : canonical
	console.error(`[check_manifest] base schema: ${basePath || CANONICAL_SCHEMA_PATH}`)
	console.error('[check_manifest] AppHost blocks (observability, deepLinks) validated against the hydra-vendored canonical ADR-040 schema')
	const schema = mergeAppHostBlocks(base, canonical)

	const { Ajv, addFormats, searched } = loadAjv()
	if (Ajv) {
		let validate
		try {
			const ajv = new Ajv({ allErrors: true, strict: false })
			if (addFormats) {
				try { addFormats(ajv) } catch (_) { /* formats best-effort */ }
			}
			validate = ajv.compile(schema)
		} catch (e) {
			console.error(`[check_manifest] Ajv could not compile the merged schema (${e.message}); falling back to AppHost structural lint`)
			return finishStructural(manifest, `Ajv could not compile the merged canonical schema (${e.message})`)
		}
		const errors = []
		if (validate(manifest)) {
			console.error('[check_manifest] Ajv validation against merged canonical schema: PASS')
		} else {
			for (const err of collapseAjvErrors(validate.errors || [])) {
				errors.push({ path: err.instancePath || '/', message: `${err.message} (keyword=${err.keyword})` })
			}
		}
		errors.push(...semanticChecks(manifest))
		return report(errors, null, manifest)
	}

	// NAME WHERE WE LOOKED. The old message asserted "no node_modules, no
	// NODE_PATH" — which was false in the case that produced it (.github#271):
	// the app root one directory up from cwd had `node_modules/ajv` installed,
	// and the resolver simply never looked there. An unverifiable claim about
	// the environment is how a wiring failure passes for a fact.
	const _searchedNote = (searched || []).slice(0, 6).join(', ')
	console.error('[check_manifest] Ajv not installed; using AppHost structural lint (observability/deepLinks still validated for-real)')
	console.error(`[check_manifest] ajv searched (first 6 of ${(searched || []).length}): ${_searchedNote}`)
	return finishStructural(
		manifest,
		`Ajv is not resolvable from the manifest's own repo root, the process cwd, or the gate package tree. Searched: ${_searchedNote}`,
	)
}

function finishStructural(manifest, degradedReason) {
	const errors = structuralLintAppHost(manifest)
	if (errors.length === 0) {
		console.error('[check_manifest] AppHost structural lint against canonical ADR-040 enums: PASS')
	}
	errors.push(...semanticChecks(manifest))
	return report(errors, degradedReason, manifest)
}

// Run as a script; expose the pure helper when required as a module, so
// test_check_manifest_ajv_collapse.js can exercise collapseAjvErrors without
// Ajv installed and without validating a manifest as a side effect.
if (require.main === module) {
	main()
} else {
	module.exports = { collapseAjvErrors }
}
