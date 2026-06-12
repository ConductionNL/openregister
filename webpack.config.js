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

// Minify with esbuild instead of Terser in production. Terser parses every chunk
// into a full JS AST held in the Node heap and runs `CPU cores - 1` parallel
// workers; across these 6 large entrypoints the build tree peaked at ~12GB in
// ~46s. esbuild minifies in native (Go) code with a tiny heap footprint and is
// ~10-100x faster — measured ~5.7GB peak in ~26s here — at the cost of only
// ~1-2% larger output. We reuse terser-webpack-plugin purely as the wiring and
// swap its engine to the built-in esbuild minifier.
if (!isDev) {
	webpackConfig.optimization = webpackConfig.optimization || {}
	webpackConfig.optimization.minimizer = [
		new TerserPlugin({
			minify: TerserPlugin.esbuildMinify,
			// esbuild minify options (NOT terserOptions); strips comments by default.
			terserOptions: {
				legalComments: 'none',
			},
		}),
	]

	// The base config keeps an in-memory cache (`cache: true`) in production.
	// A one-shot build never reuses it, so it only inflates the webpack main
	// process — the dominant memory consumer here. Disable it for the build.
	webpackConfig.cache = false
}

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
// Set OR_SKIP_LOCAL_NCVUE=1 in the env to bypass this alias when the
// apps-extra/nextcloud-vue submodule is on an unrelated branch (e.g. during
// dist-based verification of feature/integration-leaves-consolidated).
const localLib = path.resolve(__dirname, '../nextcloud-vue/src')
const useLocalLib = fs.existsSync(localLib) && !process.env.OR_SKIP_LOCAL_NCVUE

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
	// Global registry bootstrap (universal-shared-integration-registry).
	// Loaded on EVERY page via \OCP\Util::addInitScript so the shared
	// integration registry is installed + populated everywhere, letting
	// leaves render inside any consuming app's detail page without that
	// app bootstrapping the registry itself. Kept separate + tiny.
	integrationGlobal: {
		import: path.join(__dirname, 'src', 'integration-global.js'),
		filename: appId + '-integration-global.js',
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
	// ADR-019 Phase E (Option B): umbrella widget for NC user-dashboard.
	// Loaded by `OCA\OpenRegister\Dashboard\IntegrationDashboardWidget::load()`
	// which calls `Util::addScript('openregister', 'openregister-user-dashboard')`.
	userDashboard: {
		import: path.join(__dirname, 'src', 'user-dashboard.js'),
		filename: appId + '-user-dashboard.js',
	},
}

// Replace VueLoaderPlugin (don't push — duplicates break templates when using local package)
const otherPlugins = (webpackConfig.plugins || []).filter((p) => p.constructor.name !== 'VueLoaderPlugin')
webpackConfig.plugins = [new VueLoaderPlugin(), ...otherPlugins]

// Force @nextcloud/dialogs to resolve from this app's node_modules,
// preventing the nextcloud-vue submodule's nested deps (Vue 3) from leaking in.
webpackConfig.resolve.alias['@nextcloud/dialogs'] = path.resolve(__dirname, 'node_modules/@nextcloud/dialogs')

module.exports = webpackConfig
