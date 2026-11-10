/**
 * @jest-environment node
 */

/**
 * Every webpack entry that reaches @conduction/nextcloud-vue registers the
 * library's translation catalogue.
 *
 * WHY THIS EXISTS. The library translates its labels under its own
 * `nextcloud-vue` app id, and the catalogue is only registered when the host
 * bundle calls `registerTranslations()`. openregister's bundles never did, so a
 * Dutch reader got openregister's own strings in Dutch and every library string
 * (the object metadata's Archiving group among them) in English. Each entry is a
 * separate page, so fixing one entry fixes one page, and the next entry added to
 * webpack.config.js would silently repeat the gap.
 *
 * So this reads the entry list from webpack.config.js itself, rather than from a
 * list kept here that could drift from it, and walks each entry's relative import
 * graph. An entry whose graph reaches the library must call
 * `registerLibraryTranslations()` (src/services/libraryTranslations.js) or
 * `registerTranslations()` in its own file. Transitive reach counts: six of the
 * seven entries import the library only through a component or through
 * `integrations/bootstrap.js`, and a direct-import check would have excused them.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

const fs = require('fs')
const path = require('path')

const ROOT = path.resolve(__dirname, '..', '..')
const SRC = path.join(ROOT, 'src')

/** Static imports, re-exports, side-effect imports and dynamic imports. */
const IMPORT_SPECIFIER =
	/(?:import|export)\s[^'"]*?from\s*['"]([^'"]+)['"]|import\s*['"]([^'"]+)['"]|import\(\s*['"]([^'"]+)['"]\s*\)/g

/** The library itself or one of its JS subpaths. A stylesheet renders no label. */
const LIBRARY = /^@conduction\/nextcloud-vue(\/|$)/

/** The call an entry must make. */
const REGISTERS = /\bregister(?:Library)?Translations\s*\(\s*\)/

/**
 * Remove comments, so a commented-out call cannot satisfy the guard.
 *
 * @param {string} code Source text.
 * @return {string} The text without block or line comments.
 */
function stripComments(code) {
	return code.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/.*$/gm, '$1')
}

/**
 * Resolve a relative or `@/` import to a file under src/, or null for a package.
 *
 * @param {string} from The importing file.
 * @param {string} specifier The import specifier.
 * @return {string|null} The resolved file.
 */
function resolveLocal(from, specifier) {
	let base
	if (specifier.startsWith('.')) {
		base = path.resolve(path.dirname(from), specifier)
	} else if (specifier.startsWith('@/')) {
		base = path.join(SRC, specifier.slice(2))
	} else {
		return null
	}

	const candidates = [
		base,
		`${base}.js`,
		`${base}.vue`,
		`${base}.ts`,
		`${base}.mjs`,
		path.join(base, 'index.js'),
	]
	return (
		candidates.find(
			(candidate) =>
				fs.existsSync(candidate) && fs.statSync(candidate).isFile(),
		) ?? null
	)
}

/**
 * The first file in an entry's import graph that imports the library.
 *
 * @param {string} entry The entry file.
 * @return {string|null} That file, or null when the graph never reaches it.
 */
function libraryImporter(entry) {
	const seen = new Set()
	const stack = [entry]
	while (stack.length > 0) {
		const file = stack.pop()
		if (seen.has(file) || !/\.(js|vue|ts|mjs)$/.test(file)) {
			continue
		}
		seen.add(file)
		const code = stripComments(fs.readFileSync(file, 'utf8'))
		for (const match of code.matchAll(IMPORT_SPECIFIER)) {
			const specifier = match[1] || match[2] || match[3]
			if (LIBRARY.test(specifier) && !specifier.endsWith('.css')) {
				return file
			}
			const local = resolveLocal(file, specifier)
			if (local !== null) {
				stack.push(local)
			}
		}
	}
	return null
}

/**
 * The entries webpack.config.js declares, as `[name, absolute file]` pairs.
 *
 * @return {Array<[string, string]>} Every entry.
 */
function webpackEntries() {
	// @nextcloud/webpack-vue-config reads these from the npm run environment
	// and throws on a bare require without them.
	process.env.npm_package_name = process.env.npm_package_name || 'openregister'
	process.env.npm_package_version = process.env.npm_package_version || '0.0.0'
	const config = require(path.join(ROOT, 'webpack.config.js'))
	return Object.entries(config.entry).map(([name, value]) => [
		name,
		path.resolve(ROOT, typeof value === 'string' ? value : value.import),
	])
}

describe('every entry that reaches nextcloud-vue registers its translations', () => {
	const entries = webpackEntries()

	it('reads a real entry list, not an empty one', () => {
		// An empty or unparsed list would make every case below vacuous.
		expect(entries.map(([name]) => name)).toContain('main')
		expect(entries.length).toBeGreaterThan(1)
	})

	it('finds the library in at least the main entry, so the walk itself works', () => {
		const main = entries.find(([name]) => name === 'main')
		expect(libraryImporter(main[1])).not.toBeNull()
	})

	it.each(entries)('%s', (name, file) => {
		const importer = libraryImporter(file)
		if (importer === null) {
			// This entry never loads the library, so it has nothing to register.
			return
		}
		const code = stripComments(fs.readFileSync(file, 'utf8'))
		expect({
			entry: name,
			file: path.relative(ROOT, file),
			reachesLibraryVia: path.relative(ROOT, importer),
			registersTranslations: REGISTERS.test(code),
		}).toEqual(expect.objectContaining({ registersTranslations: true }))
	})

	it('the shared helper actually calls the library', () => {
		const helper = stripComments(
			fs.readFileSync(
				path.join(SRC, 'services', 'libraryTranslations.js'),
				'utf8',
			),
		)
		expect(helper).toMatch(/\bregisterTranslations\s*\(\s*\)/)
	})
})
