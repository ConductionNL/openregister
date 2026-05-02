module.exports = {
	transform: {
		'^.+\\.vue$': '@vue/vue2-jest',
		'^.+\\.js$': 'babel-jest',
		'^.+\\.ts$': 'ts-jest',
		'.+\\.(css|styl|less|sass|scss|png|jpg|ttf|woff|woff2)$': 'jest-transform-stub',
	},
	moduleFileExtensions: ['js', 'json', 'vue', 'ts'],
	testEnvironment: 'jest-environment-jsdom',
	moduleNameMapper: {
		'^@/(.*)$': '<rootDir>/src/$1',
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
		'<rootDir>/\\.playwright-mcp/',
	],
	modulePathIgnorePatterns: [
		'<rootDir>/custom_apps/',
		'<rootDir>/\\.claude/worktrees/',
	],
	coveragePathIgnorePatterns: [
		'index.js',
		'index.ts',
	],
	coverageDirectory: '<rootDir>/coverage-frontend/',
}
