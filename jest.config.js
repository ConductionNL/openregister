module.exports = {
	transform: {
		'^.+\\.vue$': '@vue/vue3-jest',
		// .babelrc is file-relative, so it never reaches node_modules. Every
		// package that transformIgnorePatterns below lets through (the ESM-only
		// @nextcloud/* and @conduction/* packages, @nextcloud/axios 2.6 among
		// them) therefore needs the preset passed explicitly, or Jest parses
		// its `import` statements raw and the suite fails to run.
		'/node_modules/.+\\.[cm]?js$': [
			'babel-jest',
			{ presets: ['@babel/preset-env'] },
		],
		'^.+\\.[cm]?js$': 'babel-jest',
		'^.+\\.ts$': 'ts-jest',
		'.+\\.(css|styl|less|sass|scss|png|jpg|ttf|woff|woff2)$':
			'jest-transform-stub',
	},
	// Compiles the script block of node_modules components (the icons) too, so
	// a spec that does not mock an icon renders the real one.
	globals: {
		'vue-jest': {
			transform: {
				'^js$': require('path').join(__dirname, 'tests/jest/vueScriptTransformer.js'),
			},
		},
	},
	moduleFileExtensions: ['js', 'json', 'vue', 'ts'],
	testEnvironment: 'jest-environment-jsdom',
	setupFiles: ['<rootDir>/tests/jest.setup.js'],
	// Several @nextcloud/* and @conduction/* packages ship pure ESM,
	// which Jest cannot parse out of the box. Run them through
	// babel-jest so the test suite can import store modules that
	// transitively pull NC vue components.
	transformIgnorePatterns: [
		'/node_modules/(?!(@nextcloud|@conduction|tributejs|escape-html|webdav|p-cancelable|p-limit|p-queue|p-timeout|node-fetch|fetch-blob|formdata-polyfill|data-uri-to-buffer|filesize|nanoid|pinia|toastify-js|vue-material-design-icons|axios|zod|cancelable-promise)/)',
	],
	moduleNameMapper: {
		'^@/(.*)$': '<rootDir>/src/$1',
		// Maps each icon to its own file on purpose. Jest's resolver caches a
		// module id per (importing file, request) for the whole worker, and a
		// `jest.mock(..., { virtual: true })` makes that id a fake path next to
		// the spec. The next spec in the worker then mocks the real path while
		// the cached id still names the fake one, so its mock is ignored and
		// the real icon loads. A mapped request never takes the virtual path,
		// so every spec gets the same id. Mapping all icons to one stub would
		// give them all one id, and one spec's icon mocks would overwrite each
		// other.
		'^vue-material-design-icons/(.+)$':
			'<rootDir>/node_modules/vue-material-design-icons/$1',
		// ⚠️ Jest's default resolver reads `main` and does NOT honour an
		// `exports` map or a bare `module` field. The Vue-3-era @nextcloud
		// packages ship one or the other and no `main`, so `jest.mock(...)` and
		// plain imports both fail with "Cannot find module" — 16 suites' worth.
		// This is the same trap as webpack's exports-map/directory-alias problem,
		// one layer up: point at the resolved FILE.
		//   @nextcloud/vue      — exports map only, no main/module
		//   @nextcloud/dialogs  — exports map only, no main/module
		//   @nextcloud/axios    — `module` only, no `main`
		'^@nextcloud/vue$': '<rootDir>/node_modules/@nextcloud/vue/dist/index.mjs',
		'^@nextcloud/dialogs$':
			'<rootDir>/node_modules/@nextcloud/dialogs/dist/index.mjs',
		'^@nextcloud/axios$':
			'<rootDir>/node_modules/@nextcloud/axios/dist/index.js',
		// @nextcloud/browser-storage ships pure ESM with `"type": "module"`.
		// Babel-jest can't transparently transform it from a CJS dependency
		// chain, so stub it for the unit-test environment.
		'^@nextcloud/browser-storage$':
			'<rootDir>/tests/__mocks__/nextcloud-browser-storage.js',
		// Stub the entire @conduction/nextcloud-vue package — its real
		// require chain pulls in @nextcloud/vue (~MB of CSS+CJS+ESM)
		// that jest cannot transform. The store unit tests only need
		// the factory exports (createObjectStore, *Plugin).
		'^@conduction/nextcloud-vue$':
			'<rootDir>/tests/__mocks__/conduction-nextcloud-vue.js',
		// Stub CSS / asset imports inside node_modules. Default
		// `transformIgnorePatterns` excludes node_modules from the
		// `jest-transform-stub` rule, so a require('....css') from
		// @nextcloud/vue otherwise crashes the parser.
		'\\.(css|less|sass|scss|png|jpg|ttf|woff|woff2)$': 'jest-transform-stub',
	},
	// The OR repo lives inside a Nextcloud docker-dev workspace where
	// `custom_apps/` is a sibling-app mount and `.claude/worktrees/` holds
	// agent worktree copies. Jest must not descend into either — both
	// host their own `src/` trees that would otherwise be picked up here.
	testPathIgnorePatterns: [
		'/node_modules/',
		'/vendor/',
		'<rootDir>/custom_apps/',
		'<rootDir>/\\.claude/worktrees/',
		// Scratch/tooling directories. A directory hidden from git (via
		// .gitignore or .git/info/exclude) is still perfectly visible to jest,
		// webpack and eslint — extracting a package tarball here for inspection
		// made jest try to transform the library's own `src/**/*.vue` and
		// produced 140 bogus "Cannot use import statement outside a module"
		// errors across 79 suites.
		'<rootDir>/\\.migration/',
		'<rootDir>/\\.playwright-mcp/',
		// Playwright suite — has its own runner via `npx playwright test`.
		'<rootDir>/tests/e2e/',
		// The demo-environment Playwright suite. It sits OUTSIDE tests/e2e/ on
		// purpose: playwright.config.ts sets testDir: './tests/e2e', so a spec
		// placed there is collected by CI, which has no booted demo to point
		// at. Moving it out solves that and creates this: jest's ignore list
		// named tests/e2e/ specifically, so the new directory was collected
		// here instead and jest tried to run a Playwright spec. Two collectors
		// with opposite exclusions — a file has to be named in both.
		'<rootDir>/tests/demo-e2e/',
	],
	modulePathIgnorePatterns: [
		'<rootDir>/custom_apps/',
		'<rootDir>/\\.claude/worktrees/',
		'<rootDir>/\\.migration/',
	],
	coveragePathIgnorePatterns: ['index.js', 'index.ts'],
	// Without this, a file no spec imports is left out of the total entirely.
	collectCoverageFrom: [
		'src/**/*.{js,ts,vue}',
		'!src/**/*.spec.{js,ts}',
		'!src/**/*.test.{js,ts}',
		'!src/tests/**',
	],
	coverageDirectory: '<rootDir>/coverage-frontend/',
}
