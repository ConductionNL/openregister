#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
//
// test_manifest_scope_filter.js — verification for manifest_scope_filter.js.
//
// This module decides which gate-53 findings BLOCK a PR. Its failure modes are
// asymmetric and both are dangerous:
//
//   too wide  → gate-53 blocks every manifest-touching PR on inherited debt,
//               which is how the gate ends up switched off (the bug this
//               module was written to fix: pipelinq 24/24, shillinq 246/246).
//   too narrow → every finding becomes "pre-existing" and the gate silently
//               stops enforcing while continuing to print PASS.
//
// So every assertion here is paired: something must be suppressed AND something
// must survive, over the same manifest and the same finding set.
//
// Run: node scripts/lib/test_manifest_scope_filter.js   (exit 0 = pass)

'use strict'

const fs = require('fs')
const os = require('os')
const path = require('path')

const filter = require('./manifest_scope_filter.js')

let fails = 0
function assert(cond, label) {
	console.log((cond ? 'PASS — ' : 'FAIL — ') + label)
	if (!cond) fails++
}

const manifest = {
	menu: [
		{ id: 'Alpha', route: 'Alpha' },
		{ id: 'Group', children: [{ id: 'Nested', route: 'Nested' }] },
	],
	pages: [
		{ id: 'Alpha', type: 'index' },
		{ id: 'Beta', type: 'index' },
		{ id: 'Untitled' },
		{ type: 'index' }, // no id — unattributable on purpose
	],
	deepLinks: [{ registerSlug: 'r', schemaSlug: 's' }],
}

function scopeFrom(lines) {
	const f = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'scopefilter-')), 'scope')
	fs.writeFileSync(f, lines.join('\n') + '\n')
	return filter.loadScope(f)
}

// --- pointer attribution ----------------------------------------------------
assert(JSON.stringify(filter.tokensForPointer('/pages/1/config/widgets/0', manifest)) === '["page:Beta"]',
	'a deep pointer under a page attributes to that page id')
assert(filter.tokensForPointer('/pages/3', manifest) === null,
	'a page with no id is UNATTRIBUTABLE (null) — it must not be silently scoped out')
assert(filter.tokensForPointer('/pages/99', manifest) === null,
	'an out-of-range page index is unattributable, not "page:undefined"')
assert(JSON.stringify(filter.tokensForPointer('/menu/1/children/0', manifest)) === '["menu:Group","menu:Nested"]',
	'a nested menu pointer collects BOTH the ancestor and the leaf id')
assert(JSON.stringify(filter.tokensForPointer('/deepLinks/0/urlTemplate', manifest)) === '["key:deepLinks"]',
	'a top-level block attributes to its key')
assert(filter.tokensForPointer('/', manifest) === null,
	'the root pointer is a whole-document finding (null → always blocking)')
assert(filter.tokensForPointer('', manifest) === null,
	'an empty pointer is a whole-document finding')

// --- partition, both directions ---------------------------------------------
const findings = [
	{ path: '/pages/0', message: 'alpha finding' },
	{ path: '/pages/1', message: 'beta finding' },
	{ path: '/menu/1/children/0', message: 'nested menu finding' },
	{ path: '/deepLinks/0', message: 'deeplink finding' },
	{ path: '/pages/3', message: 'unattributable finding' },
]

{
	const parts = filter.partition(findings, manifest, scopeFrom(['page:Beta']))
	const blockingPaths = parts.blocking.map((f) => f.path).sort()
	assert(blockingPaths.join(',') === '/pages/1,/pages/3',
		'scope page:Beta → only the Beta finding blocks, plus the unattributable one')
	assert(parts.preexisting.length === 3,
		'the other three are reported as PRE-EXISTING, not discarded')
	assert(parts.unscopable.length === 1,
		'the unattributable finding is counted as whole-manifest')
}

{
	// Reverse direction over the SAME inputs: change only the scope.
	const parts = filter.partition(findings, manifest, scopeFrom(['page:Alpha']))
	assert(parts.blocking.some((f) => f.path === '/pages/0'),
		'scope page:Alpha → the Alpha finding blocks (the filter can select either way)')
	assert(!parts.blocking.some((f) => f.path === '/pages/1'),
		'…and Beta is now the suppressed one')
}

{
	const parts = filter.partition(findings, manifest, scopeFrom(['menu:Nested']))
	assert(parts.blocking.some((f) => f.path === '/menu/1/children/0'),
		'a menu-leaf scope token blocks the leaf finding')
}

{
	const parts = filter.partition(findings, manifest, scopeFrom(['ALLMENU']))
	assert(parts.blocking.some((f) => f.path === '/menu/1/children/0'),
		'ALLMENU puts every menu finding in scope')
	assert(!parts.blocking.some((f) => f.path === '/pages/0'),
		'…but ALLMENU does not drag pages in')
}

{
	const parts = filter.partition(findings, manifest, scopeFrom(['ALL']))
	assert(parts.blocking.length === findings.length && parts.preexisting.length === 0,
		'ALL → nothing is scoped out')
}

{
	const parts = filter.partition(findings, manifest, null)
	assert(parts.blocking.length === findings.length,
		'no scope at all (full-repo run) → every finding blocks')
}

{
	const parts = filter.partition(findings, manifest, filter.loadScope('/nonexistent/scope/file'))
	assert(parts.blocking.length === findings.length,
		'an UNREADABLE scope file is an unknown scope, not an empty one — everything blocks')
}

console.log('')
if (fails > 0) {
	console.log(`${fails} manifest_scope_filter assertion(s) FAILED`)
	process.exit(1)
}
console.log('ALL manifest_scope_filter assertions PASSED')
