#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
//
// manifest_scope_filter.js — ADR-020 diff scoping for manifest findings.
//
// Shared by check_manifest.js and check_manifest_crossref.js (gate-53). Given
// the scope token file produced by manifest_diff_scope.py and the ASSEMBLED
// manifest, decide whether a finding BLOCKS this PR or is pre-existing debt on
// an entry the PR never touched.
//
// The split this module encodes:
//
//   ANSWERING a cross-reference needs the whole assembled manifest — you cannot
//   resolve `menu[].route` → page id from a diff. That is why gate-53 is, and
//   must remain, a whole-manifest CHECK.
//
//   BLOCKING on the answer does not. Every finding carries a JSON pointer into
//   the assembled manifest, and that pointer resolves to a page id, a menu id
//   or a top-level block. If the PR did not touch that entry, the finding is
//   inherited debt and must not block — ADR-020.
//
// Findings that resolve to NOTHING addressable (pointer `/`, an assembly
// failure, a whole-document invariant) stay blocking under every scope. Those
// are the legitimately-whole-repo part of this gate, and they are reported
// under their own heading so the distinction is visible rather than assumed.
//
// Out-of-scope findings are NOT discarded: they are printed as
// `at <ptr>: PRE-EXISTING <message>` so a reader can still see the debt, and
// counted separately. Silently dropping them would replace one invisible
// failure with another.

'use strict'

const fs = require('fs')

/**
 * Read a scope token file written by manifest_diff_scope.py.
 *
 * @param {string} file Path to the token file (one token per line).
 * @return {object|null} `{ all, allMenu, pages:Set, menu:Set, keys:Set }`, or
 *   null when no scoping applies (no file given, or unreadable → full-repo).
 */
function loadScope(file) {
	if (!file) return null
	let text
	try {
		text = fs.readFileSync(file, 'utf8')
	} catch (_) {
		// An unreadable scope file is an unknown scope, not an empty one.
		return { all: true, allMenu: true, pages: new Set(), menu: new Set(), keys: new Set() }
	}
	const scope = { all: false, allMenu: false, pages: new Set(), menu: new Set(), keys: new Set() }
	for (const raw of text.split('\n')) {
		const line = raw.trim()
		if (!line || line.startsWith('#')) continue
		if (line === 'ALL') { scope.all = true; continue }
		if (line === 'ALLMENU') { scope.allMenu = true; continue }
		const idx = line.indexOf(':')
		if (idx <= 0) continue
		const kind = line.slice(0, idx)
		const value = line.slice(idx + 1)
		if (kind === 'page') scope.pages.add(value)
		else if (kind === 'menu') scope.menu.add(value)
		else if (kind === 'key') scope.keys.add(value)
	}
	return scope
}

function decodeSegment(seg) {
	return seg.replace(/~1/g, '/').replace(/~0/g, '~')
}

/**
 * Resolve a JSON pointer into the assembled manifest to the scope tokens the
 * finding is attributable to.
 *
 * @param {string} ptr JSON pointer, e.g. `/pages/12/config/widgets/0`.
 * @param {object} manifest The ASSEMBLED manifest.
 * @return {Array<string>|null} Tokens, or null when the finding addresses
 *   nothing scopable (a whole-document invariant → always blocking).
 */
function tokensForPointer(ptr, manifest) {
	if (typeof ptr !== 'string' || ptr === '' || ptr === '/') return null
	const segs = ptr.replace(/^\//, '').split('/').map(decodeSegment)
	if (segs.length === 0) return null
	const head = segs[0]

	if (head === 'pages') {
		const idx = Number(segs[1])
		const pages = Array.isArray(manifest && manifest.pages) ? manifest.pages : []
		const page = Number.isInteger(idx) ? pages[idx] : undefined
		const id = page && typeof page.id === 'string' ? page.id : null
		// A page whose id cannot be read cannot be attributed — block it.
		return id ? ['page:' + id] : null
	}

	if (head === 'menu') {
		// Walk /menu/<i>[/children/<j>...] collecting every id on the path, so a
		// finding on a leaf is in scope when either the leaf OR an ancestor was
		// edited (editing a parent's `order` legitimately re-homes its children).
		const tokens = []
		let node = Array.isArray(manifest && manifest.menu) ? manifest.menu : []
		let i = 1
		let cursor = node[Number(segs[i])]
		while (cursor && typeof cursor === 'object') {
			const id = typeof cursor.id === 'string' ? cursor.id : (typeof cursor.route === 'string' ? cursor.route : null)
			if (id) tokens.push('menu:' + id)
			i += 1
			if (segs[i] !== 'children' || !Array.isArray(cursor.children)) break
			i += 1
			cursor = cursor.children[Number(segs[i])]
		}
		return tokens.length ? tokens : null
	}

	// Anything else is a top-level block: /observability/..., /deepLinks/0/...,
	// /menu-layout/removals/2, /version, ...
	return ['key:' + head]
}

/**
 * Partition findings into blocking vs pre-existing under a diff scope.
 *
 * @param {Array<object>} findings Each `{ path, message, ... }`.
 * @param {object} manifest The assembled manifest.
 * @param {object|null} scope Result of loadScope(); null → no scoping.
 * @return {object} `{ blocking, preexisting, unscopable }`.
 */
function partition(findings, manifest, scope) {
	if (!scope || scope.all) {
		return { blocking: findings.slice(), preexisting: [], unscopable: [] }
	}
	const blocking = []
	const preexisting = []
	const unscopable = []
	for (const f of findings) {
		const tokens = tokensForPointer(f.path, manifest)
		if (tokens === null) {
			unscopable.push(f)
			blocking.push(f)
			continue
		}
		const hit = tokens.some((t) => {
			if (t.startsWith('page:')) return scope.pages.has(t.slice(5))
			if (t.startsWith('menu:')) return scope.allMenu || scope.menu.has(t.slice(5))
			if (t.startsWith('key:')) {
				const key = t.slice(4)
				if (key === 'menu' && scope.allMenu) return true
				if (key === 'menu-layout' && scope.allMenu) return true
				return scope.keys.has(key)
			}
			return true
		})
		if (hit) blocking.push(f)
		else preexisting.push(f)
	}
	return { blocking, preexisting, unscopable }
}

/**
 * Emit the standard accounting lines for a scoped run.
 *
 * @param {string} tag Helper name for the log prefix.
 * @param {object} parts Result of partition().
 * @return {void}
 */
function reportScope(tag, parts) {
	if (parts.preexisting.length === 0 && parts.unscopable.length === 0) return
	for (const f of parts.preexisting) {
		console.error(`at ${f.path || '/'}: PRE-EXISTING ${String(f.message).split('\n')[0]}`)
	}
	if (parts.preexisting.length > 0) {
		console.error(`[${tag}] diff-scope (ADR-020): ${parts.preexisting.length} finding(s) sit on manifest entries this PR did not touch — reported above as PRE-EXISTING, not blocking.`)
	}
	if (parts.unscopable.length > 0) {
		console.error(`[${tag}] ${parts.unscopable.length} finding(s) address the manifest as a WHOLE (no page/menu entry to attribute them to) and block regardless of scope.`)
	}
}

module.exports = { loadScope, tokensForPointer, partition, reportScope }
