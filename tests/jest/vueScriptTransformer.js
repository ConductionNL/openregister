/**
 * Script transformer for vue3-jest. Babel's .babelrc is file-relative, so it
 * never reaches a single-file component inside node_modules (the
 * vue-material-design-icons, for one) and its script would stay an ES module.
 * Those components get @babel/preset-env passed explicitly; every other
 * component keeps the project's own babel configuration.
 *
 * @spec exclude Test tooling configuration.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
const babelJest = require('babel-jest').default

const project = babelJest.createTransformer()
const dependency = babelJest.createTransformer({ presets: ['@babel/preset-env'] })

module.exports = {
	process(source, filename, options) {
		const transformer = /[\\/]node_modules[\\/]/.test(filename) ? dependency : project
		return transformer.process(source, filename, options)
	},
}
