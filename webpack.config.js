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
	// re-bundling them into every entry. This cuts total `js/` size by ~70% and
	// roughly halves build RAM again. Because the entries are no longer
	// self-contained, each page must now load ALL of its entry's initial chunks
	// (see ScriptManifestLoader on the PHP side, fed by the manifest below).
	//
	// `integrationGlobal` is deliberately EXCLUDED from splitting: it is injected
	// on EVERY Nextcloud page via addInitScript, so sharing chunks with it would
	// (a) pull this app's vendor code onto every page of the whole instance and
	// (b) create a second webpack runtime co-loading shared chunks with the
	// page's real entry. Keeping it self-contained preserves today's behaviour.
	webpackConfig.optimization.splitChunks = {
		chunks: (chunk) => chunk.name !== 'integrationGlobal',
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
// `USE_LOCAL_LIB=false` is the fleet-wide switch (OR_SKIP_LOCAL_NCVUE stays for
// compatibility): it forces the published package even when a sibling checkout is
// present, so a local build can reproduce what CI and production build — they have
// no sibling, so they always resolve the npm dist.
const localLib = path.resolve(__dirname, '../nextcloud-vue/src')
const useLocalLib = fs.existsSync(localLib)
	&& !process.env.OR_SKIP_LOCAL_NCVUE
	&& process.env.USE_LOCAL_LIB !== 'false'

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
	personalSettings: {
		import: path.join(__dirname, 'src', 'personalSettings.js'),
		filename: appId + '-personalSettings.js',
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

// The base config sets `output.clean: true`, which wipes js/ on every build.
// The Web Push Service Worker + opt-in client (openregister-push-client.js /
// openregister-push-sw.js) are hand-written static assets served as-is — NOT
// webpack entries — so keep them through the clean, otherwise the build deletes
// them and the toggle reports "browser does not support web push".
webpackConfig.output.clean = { keep: (asset) => asset.includes('openregister-push-') }

// Code-splitting (frontend-code-splitting-and-fetch-efficiency): dynamically
// imported view chunks are fetched at runtime relative to output.publicPath.
// The default resolves to `/apps/{app}/js/`, which 404s when the app is served
// from `/custom_apps/{app}/js/` (dev/custom installs). `'auto'` derives the base
// from the currently-executing script's URL (document.currentScript), so chunks
// load correctly whether the app lives under apps/ or custom_apps/.
webpackConfig.output.publicPath = 'auto'

module.exports = webpackConfig
