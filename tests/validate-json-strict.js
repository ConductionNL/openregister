#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// validate-json-strict.js — strict JSON parse of the structured config files
// (src/manifest.json and lib/Settings/*_register.json) that rejects DUPLICATE
// KEYS. Standard JSON.parse() silently keeps the last duplicate, which is how a
// bad merge of a register/manifest file can silently drop a schema or page (git
// merges JSON line-by-line and will happily produce a document with two
// `"Enrolment"` keys — see the scholiq Wave-2 incident). This guard fails CI on
// any such corruption before it reaches development.
//
// Usage:   node tests/validate-json-strict.js
// Exit:    0 = all config JSON parses with no duplicate keys
//          1 = a duplicate key (or unparseable JSON) was found
//
// It also flags `appendOnly: true` nested inside an `x-openregister` block on a
// schema — OpenRegister's Schema::hydrate() only reads a TOP-LEVEL `appendOnly`,
// so a nested one is silently dropped and the schema is not actually append-only.

'use strict'

const fs = require('fs')
const path = require('path')

const REPO_ROOT = path.resolve(__dirname, '..')

// Files to check: the manifest + every *_register.json under lib/Settings/.
function targetFiles() {
	const files = []
	const manifest = path.join(REPO_ROOT, 'src', 'manifest.json')
	if (fs.existsSync(manifest)) files.push(manifest)
	const settingsDir = path.join(REPO_ROOT, 'lib', 'Settings')
	if (fs.existsSync(settingsDir)) {
		for (const f of fs.readdirSync(settingsDir)) {
			if (f.endsWith('_register.json') || f.endsWith('-register.json')) {
				files.push(path.join(settingsDir, f))
			}
		}
	}
	return files
}

// Parse `text` and throw if any object literal contains a duplicate key.
// `pathPrefix` is the JSON-pointer-ish path used in the error message.
function parseStrict(text, label) {
	const dupErrors = []
	const reviverPathStack = []
	// JSON.parse's reviver can't see duplicates (the object is already
	// collapsed). So we re-implement just enough: tokenise object keys.
	// Simpler robust approach: walk the raw text with a tiny tokenizer.
	let i = 0
	const n = text.length
	function err(msg) {
		throw new SyntaxError(`${label}: ${msg} (at offset ${i})`)
	}
	function skipWs() {
		while (i < n && (text[i] === ' ' || text[i] === '\t' || text[i] === '\n' || text[i] === '\r')) i++
	}
	function readString() {
		// assumes text[i] === '"'
		i++
		let s = ''
		while (i < n) {
			const c = text[i++]
			if (c === '"') return s
			if (c === '\\') {
				const e = text[i++]
				if (e === 'u') { s += '\\u' + text.slice(i, i + 4); i += 4 }
				else s += '\\' + e
			} else s += c
		}
		err('unterminated string')
	}
	function readValue(pathStr) {
		skipWs()
		const c = text[i]
		if (c === '{') return readObject(pathStr)
		if (c === '[') return readArray(pathStr)
		if (c === '"') { readString(); return }
		// number / true / false / null — scan to a delimiter
		while (i < n && !',}] \t\n\r'.includes(text[i])) i++
	}
	function readArray(pathStr) {
		i++ // [
		skipWs()
		if (text[i] === ']') { i++; return }
		let idx = 0
		// eslint-disable-next-line no-constant-condition
		while (true) {
			readValue(`${pathStr}/${idx}`)
			idx++
			skipWs()
			if (text[i] === ',') { i++; continue }
			if (text[i] === ']') { i++; return }
			err('expected , or ] in array')
		}
	}
	function readObject(pathStr) {
		i++ // {
		skipWs()
		const keys = new Set()
		const objKeys = []
		if (text[i] === '}') { i++; return }
		// eslint-disable-next-line no-constant-condition
		while (true) {
			skipWs()
			if (text[i] !== '"') err('expected string key in object')
			const key = readString()
			if (keys.has(key)) {
				dupErrors.push(`${pathStr || '/'}: DUPLICATE KEY "${key}"`)
			}
			keys.add(key)
			objKeys.push(key)
			skipWs()
			if (text[i] !== ':') err('expected : after key')
			i++
			readValue(`${pathStr}/${key}`)
			// Flag nested appendOnly on a schema's x-openregister block:
			// path looks like /components/schemas/<Name>/x-openregister and key === 'appendOnly'
			if (key === 'appendOnly' && /\/x-openregister$/.test(pathStr)) {
				dupErrors.push(`${pathStr}/appendOnly: nested inside x-openregister — OpenRegister only reads a TOP-LEVEL appendOnly; move it to the schema root`)
			}
			skipWs()
			if (text[i] === ',') { i++; continue }
			if (text[i] === '}') { i++; return }
			err('expected , or } in object')
		}
	}
	readValue('')
	skipWs()
	if (i < n) err('trailing content after JSON value')
	if (dupErrors.length > 0) {
		const e = new SyntaxError(`${label}:\n  - ${dupErrors.join('\n  - ')}`)
		e.dupErrors = dupErrors
		throw e
	}
}

function main() {
	const files = targetFiles()
	if (files.length === 0) {
		console.log('[validate-json-strict] no manifest or *_register.json found — nothing to check')
		process.exit(0)
	}
	let failed = false
	for (const file of files) {
		const label = path.relative(REPO_ROOT, file)
		let text
		try {
			text = fs.readFileSync(file, 'utf8')
		} catch (e) {
			console.error(`[validate-json-strict] cannot read ${label}: ${e.message}`)
			failed = true
			continue
		}
		// First: must be valid JSON at all.
		try {
			JSON.parse(text)
		} catch (e) {
			console.error(`[validate-json-strict] ${label}: invalid JSON — ${e.message}`)
			failed = true
			continue
		}
		// Then: strict pass (duplicate keys, nested appendOnly).
		try {
			parseStrict(text, label)
			console.log(`[validate-json-strict] ${label}: OK`)
		} catch (e) {
			console.error(`[validate-json-strict] ${e.message}`)
			failed = true
		}
	}
	if (failed) {
		console.error('[validate-json-strict] FAIL — fix the issues above.')
		process.exit(1)
	}
	console.log('[validate-json-strict] PASS')
	process.exit(0)
}

main()
