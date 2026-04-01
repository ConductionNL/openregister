const path = require('path')

module.exports = {
	// Define how different file types should be transformed before testing
	transform: {
		'^.+\\.vue$': '@vue/vue2-jest', // Compile Vue SFCs using the Vue 2 jest transformer
		'^.+\\.js$': ['babel-jest', { configFile: path.resolve(__dirname, '.babelrc') }], // Transpile JS using our local .babelrc config
		'^.+\\.ts$': ['ts-jest', { diagnostics: false }], // Transpile TS without type-checking (faster tests)
		'.+\\.(css|styl|less|sass|scss|png|jpg|ttf|woff|woff2)$': 'jest-transform-stub', // Stub out static assets that can't be executed
	},
	moduleFileExtensions: ['js', 'json', 'vue', 'ts'],
	testEnvironment: 'jest-environment-jsdom', // Simulate a browser DOM for component tests
	roots: [
		'<rootDir>',
		'<rootDir>/../nextcloud-vue/src', // Include the local nextcloud-vue source so its components can be resolved
	],
	moduleNameMapper: {
		'^@/(.*)$': '<rootDir>/src/$1', // Map the @ alias to src/ (mirrors webpack resolve)
		'^@conduction/nextcloud-vue$': '<rootDir>/tests/__mocks__/@conduction/nextcloud-vue.js', // Use a manual mock for the shared Vue lib in tests
		'^pinia$': '<rootDir>/node_modules/pinia', // Force pinia to resolve to our local copy to avoid duplicate instances
		'\\.(css|less|sass|scss)$': 'jest-transform-stub', // Stub stylesheet imports so they don't break tests
	},
	transformIgnorePatterns: [
		'/node_modules/(?!(@nextcloud|pinia)/)', // Transform @nextcloud and pinia packages (they ship raw ESM that Jest can't run directly)
	],
	setupFiles: [
		'<rootDir>/tests/setup.js', // Global test setup (e.g. mocking Nextcloud globals)
	],
	coveragePathIgnorePatterns: [
		'index.js', // Exclude barrel files from coverage — they only re-export
		'index.ts',
	],
	coverageDirectory: '<rootDir>/coverage-frontend/',
}
