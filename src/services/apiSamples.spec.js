/**
 * The curl and fetch samples for a list and a create call (api-explorer-in-the-app, task 2.5).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

import { apiSamples, APP_PASSWORD_PLACEHOLDER, operationUrl, shellQuote } from './apiSamples.js'

const INSTANCE = 'https://cloud.example.org/index.php/apps/openregister/'

describe('apiSamples', () => {
	it('samples a list call with its query', () => {
		const samples = apiSamples(
			{ method: 'get', path: '/api/objects/{register}/{schema}', pathParams: { register: 'publication', schema: 'document' }, query: { _limit: 20, _page: 2, _search: '' } },
			INSTANCE,
		)

		expect(samples.url).toBe('https://cloud.example.org/index.php/apps/openregister/api/objects/publication/document?_limit=20&_page=2')
		expect(samples.curl).toBe(
			"curl -u 'USERNAME:APP_PASSWORD' \\\n"
			+ "  'https://cloud.example.org/index.php/apps/openregister/api/objects/publication/document?_limit=20&_page=2' \\\n"
			+ "  -H 'Accept: application/json'",
		)
		expect(samples.fetch).toBe([
			"const response = await fetch('https://cloud.example.org/index.php/apps/openregister/api/objects/publication/document?_limit=20&_page=2', {",
			"\tmethod: 'GET',",
			'\theaders: {',
			"\t\tAccept: 'application/json',",
			"\t\tAuthorization: 'Basic ' + btoa('USERNAME:APP_PASSWORD'),",
			'\t},',
			'})',
			'const data = await response.json()',
		].join('\n'))
	})

	it('samples a create call with its JSON body', () => {
		const samples = apiSamples(
			{ method: 'POST', path: '/api/objects/{register}/{schema}', pathParams: { register: 7, schema: 12 }, body: { title: "Raad's besluit" } },
			INSTANCE,
		)

		expect(samples.curl).toBe(
			"curl -u 'USERNAME:APP_PASSWORD' \\\n"
			+ '  -X POST \\\n'
			+ "  'https://cloud.example.org/index.php/apps/openregister/api/objects/7/12' \\\n"
			+ "  -H 'Accept: application/json' \\\n"
			+ "  -H 'Content-Type: application/json' \\\n"
			+ "  --data '{\n  \"title\": \"Raad'\\''s besluit\"\n}'",
		)
		expect(samples.fetch).toBe([
			"const response = await fetch('https://cloud.example.org/index.php/apps/openregister/api/objects/7/12', {",
			"\tmethod: 'POST',",
			'\theaders: {',
			"\t\tAccept: 'application/json',",
			"\t\tAuthorization: 'Basic ' + btoa('USERNAME:APP_PASSWORD'),",
			"\t\t'Content-Type': 'application/json',",
			'\t},',
			'\tbody: JSON.stringify({',
			'\t  "title": "Raad\'s besluit"',
			'\t}),',
			'})',
			'const data = await response.json()',
		].join('\n'))
	})

	it('sends no body on a delete and keeps an unfilled path parameter visible', () => {
		const samples = apiSamples({ method: 'delete', path: '/api/objects/{register}/{schema}/{id}', pathParams: { register: 'r', schema: 's' }, body: { ignored: true } }, INSTANCE)

		expect(samples.url).toBe('https://cloud.example.org/index.php/apps/openregister/api/objects/r/s/{id}')
		expect(samples.curl).toContain('-X DELETE')
		expect(samples.curl).not.toContain('--data')
		expect(samples.fetch).not.toContain('body:')
	})

	it('never carries a real credential', () => {
		const { curl, fetch } = apiSamples({ method: 'GET', path: '/api/registers' }, INSTANCE)

		expect(curl).toContain(APP_PASSWORD_PLACEHOLDER)
		expect(fetch).toContain(APP_PASSWORD_PLACEHOLDER)
		expect(fetch).not.toMatch(/requesttoken/i)
	})

	it('encodes path values and joins the instance URL with exactly one slash', () => {
		expect(operationUrl('https://x.org/base', 'api/objects/{id}', { id: 'a b/c' })).toBe('https://x.org/base/api/objects/a%20b%2Fc')
		expect(operationUrl('https://x.org/base//', '//api')).toBe('https://x.org/base/api')
	})

	it('quotes a value for the shell', () => {
		expect(shellQuote("it's")).toBe("'it'\\''s'")
		expect(shellQuote('$HOME')).toBe("'$HOME'")
	})
})
