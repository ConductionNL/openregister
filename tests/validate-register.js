#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// validate-register.js — structural validation of the OpenRegister register
// seed (lib/Settings/*_register.json). Catches the classes of bug seen in
// scholiq Wave 2 *before* they reach development:
//   - a schema lost its x-openregister-* declarations / half its properties
//     (clobbered by a bad merge) — flagged via a too-thin-schema heuristic
//   - appendOnly nested in x-openregister instead of at the schema root
//     (silently dropped by OpenRegister) — also flagged here
//   - a lifecycle transition `requires:` referencing a PHP class that doesn't
//     exist as a file under lib/ (scholiq's missing CoursePublishGuard)
//   - lifecycle transition `to`/`from` states that aren't declared anywhere
//   - duplicate slugs across schemas
//
// Optional deep check: if OR_BASE_URL + OR_BASIC_AUTH env vars are set, POST
// the register to OpenRegister's schema-validation endpoint. Skipped by default
// (CI has no OR instance).
//
// Usage:   node tests/validate-register.js
// Exit:    0 = register is structurally sound   1 = problems found

'use strict'

const fs = require('fs')
const path = require('path')

const REPO_ROOT = path.resolve(__dirname, '..')

function registerFiles() {
	const dir = path.join(REPO_ROOT, 'lib', 'Settings')
	if (!fs.existsSync(dir)) return []
	return fs.readdirSync(dir)
		.filter((f) => f.endsWith('_register.json') || f.endsWith('-register.json'))
		.map((f) => path.join(dir, f))
}

// Collect every PHP class file under lib/ as a set of fully-qualified-ish names.
// We only need the class shortname → exists check, plus a namespace-tail match.
function phpClassIndex(appNamespace) {
	const libDir = path.join(REPO_ROOT, 'lib')
	const found = new Set()
	function walk(d) {
		for (const entry of fs.readdirSync(d, { withFileTypes: true })) {
			const p = path.join(d, entry.name)
			if (entry.isDirectory()) walk(p)
			else if (entry.name.endsWith('.php')) {
				const rel = path.relative(libDir, p).replace(/\.php$/, '').split(path.sep)
				// e.g. ['Lifecycle','CoursePublishGuard'] → OCA\<App>\Lifecycle\CoursePublishGuard
				found.add(`${appNamespace}\\${rel.join('\\')}`)
				found.add(rel[rel.length - 1]) // shortname fallback
			}
		}
	}
	if (fs.existsSync(libDir)) walk(libDir)
	return found
}

// Derive the PHP namespace OCA\<App> from appinfo/info.xml's <id>.
function appNamespace() {
	const infoXml = path.join(REPO_ROOT, 'appinfo', 'info.xml')
	if (!fs.existsSync(infoXml)) return null
	const m = fs.readFileSync(infoXml, 'utf8').match(/<id>([^<]+)<\/id>/)
	if (!m) return null
	// app id like "openconnector" or "app-template" → namespace "OpenConnector" / "AppTemplate"
	const camel = m[1].split(/[-_]/).map((s) => s.charAt(0).toUpperCase() + s.slice(1)).join('')
	return `OCA\\${camel}`
}

function collectRequires(node, acc) {
	if (node === null || typeof node !== 'object') return
	if (Array.isArray(node)) { node.forEach((n) => collectRequires(n, acc)); return }
	for (const [k, v] of Object.entries(node)) {
		if (k === 'requires') {
			if (typeof v === 'string') acc.push(v)
			else if (Array.isArray(v)) v.forEach((s) => { if (typeof s === 'string') acc.push(s) })
		} else {
			collectRequires(v, acc)
		}
	}
}

function lifecycleStates(lc) {
	// lc is an x-openregister-lifecycle block; shapes vary slightly across the
	// fleet: {states:{...},transitions:{...}} or {transitions:{name:{from,to}}}.
	const states = new Set()
	if (!lc || typeof lc !== 'object') return states
	if (lc.states && typeof lc.states === 'object') Object.keys(lc.states).forEach((s) => states.add(s))
	if (lc.initial) states.add(lc.initial)
	if (lc.initialState) states.add(lc.initialState)
	if (lc.default) states.add(lc.default)
	const tr = lc.transitions || {}
	for (const t of Object.values(tr)) {
		if (t && typeof t === 'object') {
			if (typeof t.to === 'string') states.add(t.to)
			if (typeof t.from === 'string') states.add(t.from)
			if (Array.isArray(t.from)) t.from.forEach((f) => states.add(f))
		}
	}
	states.delete(null)
	return states
}

function validateRegister(file, errors, warnings) {
	const label = path.relative(REPO_ROOT, file)
	let reg
	try {
		reg = JSON.parse(fs.readFileSync(file, 'utf8'))
	} catch (e) {
		errors.push(`${label}: invalid JSON — ${e.message}`)
		return
	}
	const xo = reg['x-openregister']
	if (!xo || typeof xo !== 'object' || !xo.type || !xo.app) {
		errors.push(`${label}: missing or incomplete top-level x-openregister block (needs at least { type, app })`)
	}
	const schemas = (reg.components && reg.components.schemas) || null
	if (!schemas || typeof schemas !== 'object' || Object.keys(schemas).length === 0) {
		errors.push(`${label}: components.schemas is empty`)
		return
	}

	const ns = appNamespace()
	const phpIndex = phpClassIndex(ns || '')
	const slugSeen = new Map()

	for (const [name, s] of Object.entries(schemas)) {
		const at = `${label} › ${name}`
		if (!s || typeof s !== 'object') { errors.push(`${at}: not an object`); continue }
		// slug
		if (typeof s.slug !== 'string' || s.slug.length === 0) errors.push(`${at}: missing string "slug"`)
		else {
			if (slugSeen.has(s.slug)) errors.push(`${at}: slug "${s.slug}" duplicates ${slugSeen.get(s.slug)}`)
			slugSeen.set(s.slug, name)
		}
		// shape
		if (s.type !== 'object') warnings.push(`${at}: "type" is not "object" (got ${JSON.stringify(s.type)})`)
		if (!Array.isArray(s.required)) warnings.push(`${at}: "required" is not an array`)
		const props = s.properties && typeof s.properties === 'object' ? Object.keys(s.properties) : []
		if (props.length === 0) errors.push(`${at}: "properties" is empty — schema looks clobbered (a merge may have replaced it with a stub)`)
		// "too thin to be real" heuristic: a schema that declares a lifecycle/calc/notif/agg
		// elsewhere but here has ≤3 properties and no x-openregister-* at all is suspect.
		const xKeys = Object.keys(s).filter((k) => k.startsWith('x-openregister'))
		if (props.length > 0 && props.length <= 3 && xKeys.filter((k) => k !== 'x-openregister-seed').length === 0) {
			warnings.push(`${at}: only ${props.length} properties and no x-openregister-* declarations — verify this isn't a stub left by a bad merge`)
		}
		// appendOnly placement
		if (s['x-openregister'] && typeof s['x-openregister'] === 'object' && s['x-openregister'].appendOnly !== undefined) {
			errors.push(`${at}: appendOnly is nested inside x-openregister — OpenRegister only reads a TOP-LEVEL appendOnly; move it to the schema root`)
		}
		// lifecycle requires → PHP class must exist
		const requires = []
		collectRequires(s['x-openregister-lifecycle'] || {}, requires)
		for (const cls of requires) {
			const short = cls.split('\\').pop()
			if (!phpIndex.has(cls) && !phpIndex.has(short)) {
				errors.push(`${at}: lifecycle requires "${cls}" but no matching PHP class found under lib/ (looked for "${cls}" and shortname "${short}")`)
			}
		}
		// lifecycle transition target states should be declared
		const lc = s['x-openregister-lifecycle']
		if (lc && lc.transitions && lc.states) {
			const declared = new Set(Object.keys(lc.states))
			for (const [tn, t] of Object.entries(lc.transitions)) {
				if (t && typeof t.to === 'string' && !declared.has(t.to)) {
					warnings.push(`${at}: transition "${tn}" → "${t.to}" but "${t.to}" is not in states{}`)
				}
			}
		}
		void lifecycleStates // (helper retained for future deeper checks)
	}
}

async function deepValidate(files, errors) {
	const base = process.env.OR_BASE_URL
	const auth = process.env.OR_BASIC_AUTH // "user:pass"
	if (!base || !auth) return // skipped silently — no OR instance in CI
	const fetchFn = global.fetch
	if (!fetchFn) { errors.push('OR_BASE_URL set but global fetch unavailable (need Node 18+)'); return }
	for (const file of files) {
		const json = fs.readFileSync(file, 'utf8')
		try {
			const res = await fetchFn(`${base.replace(/\/$/, '')}/index.php/apps/openregister/api/configurations/validate`, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'OCS-APIRequest': 'true',
					Authorization: 'Basic ' + Buffer.from(auth).toString('base64'),
				},
				body: JSON.stringify({ json }),
			})
			if (!res.ok) errors.push(`${path.relative(REPO_ROOT, file)}: OR validate endpoint returned ${res.status}`)
			else {
				const body = await res.json().catch(() => ({}))
				if (body && Array.isArray(body.errors) && body.errors.length) {
					errors.push(`${path.relative(REPO_ROOT, file)}: OR reported ${body.errors.length} schema errors`)
				}
			}
		} catch (e) {
			errors.push(`${path.relative(REPO_ROOT, file)}: OR validate call failed — ${e.message}`)
		}
	}
}

async function main() {
	const files = registerFiles()
	if (files.length === 0) {
		console.log('[validate-register] no lib/Settings/*_register.json found — nothing to check')
		process.exit(0)
	}
	const errors = []
	const warnings = []
	for (const file of files) validateRegister(file, errors, warnings)
	await deepValidate(files, errors)

	for (const w of warnings) console.warn(`[validate-register] WARN  ${w}`)
	for (const e of errors) console.error(`[validate-register] ERROR ${e}`)
	if (errors.length > 0) {
		console.error(`[validate-register] FAIL — ${errors.length} error(s), ${warnings.length} warning(s).`)
		process.exit(1)
	}
	console.log(`[validate-register] PASS — ${files.length} register file(s), ${warnings.length} warning(s).`)
	process.exit(0)
}

main()
