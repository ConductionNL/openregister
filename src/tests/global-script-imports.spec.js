/**
 * @jest-environment node
 */

/**
 * The script Nextcloud loads on every page imports no package barrel.
 *
 * WHY THIS EXISTS. `openregister-integration-global.js` is added to every
 * Nextcloud page (addInitScript), so every byte in it is paid on the Files
 * page, the Dashboard and every fleet app page. On 25 Nov 2026 it weighed
 * 7.4 MB and pulled an 8.6 MB async chunk on top: one file imported from the
 * @conduction/nextcloud-vue barrel (which keeps every component, since the
 * package marks `.vue` modules as side effects), one dynamically imported that
 * same barrel, and one imported two buttons from the @nextcloud/vue index
 * (which carries the emoji picker, the date picker, dialogs and all of
 * @mdi/js). Named imports read cheap in review and are not.
 *
 * So this walks the entry's relative import graph, static and dynamic, and
 * fails on a bare import of either barrel. Import a module path instead:
 * `@conduction/nextcloud-vue/dist/esm/...` or `@nextcloud/vue/components/Nc...`.
 *
 * @spec exclude Build-size guard for the global script; no OpenSpec requirement covers bundle composition.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

const fs = require('fs')
const path = require('path')

const ROOT = path.resolve(__dirname, '..', '..')
const ENTRY = path.join(ROOT, 'src', 'integration-global.js')

/** Static imports, re-exports, side-effect imports and dynamic imports. */
const IMPORT_SPECIFIER =
	/(?:import|export)\s[^'"]*?from\s*['"]([^'"]+)['"]|import\s*['"]([^'"]+)['"]|import\(\s*(?:\/\*[\s\S]*?\*\/\s*)?['"]([^'"]+)['"]\s*\)/g

/** Package entry points that drag a whole library in. */
const BARRELS = ['@conduction/nextcloud-vue', '@nextcloud/vue']

/**
 * Remove comments, so a commented-out import neither counts nor hides.
 *
 * @param {string} code Source text.
 * @return {string} The text without comments.
 */
function stripComments(code) {
	return code.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/.*$/gm, '$1')
}

/**
 * Resolve a relative import to a file, or null for a package.
 *
 * @param {string} from The importing file.
 * @param {string} specifier The import specifier.
 * @return {string|null} The file.
 */
function resolveLocal(from, specifier) {
	if (!specifier.startsWith('.')) {
		return null
	}
	const base = path.resolve(path.dirname(from), specifier)
	return [base, `${base}.js`, `${base}.vue`, path.join(base, 'index.js')]
		.find((c) => fs.existsSync(c) && fs.statSync(c).isFile()) ?? null
}

/**
 * Every file in the entry's graph, with the package specifiers it imports.
 *
 * @param {string} entry The entry file.
 * @return {Map<string, string[]>} File to package specifiers.
 */
function graph(entry) {
	const files = new Map()
	const stack = [entry]
	while (stack.length > 0) {
		const file = stack.pop()
		if (files.has(file)) {
			continue
		}
		const code = stripComments(fs.readFileSync(file, 'utf8'))
		const packages = []
		for (const match of code.matchAll(IMPORT_SPECIFIER)) {
			const specifier = match[1] || match[2] || match[3]
			const local = resolveLocal(file, specifier)
			if (local !== null) {
				stack.push(local)
			} else if (!specifier.startsWith('.')) {
				packages.push(specifier)
			}
		}
		files.set(file, packages)
	}
	return files
}

describe('the global script imports no package barrel', () => {
	const files = graph(ENTRY)

	it('walks the real graph', () => {
		const names = [...files.keys()].map((f) => path.relative(ROOT, f))
		expect(names).toEqual(expect.arrayContaining([
			'src/integration-global.js',
			'src/integrations/bootstrap.js',
			'src/integrations/builtin/ExternalRegisterCard.vue',
		]))
	})

	it('still reaches both libraries, through module paths', () => {
		const all = [...files.values()].flat()
		expect(all.some((s) => s.startsWith('@conduction/nextcloud-vue/dist/esm/'))).toBe(true)
		expect(all.some((s) => s.startsWith('@nextcloud/vue/components/'))).toBe(true)
	})

	it.each([...graph(ENTRY).keys()].map((f) => [path.relative(ROOT, f), f]))('%s', (name, file) => {
		const barrels = files.get(file).filter((s) => BARRELS.includes(s))
		expect({ file: name, barrels }).toEqual({ file: name, barrels: [] })
	})
})
