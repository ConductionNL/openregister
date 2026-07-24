const path = require('path')
const fs = require('fs')
const { VueLoaderPlugin } = require('vue-loader')
const TerserPlugin = require('terser-webpack-plugin')
const webpackConfig = require('@nextcloud/webpack-vue-config')

const buildMode = process.env.NODE_ENV
const isDev = buildMode === 'development'
// Production builds disable source maps entirely. The full `source-map` devtool
// (and Terser's own source-map generation) added significant memory and time on
// top of compilation. Dropping them keeps the output minified while lowering peak
// memory. Dev keeps cheap, fast line-level maps.
webpackConfig.devtool = isDev ? 'cheap-source-map' : false

webpackConfig.stats = {
	colors: true,
	modules: false,
}

// Add TypeScript handling to module rules
// Use ts-loader for TypeScript files (already in dependencies)
webpackConfig.module.rules.push({
	test: /\.(ts|tsx)$/,
	exclude: /node_modules/,
	use: {
		loader: 'ts-loader',
		options: {
			transpileOnly: true,
			appendTsSuffixTo: [/\.vue$/],
		},
	},
})

// Add .ts and .tsx to resolve extensions and '@' alias
webpackConfig.resolve = webpackConfig.resolve || {}
webpackConfig.resolve.extensions = [
	'.ts',
	'.tsx',
	'.js',
	'.jsx',
	'.vue',
	'.json',
	...(webpackConfig.resolve.extensions || []),
]
// ==================================================
//                      NOTE:
//           DO NOT REMOVE THE ALIASES,
// THESE MAKE THE DEVELOPMENT ENVIRONMENT FUNCTIONAL
// ==================================================
// Use local source when available (monorepo dev), otherwise fall back to npm package
const localLib = path.resolve(__dirname, '../nextcloud-vue/src')
const useLocalLib = fs.existsSync(localLib)

webpackConfig.resolve.alias = {
	...(webpackConfig.resolve.alias || {}),
	'@': path.resolve(__dirname, 'src'),
	...(useLocalLib ? { '@conduction/nextcloud-vue': localLib } : {}),
	// Deduplicate shared packages so the aliased library source uses
	// the same instances as the app (prevents dual-Pinia / dual-Vue bugs).
	vue$: path.resolve(__dirname, 'node_modules/vue'),
	pinia$: path.resolve(__dirname, 'node_modules/pinia'),
	'@nextcloud/vue$': path.resolve(__dirname, 'node_modules/@nextcloud/vue'),
	// Shim for floating-vue compatibility: adds getScrollParents (0.x API) as alias for getOverflowAncestors (1.x API)
	'@floating-ui/dom$': path.resolve(__dirname, 'src/shims/floating-ui-dom.js'),
	'@floating-ui/dom-actual': path.resolve(__dirname, 'node_modules/@floating-ui/dom'),
}
// @nextcloud/vue ships .cjs/.mjs; allow .js requests to resolve to .cjs (for dist subpaths)
webpackConfig.resolve.extensionAlias = {
	'.js': ['.cjs', '.js'],
	...webpackConfig.resolve.extensionAlias,
}
// When using local nextcloud-vue (../nextcloud-vue/src), resolve its deps from this app's node_modules
webpackConfig.resolve.modules = [
	path.resolve(__dirname, 'node_modules'),
	...(webpackConfig.resolve.modules || ['node_modules']),
]

const appId = 'openregister'
webpackConfig.entry = {
	main: {
		import: path.join(__dirname, 'src', 'main.js'),
		filename: appId + '-main.js',
	},
	adminSettings: {
		import: path.join(__dirname, 'src', 'settings.js'),
		filename: appId + '-settings.js',
	},
	filesSidebar: {
		import: path.join(__dirname, 'src', 'files-sidebar.js'),
		filename: appId + '-filesSidebar.js',
	},
	mailSidebar: {
		import: path.join(__dirname, 'src', 'mail-sidebar.js'),
		filename: appId + '-mail-sidebar.js',
	},
}

// Replace VueLoaderPlugin (don't push — duplicates break templates when using local package)
const otherPlugins = (webpackConfig.plugins || []).filter((p) => p.constructor.name !== 'VueLoaderPlugin')
webpackConfig.plugins = [new VueLoaderPlugin(), ...otherPlugins]

// Minify with esbuild instead of Terser in production. Terser parses every chunk
// into a full JS AST held in the Node heap and runs `CPU cores - 1` parallel
// workers. esbuild minifies in native (Go) code with a tiny heap footprint and is
// far faster, at the cost of only ~1-2% larger output. We reuse terser-webpack-plugin
// purely as the wiring and swap its engine to the built-in esbuild minifier.
if (!isDev) {
	webpackConfig.optimization = webpackConfig.optimization || {}
	webpackConfig.optimization.minimizer = [
		new TerserPlugin({
			minify: TerserPlugin.esbuildMinify,
			// esbuild parallelizes internally (in Go), so terser-webpack-plugin's
			// default `cpus-1` Node worker processes only add memory overhead.
			// Disabling them lowers peak build RAM with no real speed cost.
			parallel: false,
			// esbuild minify options (NOT terserOptions). Keep legal/license
			// comments at end-of-file so MIT/AGPL attribution required by our
			// deps survives minification. (esbuild's sidecar-emitting 'linked'
			// mode is unavailable here — terser-webpack-plugin drives esbuild via
			// its transform API, which rejects 'linked'/'external'.)
			terserOptions: {
				legalComments: 'eof',
			},
		}),
	]

	// The base config keeps an in-memory cache (`cache: true`) in production.
	// A one-shot build never reuses it, so it only inflates the webpack main
	// process — the dominant memory consumer here. Disable it for the build.
	webpackConfig.cache = false

	// Deduplicate heavy shared deps (vue, @nextcloud/vue, pinia, the local
	// nextcloud-vue source, …) across entries into shared chunks instead of
	// re-bundling them into every entry. This cuts total `js/` size and roughly
	// halves build RAM again. Because entries are no longer self-contained, each
	// page must now load ALL of its entry's initial chunks (see
	// ScriptManifestLoader on the PHP side, fed by the manifest below).
	webpackConfig.optimization.splitChunks = {
		chunks: 'all',
		cacheGroups: {
			vendor: {
				test: /[\\/]node_modules[\\/]/,
				name: 'openregister-vendor',
				priority: 10,
			},
		},
	}

	// Emit js/openregister-entrypoints.json mapping each entry name to the
	// ordered list of initial JS chunks it needs. The PHP ScriptManifestLoader
	// reads this at render time and enqueues every chunk (split-chunk filenames
	// are content-hashed and change per build, so they cannot be hardcoded).
	webpackConfig.plugins.push({
		apply(compiler) {
			compiler.hooks.afterEmit.tap('OpenRegisterEntrypointsManifest', (compilation) => {
				const manifest = {}
				for (const [name, entrypoint] of compilation.entrypoints) {
					manifest[name] = entrypoint
						.getFiles()
						.map((file) => file.split('?')[0])
						.filter((file) => file.endsWith('.js'))
				}
				fs.writeFileSync(
					path.join(compiler.options.output.path, 'openregister-entrypoints.json'),
					JSON.stringify(manifest, null, '\t') + '\n',
				)
			})
		},
	})
}

// Force @nextcloud/dialogs to resolve from this app's node_modules,
// preventing the nextcloud-vue submodule's nested deps (Vue 3) from leaking in.
// The '$' restricts this to the exact bare specifier so subpath imports like
// '@nextcloud/dialogs/style.css' still resolve via the package's own exports map
// instead of being rewritten to a literal (non-existent) filesystem path.
webpackConfig.resolve.alias['@nextcloud/dialogs$'] = path.resolve(__dirname, 'node_modules/@nextcloud/dialogs')

module.exports = webpackConfig
