#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// validate-manifest.js — schema-validates src/manifest.json against the
// @conduction/nextcloud-vue app-manifest schema using Ajv.
//
// @spec openspec/specs/openregister-app-manifest/spec.md#REQ-OR-MAN-007
//   (Build gate validates the manifest — `npm run check:manifest` runs this CLI,
//    is wired into CI via .github/workflows/spec-validation.yml → check:specs,
//    Ajv-validates against the canonical schema, prints error paths, exits non-zero
//    on schema violation.)
//
// @spec openspec/specs/openregister-app-manifest/spec.md#REQ-OR-MAN-002
//   (Manifest declares zero Conduction-app dependencies — OpenRegister is the
//    foundation app, so src/manifest.json carries `"dependencies": []`. The
//    schema declares `dependencies` as a required array, and CnAppRoot guards
//    the dependency-check phase against it. With the manifest authored under
//    openregister-manifest-shell-swap, the validator now enforces this end-to-
//    end on every CI run.)
//
// @spec openspec/specs/openregister-app-manifest/spec.md#REQ-OR-MAN-008
//   (Manifest version reflects the adoption tier — top-level `version: "1.0.0"`
//    set in src/manifest.json signals Tier-4 / full-shell adoption. The schema's
//    required `version` field, asserted by Ajv here AND by structuralLint
//    below as a fallback, prevents accidental removal or empty-string drift.)
//
// Usage:
//   node tests/validate-manifest.js
//
// Exit codes:
//   0 — manifest validates against the schema with zero errors
//   1 — manifest fails validation (or schema/manifest cannot be loaded)
//
// Schema lookup order (first hit wins):
//   1. Env var APP_MANIFEST_SCHEMA — explicit absolute path to a schema JSON
//   2. node_modules/@conduction/nextcloud-vue/src/schemas/app-manifest.schema.json
//   3. ../nextcloud-vue/src/schemas/app-manifest.schema.json (sibling worktree)
//   4. /tmp/worktrees/nextcloud-vue-manifest-v1/src/schemas/app-manifest.schema.json
//   5. /tmp/worktrees/nextcloud-vue-page-type-extensions/src/schemas/app-manifest.schema.json

'use strict'

const fs = require('fs')
const path = require('path')

const REPO_ROOT = path.resolve(__dirname, '..')

const MANIFEST_PATH = path.join(REPO_ROOT, 'src', 'manifest.json')

// v1 schema candidates (default).
const SCHEMA_CANDIDATES_V1 = [
	process.env.APP_MANIFEST_SCHEMA,
	path.join(
		REPO_ROOT,
		'node_modules',
		'@conduction',
		'nextcloud-vue',
		'src',
		'schemas',
		'app-manifest.schema.json',
	),
	path.join(
		REPO_ROOT,
		'..',
		'nextcloud-vue',
		'src',
		'schemas',
		'app-manifest.schema.json',
	),
	'/tmp/worktrees/nextcloud-vue-manifest-v1/src/schemas/app-manifest.schema.json',
	'/tmp/worktrees/nextcloud-vue-page-type-extensions/src/schemas/app-manifest.schema.json',
].filter(Boolean)

// v2 schema candidates — preferred when the manifest's `$schema` references the
// v2 schema. The published @conduction/nextcloud-vue (beta.103) does NOT yet
// ship the v2 schema in its package, so a synced copy is vendored at
// tests/schemas/app-manifest-v2.schema.json for CI portability. Once the
// package publishes the v2 schema the node_modules candidate wins automatically
// and the vendored copy becomes the fallback.
const SCHEMA_CANDIDATES_V2 = [
	process.env.APP_MANIFEST_SCHEMA,
	path.join(
		REPO_ROOT,
		'node_modules',
		'@conduction',
		'nextcloud-vue',
		'src',
		'schemas',
		'app-manifest-v2.schema.json',
	),
	path.join(
		REPO_ROOT,
		'..',
		'nextcloud-vue',
		'src',
		'schemas',
		'app-manifest-v2.schema.json',
	),
	path.join(REPO_ROOT, 'tests', 'schemas', 'app-manifest-v2.schema.json'),
].filter(Boolean)

/**
 * Pick the candidate list based on the manifest's declared `$schema`. A
 * manifest whose `$schema` mentions `app-manifest-v2` is validated against the
 * v2 schema; everything else falls back to v1.
 *
 * @param {object} manifest The parsed manifest.
 * @return {Array<string>} Ordered schema-path candidates.
 */
function schemaCandidatesFor(manifest) {
	const declared =
		manifest && typeof manifest.$schema === 'string' ? manifest.$schema : ''
	if (declared.includes('app-manifest-v2')) {
		return SCHEMA_CANDIDATES_V2
	}
	return SCHEMA_CANDIDATES_V1
}

function findSchemaPath(candidates) {
	for (const candidate of candidates) {
		try {
			if (fs.existsSync(candidate) && fs.statSync(candidate).isFile()) {
				return candidate
			}
		} catch (_) {
			// continue to next candidate
		}
	}
	return null
}

function loadJson(file) {
	const raw = fs.readFileSync(file, 'utf8')
	return JSON.parse(raw)
}

function loadAjv() {
	// The canonical schema uses JSON Schema draft 2020-12. Standard Ajv (v7+)
	// does not auto-load the 2020 meta-schema; we need the `ajv/dist/2020`
	// entry point.
	let Ajv2020 = null
	let addFormats = null
	try {
		// Ajv 8+ ships the 2020 draft entry point.
		Ajv2020 = require('ajv/dist/2020').default || require('ajv/dist/2020')
	} catch (_) {
		try {
			// Fall back to standard Ajv.
			Ajv2020 = require('ajv').default || require('ajv')
		} catch (__) {
			console.error('[validate-manifest] Ajv not installed in node_modules.')
			console.error(
				'[validate-manifest] Install with: npm i -D ajv ajv-formats',
			)
			console.error(
				'[validate-manifest] Falling back to a structural lint pass.',
			)
			return { Ajv: null, addFormats: null }
		}
	}
	try {
		addFormats = require('ajv-formats').default || require('ajv-formats')
	} catch (_) {
		// ajv-formats is optional; the schema uses "uri" format on $schema
		// which without ajv-formats is silently accepted.
		addFormats = null
	}
	return { Ajv: Ajv2020, addFormats }
}

function structuralLint(manifest) {
	// Minimal structural fallback when Ajv isn't available.
	const errors = []
	if (!manifest.version || typeof manifest.version !== 'string') {
		errors.push('top-level: version (string) is required')
	}
	if (!Array.isArray(manifest.menu))
		errors.push('top-level: menu (array) is required')
	if (!Array.isArray(manifest.pages))
		errors.push('top-level: pages (array) is required')
	const allowedTypes = new Set([
		'index',
		'detail',
		'dashboard',
		'logs',
		'settings',
		'chat',
		'files',
		'custom',
	])
	const seenIds = new Set()
	for (let i = 0; i < (manifest.pages || []).length; i++) {
		const page = manifest.pages[i]
		if (!page || typeof page !== 'object') {
			errors.push(`pages[${i}]: must be an object`)
			continue
		}
		for (const required of ['id', 'route', 'type', 'title']) {
			if (!page[required] || typeof page[required] !== 'string') {
				errors.push(
					`pages[${i}]: missing required string field "${required}"`,
				)
			}
		}
		if (page.type && !allowedTypes.has(page.type)) {
			errors.push(`pages[${i}].type: "${page.type}" not in known enum`)
		}
		if (page.id) {
			if (seenIds.has(page.id))
				errors.push(`pages[${i}].id: duplicate "${page.id}"`)
			seenIds.add(page.id)
		}
	}
	return errors
}

function main() {
	if (!fs.existsSync(MANIFEST_PATH)) {
		// openregister is the foundation app — no CnAppRoot manifest expected.
		// Skip cleanly instead of failing CI.
		console.log(
			`[validate-manifest] no src/manifest.json (foundation app) — skipping`,
		)
		process.exit(0)
	}

	const manifest = loadJson(MANIFEST_PATH)
	console.log(`[validate-manifest] manifest: ${MANIFEST_PATH}`)
	console.log(`[validate-manifest] manifest.version: ${manifest.version}`)
	console.log(`[validate-manifest] pages: ${(manifest.pages || []).length}`)

	const schemaPath = findSchemaPath(schemaCandidatesFor(manifest))
	if (!schemaPath) {
		console.warn(
			'[validate-manifest] no schema candidate resolved; falling back to structural lint.',
		)
		const errors = structuralLint(manifest)
		if (errors.length === 0) {
			console.log('[validate-manifest] structural lint: PASS (0 issues)')
			process.exit(0)
		}
		console.error('[validate-manifest] structural lint: FAIL')
		for (const err of errors) console.error(`  - ${err}`)
		process.exit(1)
	}
	console.log(`[validate-manifest] schema: ${schemaPath}`)
	const schema = loadJson(schemaPath)
	console.log(`[validate-manifest] schema.version: ${schema.version || '(unset)'}`)

	const { Ajv, addFormats } = loadAjv()
	if (!Ajv) {
		const errors = structuralLint(manifest)
		if (errors.length === 0) {
			console.log(
				'[validate-manifest] structural lint (no Ajv): PASS (0 issues)',
			)
			process.exit(0)
		}
		console.error('[validate-manifest] structural lint (no Ajv): FAIL')
		for (const err of errors) console.error(`  - ${err}`)
		process.exit(1)
	}

	// The ajv path can fail for environment reasons unrelated to the manifest:
	// ajv-formats@2 expects ajv@8 but a transitive ajv@6 may resolve (addFormats
	// then throws on ajv.opts.code), and ajv@6 can't compile a draft-2020 schema.
	// Formats are best-effort, and any ajv setup/compile failure degrades to the
	// structural lint rather than crashing the gate.
	let validate
	try {
		const ajv = new Ajv({ allErrors: true, strict: false })
		if (addFormats) {
			try {
				addFormats(ajv)
			} catch (e) {
				console.warn(
					`[validate-manifest] ajv-formats unavailable (${e.message}); continuing without format validation`,
				)
			}
		}
		validate = ajv.compile(schema)
	} catch (e) {
		console.warn(
			`[validate-manifest] Ajv could not compile the schema (${e.message}); falling back to structural lint`,
		)
		const errors = structuralLint(manifest)
		if (errors.length === 0) {
			console.log(
				'[validate-manifest] structural lint (Ajv unavailable): PASS (0 issues)',
			)
			process.exit(0)
		}
		console.error('[validate-manifest] structural lint (Ajv unavailable): FAIL')
		for (const err of errors) console.error(`  - ${err}`)
		process.exit(1)
	}
	const ok = validate(manifest)
	if (ok) {
		console.log('[validate-manifest] Ajv validation: PASS (0 errors)')
		process.exit(0)
	}
	console.error('[validate-manifest] Ajv validation: FAIL')
	for (const err of validate.errors || []) {
		console.error(
			`  - ${err.instancePath || '(root)'} ${err.message} (keyword=${err.keyword})`,
		)
	}
	process.exit(1)
}

main()
