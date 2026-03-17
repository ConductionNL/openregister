const {
	defineConfig,
} = require('@eslint/config-helpers')

const js = require('@eslint/js')

const {
	FlatCompat,
} = require('@eslint/eslintrc')

const compat = new FlatCompat({
	baseDirectory: __dirname,
	recommendedConfig: js.configs.recommended,
	allConfig: js.configs.all,
})

module.exports = defineConfig([
	...compat.extends('@nextcloud'),

	{
		languageOptions: {
			// set latest version of ECMAScript
			// default (non explicitly set) causes errors when importing
			ecmaVersion: 'latest',
			sourceType: 'module',

			// also pass through to parsers that still read parserOptions
			parserOptions: {
				ecmaVersion: 'latest',
				sourceType: 'module',
			},
		},

		settings: {
			'import/resolver': {
				alias: {
					map: [['@', './src']],
					extensions: ['.js', '.ts', '.vue', '.json'],
				},
			},

			// import/parsers is used to parse the files
			// espree is used to parse the JavaScript files
			// @typescript-eslint/parser is used to parse the TypeScript files
			// vue-eslint-parser is used to parse the Vue files
			'import/parsers': {
				espree: ['.js', '.mjs', '.cjs', '.jsx'],
				'@typescript-eslint/parser': ['.ts', '.tsx', '.mts', '.cts'],
				'vue-eslint-parser': ['.vue'],
			},
		},

		rules: {
			'jsdoc/require-jsdoc': 'off',
			'vue/first-attribute-linebreak': 'off',
			'@typescript-eslint/no-explicit-any': 'off',
			'n/no-missing-import': 'off',
		},
	},
])
