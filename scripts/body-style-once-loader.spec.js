/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The body style loader turns @nextcloud/vue's per-component body style reads
 * into one read per property per page, and leaves everything else alone.
 *
 * @spec exclude Unit test of a build tooling script.
 */
const readBodyStyleOnce = require('./body-style-once-loader.js')

const NC_SELECT = `const avatarSize = Number.parseInt(window.getComputedStyle(document.body).getPropertyValue("--default-clickable-area")) - 2 * Number.parseInt(window.getComputedStyle(document.body).getPropertyValue('--default-grid-baseline'));
export { avatarSize };
`

/**
 * Evaluate a rewritten chunk with a counting getComputedStyle.
 *
 * @param {string} source The rewritten source
 * @param {object} values The body's custom properties
 * @return {{ result: number, reads: number }} The chunk's avatar size and the number of style reads
 */
function run(source, values) {
	let reads = 0
	const window = {
		getComputedStyle: () => {
			reads++
			return { getPropertyValue: (name) => values[name] }
		},
	}
	const body = source.replace('export { avatarSize };', 'return avatarSize;')
	const result = new Function('window', 'document', body)(window, { body: {} })
	return { result, reads }
}

describe('body style once loader', () => {
	afterEach(() => {
		delete globalThis.__cnBodyStyleValues
	})

	it('rewrites both quote styles to the per-page memo', () => {
		const out = readBodyStyleOnce(NC_SELECT)
		expect(out).not.toContain('getPropertyValue("--')
		expect(out).not.toContain("getPropertyValue('--")
		expect(out).toContain('__cnBodyStyleValue("--default-clickable-area")')
		expect(out).toContain("__cnBodyStyleValue('--default-grid-baseline')")
		expect(out).toContain('function __cnBodyStyleValue(name)')
	})

	it('computes the same value as the original', () => {
		const values = { '--default-clickable-area': '34px', '--default-grid-baseline': '4px' }
		const original = run(NC_SELECT, values)
		const rewritten = run(readBodyStyleOnce(NC_SELECT), values)
		expect(rewritten.result).toBe(original.result)
		expect(rewritten.result).toBe(26)
	})

	it('reads each property once per page however many components run it', () => {
		const values = { '--default-clickable-area': '34px', '--default-grid-baseline': '4px' }
		const chunk = readBodyStyleOnce(NC_SELECT)
		const reads = [1, 2, 3, 4, 5].map(() => run(chunk, values).reads)
		expect(reads).toEqual([2, 0, 0, 0, 0])
		expect(run(NC_SELECT, values).reads).toBe(2)
	})

	it('leaves a chunk without body style reads unchanged', () => {
		const other = 'const x = window.getComputedStyle(el).getPropertyValue("--a");\n'
		expect(readBodyStyleOnce(other)).toBe(other)
		expect(readBodyStyleOnce('')).toBe('')
	})

	it('can run twice on the same loader instance', () => {
		expect(readBodyStyleOnce(NC_SELECT)).toBe(readBodyStyleOnce(NC_SELECT))
	})
})
