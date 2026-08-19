#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
//
// build_effective_manifest.js — Gate-30 vendored effective-manifest builder.
//
// Assembles an app's EFFECTIVE manifest exactly as the library bootstrap
// does: base src/manifest.json + src/manifest.d/*.json fragments (ADR-037,
// ascending filename order) + src/menu-layout.json (ADR-044, applied in the
// order relocations → removals → settingsSection).
//
// SYNC NOTE — vendored port. The merge pipeline below is ported FAITHFULLY
// from @conduction/nextcloud-vue:
//   nextcloud-vue/src/utils/buildManifest.js
// (buildManifest, applyMenuLayout, mergeMenuItems, mergePages,
//  applyMenuRelocations, applyMenuRemovals, applySettingsSection).
// hydra has no package.json / node_modules, and the fleet's pinned lib
// generations span beta.30…beta.146 — vendoring gives ONE deterministic
// pipeline fleet-wide, mirroring how check_manifest.js vendors the canonical
// schema. If the lib's buildManifest semantics change, update this file AND
// scripts/lib/test_build_effective_manifest.js (the fixtures pin the
// observable merge behaviour).
//
// Usage (CLI):
//   node scripts/lib/build_effective_manifest.js [--app-dir DIR] [--out FILE]
//     --app-dir DIR   app repo root (default: CWD). Reads DIR/src/manifest.json,
//                     DIR/src/manifest.d/*.json, DIR/src/menu-layout.json.
//     --out FILE      write the assembled manifest JSON to FILE (default: stdout).
//
// Missing src/manifest.d/ and missing src/menu-layout.json are ABSENT INPUTS,
// not errors: the effective manifest then equals the base manifest.
//
// Exit codes:
//   0 — assembled successfully
//   1 — an input file is not valid JSON (message names the file)
//   2 — base src/manifest.json missing (Tier 0 — caller decides how to treat)
//
// Also require()-able as a module:
//   const { buildManifest, loadAppInputs, assembleFromDir } = require('./build_effective_manifest.js')

'use strict'

const fs = require('fs')
const path = require('path')

/**
 * Build an app's effective manifest from its bundled base, its modular
 * `manifest.d/*.json` fragments (ADR-037), and its `menu-layout.json`.
 * Ported verbatim from nextcloud-vue/src/utils/buildManifest.js.
 *
 * @param {object} base The bundled base manifest (`src/manifest.json`).
 * @param {Array<object>} [fragments] Fragment objects (each may carry `pages`/`menu`).
 * @param {object} [menuLayout] `{ relocations?, removals?, settingsSection? }`.
 * @return {object} The merged manifest: `{ ...base, pages, menu }`.
 */
function buildManifest(base, fragments = [], menuLayout = {}) {
	const merged = { ...base, pages: [...(base.pages || [])], menu: [] }
	mergeMenuItems(merged.menu, base.menu || [])
	for (const frag of (Array.isArray(fragments) ? fragments : [])) {
		if (frag && Array.isArray(frag.pages)) {
			mergePages(merged.pages, frag.pages)
		}
		if (frag && Array.isArray(frag.menu)) {
			mergeMenuItems(merged.menu, frag.menu)
		}
	}
	merged.menu = applyMenuLayout(merged.menu, menuLayout)
	return merged
}

/**
 * Apply the canonical navigation layout (`relocations` → `removals` →
 * `settingsSection`) to an already-merged menu.
 *
 * @param {Array<object>} menu The merged menu (mutated in place by the steps).
 * @param {object} [menuLayout] `{ relocations?, removals?, settingsSection? }`.
 * @return {Array<object>} The laid-out menu.
 */
function applyMenuLayout(menu, menuLayout = {}) {
	let out = applyMenuRelocations(menu, menuLayout.relocations)
	out = applyMenuRemovals(out, menuLayout.removals)
	out = applySettingsSection(out, menuLayout.settingsSection)
	return out
}

/**
 * Merge an array of incoming menu items into a target array, keyed by `id`.
 * New ids are appended; existing ids are merged in place: the first
 * definition of each listed key wins (the base manifest loads first, so its
 * canonical group definitions take precedence), and `children` are unioned
 * recursively by the same rule.
 *
 * @param {Array<object>} target The accumulated menu (mutated in place).
 * @param {Array<object>} incoming Menu items from a fragment.
 * @return {void}
 */
function mergeMenuItems(target, incoming) {
	incoming.forEach((item) => {
		const existing = target.find((t) => t.id === item.id)
		if (!existing) {
			target.push({ ...item, children: Array.isArray(item.children) ? [...item.children] : item.children })
			return
		}
		for (const key of ['label', 'icon', 'route', 'order', 'section', 'featureFlag', 'permission', 'visibleIf', 'href', 'action']) {
			if (existing[key] === undefined && item[key] !== undefined) {
				existing[key] = item[key]
			}
		}
		if (Array.isArray(item.children) && item.children.length > 0) {
			if (!Array.isArray(existing.children)) {
				existing.children = []
			}
			mergeMenuItems(existing.children, item.children)
		}
	})
}

/**
 * Merge fragment pages onto the accumulated page list by `id` — a later
 * declaration REPLACES an earlier one wholesale.
 *
 * @param {Array<object>} target Accumulated pages (mutated in place).
 * @param {Array<object>} incoming Pages from a fragment.
 * @return {void}
 */
function mergePages(target, incoming) {
	incoming.forEach((page) => {
		const idx = target.findIndex((p) => p.id === page.id)
		if (idx === -1) {
			target.push(page)
		} else {
			target[idx] = page
		}
	})
}

/**
 * Re-home merged menu entries onto the canonical navigation layout declared
 * by `menu-layout.json#relocations` (`{ sourceId: targetGroupId }`).
 * Runs in passes until stable; drops empty group shells left behind.
 *
 * @param {Array<object>} menu The merged menu (mutated in place).
 * @param {Record<string, string>|undefined} relocations Source-id → target-group-id map.
 * @return {Array<object>} The menu with relocations applied.
 */
function applyMenuRelocations(menu, relocations) {
	if (!relocations || typeof relocations !== 'object') return menu
	for (let pass = 0; pass < 5; pass++) {
		const moves = []
		for (let i = menu.length - 1; i >= 0; i--) {
			const node = menu[i]
			const target = relocations[node.id]
			if (target && target !== node.id) {
				menu.splice(i, 1)
				moves.push({ node, target })
				continue
			}
			if (!Array.isArray(node.children)) continue
			for (let j = node.children.length - 1; j >= 0; j--) {
				const child = node.children[j]
				const childTarget = relocations[child.id]
				if (!childTarget) continue
				if (childTarget === node.id && !Array.isArray(child.children)) continue
				node.children.splice(j, 1)
				moves.push({ node: child, target: childTarget })
			}
		}
		if (moves.length === 0) break
		moves.forEach(({ node, target }) => {
			const group = menu.find((m) => m.id === target)
			if (!group) {
				menu.push(node)
				return
			}
			if (!Array.isArray(group.children)) group.children = []
			if (Array.isArray(node.children)) {
				mergeMenuItems(group.children, node.children)
			} else {
				mergeMenuItems(group.children, [node])
			}
		})
	}
	// Drop empty group shells left behind by relocations.
	return menu.filter((m) => m.route || m.href || m.action
		|| (Array.isArray(m.children) && m.children.length > 0))
}

/**
 * Remove individual menu entries by id after relocation — used to retire
 * duplicate navigation entries whose PAGE must stay routable (ADR-044).
 * Only leaf entries are removed; a group is dropped only when left empty
 * and not itself clickable.
 *
 * @param {Array<object>} menu The merged menu.
 * @param {Array<string>|undefined} removals Menu-entry ids to drop.
 * @return {Array<object>} The menu without the removed entries.
 */
function applyMenuRemovals(menu, removals) {
	if (!Array.isArray(removals) || removals.length === 0) return menu
	const drop = new Set(removals)
	const wasGroup = (n) => Array.isArray(n.children) && n.children.length > 0
	const isClickable = (n) => n.route !== undefined || n.href !== undefined || n.action !== undefined
	const prune = (nodes) => nodes.reduce((acc, n) => {
		if (drop.has(n.id) && !wasGroup(n)) return acc
		if (Array.isArray(n.children)) {
			const children = prune(n.children)
			const hadChildren = wasGroup(n)
			if (children.length === 0 && hadChildren && !isClickable(n)) return acc
			acc.push({ ...n, children })
			return acc
		}
		acc.push(n)
		return acc
	}, [])
	return prune(menu)
}

/**
 * Promote the menu entries listed in `menu-layout.json#settingsSection` into
 * Nextcloud's settings foldout: lift each listed id out of wherever it sits,
 * tag it `section: "settings"`, flatten it, append to the top level.
 *
 * @param {Array<object>} menu The merged + relocated + pruned menu.
 * @param {Array<string>|undefined} settingsIds Entry ids to move to the foldout.
 * @return {Array<object>} The menu with the settings entries lifted out.
 */
function applySettingsSection(menu, settingsIds) {
	if (!Array.isArray(settingsIds) || settingsIds.length === 0) return menu
	const want = new Set(settingsIds)
	const isClickable = (n) => n.route !== undefined || n.href !== undefined || n.action !== undefined
	const lifted = []
	const strip = (nodes) => nodes.reduce((acc, n) => {
		if (want.has(n.id)) {
			const { children, ...leaf } = n
			lifted.push({ ...leaf, section: 'settings' })
			return acc
		}
		if (Array.isArray(n.children)) {
			const children = strip(n.children)
			if (children.length === 0 && n.children.length > 0 && !isClickable(n)) return acc
			acc.push({ ...n, children })
			return acc
		}
		acc.push(n)
		return acc
	}, [])
	const remaining = strip(menu)
	return [...remaining, ...lifted]
}

// --- hydra-side input loading (not part of the lib port) --------------------

/**
 * Load the three assembly inputs from an app repo root. Missing manifest.d/
 * and missing menu-layout.json are absent inputs (empty fragments / empty
 * layout), NOT errors. A missing base manifest or invalid JSON throws an
 * Error carrying `.code` ('ENOBASE' | 'EBADJSON') and the offending path.
 *
 * @param {string} appDir App repo root.
 * @return {{ base: object, fragments: Array<object>, fragmentFiles: Array<string>, menuLayout: object, menuLayoutPath: string|null, basePath: string }}
 */
function loadAppInputs(appDir) {
	const basePath = path.join(appDir, 'src', 'manifest.json')
	if (!fs.existsSync(basePath)) {
		const err = new Error(`base manifest missing at ${basePath}`)
		err.code = 'ENOBASE'
		throw err
	}
	const readJson = (file) => {
		try {
			return JSON.parse(fs.readFileSync(file, 'utf8'))
		} catch (e) {
			const err = new Error(`${file} is not valid JSON (${e.message})`)
			err.code = 'EBADJSON'
			throw err
		}
	}
	const base = readJson(basePath)
	const fragDir = path.join(appDir, 'src', 'manifest.d')
	let fragmentFiles = []
	if (fs.existsSync(fragDir) && fs.statSync(fragDir).isDirectory()) {
		// Ascending filename order — mirrors the lib caller's ctx.keys().sort().
		fragmentFiles = fs.readdirSync(fragDir)
			.filter((f) => f.endsWith('.json'))
			.sort()
			.map((f) => path.join(fragDir, f))
	}
	const fragments = fragmentFiles.map(readJson)
	const menuLayoutPath = path.join(appDir, 'src', 'menu-layout.json')
	let menuLayout = {}
	let hasLayout = false
	if (fs.existsSync(menuLayoutPath)) {
		menuLayout = readJson(menuLayoutPath)
		hasLayout = true
	}
	return {
		base,
		fragments,
		fragmentFiles,
		menuLayout,
		menuLayoutPath: hasLayout ? menuLayoutPath : null,
		basePath,
	}
}

/**
 * Assemble the effective manifest for an app repo root.
 *
 * @param {string} appDir App repo root.
 * @return {{ manifest: object, inputs: object }} The assembled manifest + the raw inputs.
 */
function assembleFromDir(appDir) {
	const inputs = loadAppInputs(appDir)
	const manifest = buildManifest(inputs.base, inputs.fragments, inputs.menuLayout)
	return { manifest, inputs }
}

module.exports = {
	buildManifest,
	applyMenuLayout,
	mergeMenuItems,
	mergePages,
	applyMenuRelocations,
	applyMenuRemovals,
	applySettingsSection,
	loadAppInputs,
	assembleFromDir,
}

// --- CLI --------------------------------------------------------------------

function cliMain() {
	let appDir = process.cwd()
	let outFile = null
	const argv = process.argv.slice(2)
	for (let i = 0; i < argv.length; i++) {
		if (argv[i] === '--app-dir' && argv[i + 1]) { appDir = path.resolve(argv[++i]); continue }
		if (argv[i] === '--out' && argv[i + 1]) { outFile = path.resolve(argv[++i]); continue }
		console.error(`[build_effective_manifest] unknown argument: ${argv[i]}`)
		process.exit(1)
	}
	let result
	try {
		result = assembleFromDir(appDir)
	} catch (e) {
		if (e.code === 'ENOBASE') {
			console.error(`[build_effective_manifest] ${e.message}`)
			process.exit(2)
		}
		console.error(`[build_effective_manifest] ${e.message}`)
		process.exit(1)
	}
	const { manifest, inputs } = result
	console.error(`[build_effective_manifest] base=${inputs.basePath} fragments=${inputs.fragmentFiles.length} menu-layout=${inputs.menuLayoutPath ? 'yes' : 'no'}`)
	const json = JSON.stringify(manifest, null, '\t') + '\n'
	if (outFile) {
		try {
			fs.writeFileSync(outFile, json)
		} catch (e) {
			console.error(`[build_effective_manifest] cannot write ${outFile} (${e.message})`)
			process.exit(1)
		}
	} else {
		process.stdout.write(json)
	}
	process.exit(0)
}

if (require.main === module) cliMain()
