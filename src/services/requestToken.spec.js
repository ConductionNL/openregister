/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import fs from 'fs'
import path from 'path'
import { withRequestToken } from './requestToken.js'

const SRC = path.resolve(__dirname, '..')
const WRITE = /method\s*:\s*[^,}]*\b(POST|PUT|PATCH|DELETE)\b/i
const TOKEN = /withRequestToken|requesttoken|getRequestToken|OCS-APIRequest/i

/**
 * Every .js/.vue/.ts source under a directory, specs excluded.
 *
 * @param {string} dir The directory.
 * @return {string[]} The file paths.
 */
function sourceFiles(dir) {
	return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
		const full = path.join(dir, entry.name)
		if (entry.isDirectory()) {
			return entry.name === '__snapshots__' ? [] : sourceFiles(full)
		}
		return /\.(js|vue|ts)$/.test(entry.name) && !/\.(spec|test)\./.test(entry.name) ? [full] : []
	})
}

/**
 * The text of every fetch(...) call in a source, up to its closing paren.
 *
 * @param {string} text The source.
 * @return {Array<{line: number, call: string}>} The calls.
 */
function fetchCalls(text) {
	const calls = []
	const re = /(?<![\w.])fetch\s*\(/g
	let match
	while ((match = re.exec(text)) !== null) {
		let i = re.lastIndex
		let depth = 1
		while (i < text.length && depth > 0) {
			if ('([{'.includes(text[i])) depth++
			else if (')]}'.includes(text[i])) depth--
			i++
		}
		calls.push({ line: text.slice(0, match.index).split('\n').length, call: text.slice(match.index, i) })
	}
	return calls
}

describe('withRequestToken', () => {
	it('adds the request token and keeps the other options and headers', () => {
		expect(withRequestToken({ method: 'POST', body: '{}', headers: { 'Content-Type': 'application/json' } })).toEqual({
			method: 'POST',
			body: '{}',
			headers: { 'Content-Type': 'application/json', requesttoken: 'test-request-token' },
		})
	})

	it('builds the headers when the options have none', () => {
		expect(withRequestToken()).toEqual({ headers: { requesttoken: 'test-request-token' } })
	})
})

describe('fetch() writes send the request token', () => {
	it('has no raw fetch() write without the token', () => {
		const missing = []
		for (const file of sourceFiles(SRC)) {
			for (const { line, call } of fetchCalls(fs.readFileSync(file, 'utf8'))) {
				if (WRITE.test(call) && !TOKEN.test(call)) {
					missing.push(`${path.relative(SRC, file)}:${line}`)
				}
			}
		}
		expect(missing).toEqual([])
	})
})
