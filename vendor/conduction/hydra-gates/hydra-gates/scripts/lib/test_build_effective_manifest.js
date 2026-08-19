#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
//
// test_build_effective_manifest.js — merge-semantics self-test for the
// gate-30 vendored effective-manifest builder (build_effective_manifest.js).
//
// These assertions PIN the observable buildManifest behaviour ported from
// nextcloud-vue/src/utils/buildManifest.js (see the sync note there). If the
// lib's merge semantics change, this file is where the vendored port catches
// up. Covers: fragment filename order, page replace-by-id, keyed menu merge
// (first scalar wins, children unioned), relocations, removals,
// settingsSection — plus assembly from the good fixture directory.
//
// Run: node scripts/lib/test_build_effective_manifest.js   (exit 0 = pass)

'use strict'

const fs = require('fs')
const path = require('path')
const {
	buildManifest,
	mergeMenuItems,
	mergePages,
	applyMenuRelocations,
	applyMenuRemovals,
	applySettingsSection,
	assembleFromDir,
} = require('./build_effective_manifest.js')

// --- guard the guard --------------------------------------------------------
// The last block of this file assembles from
// scripts/test-fixtures/effective-manifest/good/. Until 2026-08-04 that
// directory did not exist and nothing in CI ran this file, so the in-memory
// assertions above it reported PASS and the run then died on an uncaught
// ENOBASE stack trace. Fail with a cause instead — and before, not after, the
// misleading passes.
{
	const goodDir = path.resolve(__dirname, '..', 'test-fixtures', 'effective-manifest', 'good')
	const required = [
		'src/manifest.json',
		'src/manifest.d/10-archive.json',
		'src/manifest.d/20-settings.json',
		'src/menu-layout.json',
	]
	const missing = required.filter((rel) => !fs.existsSync(path.join(goodDir, rel)))
	if (missing.length > 0) {
		console.log(`FAIL — ${missing.length} fixture file(s) MISSING under ${goodDir}; this suite cannot assert anything:`)
		for (const rel of missing) console.log(`    ${rel}`)
		console.log('')
		console.log('Refusing to run. The assembly assertions at the end of this file need them,')
		console.log('and reporting the in-memory passes above without them would announce a green')
		console.log('for a suite that never reached its integration leg.')
		process.exit(1)
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

// --- pages: replace-by-id, later fragment wins -------------------------------
{
	const target = [{ id: 'a', title: 'base-a' }, { id: 'b', title: 'base-b' }]
	mergePages(target, [{ id: 'b', title: 'frag-b' }, { id: 'c', title: 'frag-c' }])
	assert(target.length === 3, 'mergePages: new page appended')
	assert(target.find((p) => p.id === 'b').title === 'frag-b', 'mergePages: fragment page REPLACES base page by id (wholesale)')
	assert(target[1].id === 'b', 'mergePages: replaced page keeps its position')
}

// --- menu: keyed merge, first scalar wins, children unioned ------------------
{
	const target = []
	mergeMenuItems(target, [{ id: 'g', label: 'base-label', order: 10, children: [{ id: 'c1', label: 'c1' }] }])
	mergeMenuItems(target, [{ id: 'g', label: 'frag-label', icon: 'frag-icon', order: 99, children: [{ id: 'c2', label: 'c2' }, { id: 'c1', label: 'c1-dup' }] }])
	const g = target.find((t) => t.id === 'g')
	assert(g.label === 'base-label' && g.order === 10, 'mergeMenuItems: first definition of a scalar key wins')
	assert(g.icon === 'frag-icon', 'mergeMenuItems: fragment fills a key the base left undefined')
	assert(g.children.length === 2 && g.children[0].label === 'c1', 'mergeMenuItems: children unioned by id (no dup, first def wins)')
}

// --- buildManifest: fragment order end-to-end --------------------------------
{
	const base = { version: '1.0.0', pages: [{ id: 'p1', title: 'base' }], menu: [{ id: 'm1', label: 'one' }] }
	const frag10 = { pages: [{ id: 'p2', title: 'from-10' }] }
	const frag20 = { pages: [{ id: 'p2', title: 'from-20' }], menu: [{ id: 'm2', label: 'two' }] }
	const out = buildManifest(base, [frag10, frag20], {})
	assert(out.pages.length === 2 && out.pages.find((p) => p.id === 'p2').title === 'from-20', 'buildManifest: later fragment (ascending filename order) wins replace-by-id')
	assert(out.menu.length === 2, 'buildManifest: base + fragment menus merged')
	assert(base.pages.length === 1 && base.menu.length === 1, 'buildManifest: base object not mutated')
}

// --- relocations ---------------------------------------------------------------
{
	// Leaf moves under target group; group dissolves into target; missing
	// target keeps the entry at top level; empty shells dropped.
	const menu = [
		{ id: 'group-a', label: 'A', children: [{ id: 'leaf-1', label: 'L1', route: 'r1' }] },
		{ id: 'group-b', label: 'B', children: [{ id: 'leaf-2', label: 'L2', route: 'r2' }] },
		{ id: 'leaf-3', label: 'L3', route: 'r3' },
	]
	const out = applyMenuRelocations(menu, { 'leaf-3': 'group-a', 'group-b': 'group-a', 'leaf-9': 'nowhere' })
	const a = out.find((m) => m.id === 'group-a')
	assert(a && a.children.some((c) => c.id === 'leaf-3'), 'relocations: leaf moves under the target group')
	assert(a && a.children.some((c) => c.id === 'leaf-2'), 'relocations: relocated GROUP dissolves — its children merge into the target')
	assert(!out.some((m) => m.id === 'group-b'), 'relocations: dissolved group shell dropped')
}
{
	const menu = [{ id: 'lonely', label: 'L', route: 'r' }]
	const out = applyMenuRelocations(menu, { lonely: 'ghost-group' })
	assert(out.length === 1 && out[0].id === 'lonely', 'relocations: missing target keeps the entry at top level (nothing silently disappears)')
}

// --- removals -------------------------------------------------------------------
{
	const menu = [
		{ id: 'group', label: 'G', children: [{ id: 'dup', label: 'D', route: 'r1' }, { id: 'keep', label: 'K', route: 'r1' }] },
		{ id: 'clickable-group', label: 'CG', route: 'rg', children: [{ id: 'only-child', label: 'OC', route: 'r2' }] },
	]
	const out = applyMenuRemovals(menu, ['dup', 'only-child'])
	const g = out.find((m) => m.id === 'group')
	assert(g && g.children.length === 1 && g.children[0].id === 'keep', 'removals: leaf entry dropped, sibling with same route survives')
	assert(out.some((m) => m.id === 'clickable-group'), 'removals: a clickable group survives even when all children removed')
}

// --- settingsSection -------------------------------------------------------------
{
	const menu = [
		{ id: 'group', label: 'G', children: [{ id: 'cfg', label: 'Config', route: 'settings-page' }, { id: 'other', label: 'O', route: 'r' }] },
	]
	const out = applySettingsSection(menu, ['cfg'])
	const lifted = out.find((m) => m.id === 'cfg')
	assert(lifted && lifted.section === 'settings', 'settingsSection: listed entry lifted to top level with section:"settings"')
	assert(out[out.length - 1].id === 'cfg', 'settingsSection: lifted entry appended after remaining entries')
	assert(out.find((m) => m.id === 'group').children.length === 1, 'settingsSection: entry stripped from its original group')
}

// --- absent inputs: effective == base --------------------------------------------
{
	const base = { version: '1.0.0', pages: [{ id: 'p' }], menu: [{ id: 'm', label: 'M', route: 'p' }] }
	const out = buildManifest(base, [], {})
	assert(JSON.stringify(out.pages) === JSON.stringify(base.pages)
		&& JSON.stringify(out.menu) === JSON.stringify([{ id: 'm', label: 'M', route: 'p' }]),
	'buildManifest: no fragments + no menu-layout → effective equals base')
}

// --- assembly from the good fixture dir (file ordering + full pipeline) -----------
{
	const goodDir = path.resolve(__dirname, '..', 'test-fixtures', 'effective-manifest', 'good')
	const { manifest, inputs } = assembleFromDir(goodDir)
	assert(inputs.fragmentFiles.length === 2
		&& path.basename(inputs.fragmentFiles[0]) === '10-archive.json'
		&& path.basename(inputs.fragmentFiles[1]) === '20-settings.json',
	'assembleFromDir: fragments gathered in ascending filename order')
	const settings = manifest.pages.find((p) => p.id === 'settings-page')
	assert(settings && settings.component === 'SettingsPage', 'assembleFromDir: 20-settings.json replaces the 10-archive.json page (later fragment wins)')
	assert(!manifest.menu.some(function walk(m) { return m.id === 'items-index-duplicate' || (m.children || []).some(walk) }),
		'assembleFromDir: menu-layout removals applied (duplicate entry gone)')
	const settingsEntry = manifest.menu.find((m) => m.id === 'app-settings-entry')
	assert(settingsEntry && settingsEntry.section === 'settings', 'assembleFromDir: settingsSection applied')
	const itemsGroup = manifest.menu.find((m) => m.id === 'items-group')
	assert(itemsGroup && itemsGroup.children.some((c) => c.id === 'reports-entry'), 'assembleFromDir: relocations applied (reports-entry under items-group)')
	assert(itemsGroup.children.find((c) => c.id === 'reports-entry').order === 20, 'assembleFromDir: keyed menu merge — base scalar (order 20) beat the fragment re-declaration (25)')
}

console.log('')
if (fails === 0) {
	console.log('ALL build_effective_manifest merge-semantics assertions PASSED')
	process.exit(0)
}
console.log(`${fails} build_effective_manifest assertion(s) FAILED`)
process.exit(1)
