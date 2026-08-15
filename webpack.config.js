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
			compiler.hooks.afterEmit.tap(
				'OpenRegisterEntrypointsManifest',
				(compilation) => {
					const manifest = {}
					for (const [name, entrypoint] of compilation.entrypoints) {
						manifest[name] = entrypoint
							.getFiles()
							.map((file) => file.split('?')[0])
							.filter((file) => file.endsWith('.js'))
					}
					fs.writeFileSync(
						path.join(
							compiler.options.output.path,
							'openregister-entrypoints.json',
						),
						JSON.stringify(manifest, null, '\t') + '\n',
					)
				},
			)
		},
	})
}

webpackConfig.stats = {
	colors: true,
	modules: false,
}

// TypeScript handling.
//
// ⚠️ REPLACE the base config's TypeScript rule — do not push a second one.
// `@nextcloud/webpack-vue-config@5` shipped NO `.ts` rule, which is why this
// app added its own with `transpileOnly: true`. Version 7 introduced one
// (`rules.js`, `test: /\.tsx?$/` using a bare `'ts-loader'` with no options),
// so pushing left TWO rules matching every `.ts` file — and the base one type
// checks.
//
// The effect was 218 `[tsl] ERROR` type errors out of `src/store/modules/search.ts`
// (TS2345/TS2683/TS7006, all pre-existing under `strict: true`), failing the
// build. Those are real type debt, but they are NOT Vue 3 work, and a
// framework migration is the wrong change to smuggle a new 218-error type gate
// into. Replacing keeps exactly the previous behaviour: one transpile-only rule.
//
// Turning the type check on deliberately is worth doing — separately.
const nonTsRules = webpackConfig.module.rules.filter(
	(rule) => String(rule.test) !== String(/\.tsx?$/),
)
webpackConfig.module.rules = [
	...nonTsRules,
	{
		test: /\.(ts|tsx)$/,
		exclude: /node_modules/,
		use: {
			loader: 'ts-loader',
			options: {
				transpileOnly: true,
				appendTsSuffixTo: [/\.vue$/],
			},
		},
	},
]

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
// ⚠️ USE_LOCAL_LIB is opt-IN (ADR-090). Building against a developer's working
// checkout is the wrong default for a build that can ship.
let useLocalLib =
	fs.existsSync(localLib)
	&& !process.env.OR_SKIP_LOCAL_NCVUE
	&& process.env.USE_LOCAL_LIB === 'true'

// The sibling checkout is validated against THIS app's own declared range.
// The previous test was `/^2\./` on the comment's premise that "the Vue 3 line is
// 2.x; the Vue 2 line is 1.0.0-beta.*". That premise expired: the Vue 2 line is
// now 2.0.5 and the Vue 3 line is 2.2.0-vue3.16 — BOTH major 2 — so the test
// matched the Vue 2 checkout and passed it straight through, which is precisely
// what it existed to prevent. Compiling those sources into this Vue 3 app yields
// a bundle that builds cleanly and renders nothing.
//
// Fail CLOSED: if the check cannot run, the sibling is refused. A guard that
// degrades to "allow" is not a guard.
if (useLocalLib) {
	const siblingPkgPath = path.resolve(__dirname, '../nextcloud-vue/package.json')
	let siblingVersion = 'unreadable'
	let satisfied = false
	try {
		// eslint-disable-next-line n/no-extraneous-require
		const semver = require('semver')
		const required =
			require('./package.json').dependencies['@conduction/nextcloud-vue']
		siblingVersion = JSON.parse(fs.readFileSync(siblingPkgPath, 'utf8')).version
		satisfied = semver.satisfies(siblingVersion, required, {
			includePrerelease: true,
		})
	} catch (e) {
		satisfied = false
	}

	if (!satisfied) {
		// eslint-disable-next-line no-console
		console.warn(
			'[openregister/webpack] Ignoring the sibling @conduction/nextcloud-vue checkout: '
				+ `version ${siblingVersion} does not satisfy this app's declared range. `
				+ 'Building against the installed npm package instead.',
		)
		useLocalLib = false
	}
}

webpackConfig.resolve.alias = {
	...(webpackConfig.resolve.alias || {}),
	'@': path.resolve(__dirname, 'src'),
	...(useLocalLib ? { '@conduction/nextcloud-vue': localLib } : {}),
	// Deduplicate shared packages so the aliased library source uses
	// the same instances as the app (prevents dual-Pinia / dual-Vue bugs).
	//
	// ⚠️ `@nextcloud/vue@9`, `@nextcloud/dialogs@7` and `vue-router@5` ship an
	// `exports` map with NO `main` and NO `module`. webpack applies an exports
	// map to a PACKAGE REQUEST, never to an already-absolutised path, so a
	// Vue-2-era DIRECTORY alias resolves to nothing and every import fails with
	// `Can't resolve '@nextcloud/vue'`. Alias the absolute FILE for those.
	// `vue`, `pinia` and `vue-router` still carry `main`/`module`, but pinning
	// the file keeps every consumer on one copy regardless.
	vue$: path.resolve(
		__dirname,
		'node_modules/vue/dist/vue.runtime.esm-bundler.js',
	),
	pinia$: path.resolve(__dirname, 'node_modules/pinia/dist/pinia.mjs'),
	// `dist/vue-router.js` — NOT `.mjs`, which does not exist. This is the file
	// the package's own `exports['.'].import` (and `module`) names, so the alias
	// reproduces default resolution exactly while still guaranteeing that
	// @nextcloud/vue's chunks and this app share ONE router copy.
	'vue-router$': path.resolve(
		__dirname,
		'node_modules/vue-router/dist/vue-router.js',
	),
	'@nextcloud/vue$': path.resolve(
		__dirname,
		'node_modules/@nextcloud/vue/dist/index.mjs',
	),
	// Shim for floating-vue compatibility: adds getScrollParents (0.x API) as alias for getOverflowAncestors (1.x API)
	'@floating-ui/dom$': path.resolve(__dirname, 'src/shims/floating-ui-dom.js'),
	'@floating-ui/dom-actual': path.resolve(
		__dirname,
		'node_modules/@floating-ui/dom',
	),
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
const otherPlugins = (webpackConfig.plugins || []).filter(
	(p) => p.constructor.name !== 'VueLoaderPlugin',
)
webpackConfig.plugins = [new VueLoaderPlugin(), ...otherPlugins]

// Force @nextcloud/dialogs to resolve from this app's node_modules, preventing
// a nested copy from a sibling checkout leaking in. v7 is exports-map-only, so
// this must name the absolute FILE (see the alias block above).
webpackConfig.resolve.alias['@nextcloud/dialogs$'] = path.resolve(
	__dirname,
	'node_modules/@nextcloud/dialogs/dist/index.mjs',
)

// The base config sets `output.clean: true`, which wipes js/ on every build.
// Some assets under js/ are hand-written static files served as-is — NOT
// webpack entries — so they must survive the clean, otherwise the build
// deletes them and they come back as a spurious deletion in the next diff.
//
// Keep this list in sync with `git ls-files js/`. Anything TRACKED in js/ that
// no entry in `webpackConfig.entry` emits belongs here. Today that is:
//
//   openregister-push-client.js  \  Web Push opt-in client + Service Worker.
//   openregister-push-sw.js      /  Without these the toggle reports
//                                   "browser does not support web push".
//
//   openregister-flow-operator.js   Registers the "Run an OpenRegister flow"
//                                   operation with the workflowengine admin
//                                   UI. Loaded by
//                                   FlowEngineRegistrationListener.php:86 via
//                                   \OCP\Util::addScript(). Losing it 404s
//                                   that script and the operator silently
//                                   stops appearing in Flow settings.
//
// This predicate previously matched only `openregister-push-`, so every build
// deleted the flow-operator script. It was picked up as a deletion and shipped
// in a PR at least once (#2381) before being caught.
const KEPT_STATIC_ASSETS = ['openregister-push-', 'openregister-flow-operator']
webpackConfig.output.clean = {
	keep: (asset) => KEPT_STATIC_ASSETS.some((name) => asset.includes(name)),
}

// Code-splitting (frontend-code-splitting-and-fetch-efficiency): dynamically
// imported view chunks are fetched at runtime relative to output.publicPath.
// The default resolves to `/apps/{app}/js/`, which 404s when the app is served
// from `/custom_apps/{app}/js/` (dev/custom installs). `'auto'` derives the base
// from the currently-executing script's URL (document.currentScript), so chunks
// load correctly whether the app lives under apps/ or custom_apps/.
webpackConfig.output.publicPath = 'auto'

module.exports = webpackConfig
