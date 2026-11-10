/**
 * @jest-environment node
 */
/**
 * Golden record of the compiled styles of the API token section:
 * every selector with its declarations, independent of how the SCSS that
 * produces them is organised. Grouping rules that share declarations must leave
 * this record unchanged.
 *
 * @spec exclude Golden record of compiled page styles, no behaviour.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

const fs = require('fs')
const path = require('path')
const sass = require('sass')
const { pathToFileURL } = require('url')

/**
 * Compile the `<style>` block of a view and fold it into selector → declarations.
 *
 * @param {string} file The view, relative to this folder
 * @return {object} Sorted selectors, each with its sorted declarations
 */
function compiledRules(file) {
	const source = fs.readFileSync(path.join(__dirname, file), 'utf8')
	const scss = source.match(/<style[^>]*>([\s\S]*?)<\/style>/)[1]
	const { css } = sass.compileString(scss, { url: pathToFileURL(path.join(__dirname, file)) })
	const rules = {}
	for (const [, selectors, body] of css.matchAll(/([^{}]+)\{([^{}]*)\}/g)) {
		for (const selector of selectors.split(',').map((one) => one.trim().replace(/\s+/g, ' '))) {
			rules[selector] = rules[selector] || {}
			for (const declaration of body.split(';')) {
				const at = declaration.indexOf(':')
				if (at > 0) {
					rules[selector][declaration.slice(0, at).trim()] = declaration.slice(at + 1).trim().replace(/\s+/g, ' ')
				}
			}
		}
	}
	return Object.fromEntries(Object.keys(rules).sort().map((selector) => [selector, Object.fromEntries(Object.entries(rules[selector]).sort())]))
}

describe('API token section styles', () => {
	it.each(['ApiTokenConfiguration.vue'])('%s compiles to the same rules', (file) => {
		expect(compiledRules(file)).toMatchSnapshot()
	})
})
