/**
 * ESLint flat config for OpenRegister.
 *
 * Layered as:
 *   1. `@nextcloud` (v8 base, via FlatCompat) — the fleet's baseline style rules.
 *   2. `conductionVue3Fixes` from `@conduction/nextcloud-vue/eslint`, spread
 *      LAST so it wins.
 *   3. this app's own overrides (in layer 1's `rules` block).
 *
 * ⚠️ Layer 2 is load-bearing, not decoration. Without it not one
 * `vue/no-deprecated-*` rule is active, and every surviving Vue-2 idiom
 * (`beforeDestroy`, `.sync`, `$listeners`) lints clean while being silently
 * ignored at runtime — openconnector finished its Vue 3 migration with four
 * live `beforeDestroy` memory leaks exactly that way.
 *
 * ⚠️ `@nextcloud/eslint-config/vue3` is deliberately NOT extended directly: it
 * sets `parserOptions.parser` to a bare string, which routes template
 * expressions through `@typescript-eslint/parser`, drops `v-for` scope, and
 * manufactures hundreds of bogus `vue/valid-v-for` errors.
 *
 * ⚠️ `conductionVue3Fixes` is an ARRAY of configs and must be SPREAD, not
 * pushed as a single object. It registers no plugins, which is why it layers
 * cleanly on top of the `@nextcloud` base.
 */
const {
	defineConfig,
} = require('@eslint/config-helpers')

const js = require('@eslint/js')

const {
	FlatCompat,
} = require('@eslint/eslintrc')

const { conductionVue3Fixes } = require('@conduction/nextcloud-vue/eslint')

const compat = new FlatCompat({
	baseDirectory: __dirname,
	recommendedConfig: js.configs.recommended,
	allConfig: js.configs.all,
})

module.exports = defineConfig([{
	extends: compat.extends('@nextcloud'),

	settings: {
		'import/resolver': {
			alias: {
				map: [
					['@', './src'],
					['@floating-ui/dom-actual', './node_modules/@floating-ui/dom'],
					['@conduction/nextcloud-vue', '../nextcloud-vue/src'],
				],
				extensions: ['.js', '.ts', '.vue', '.json', '.css'],
			},
		},
	},

	rules: {
		// Allow unused i18n functions (t, n) — imported for future translation wiring
		'no-unused-vars': ['error', { varsIgnorePattern: '^(t|n)$', argsIgnorePattern: '^_' }],
		'jsdoc/require-jsdoc': 'off',
		// `@spec` is the ADR-003 spec-traceability tag (links code to OpenSpec change tasks).
		// Register it so jsdoc/check-tag-names does not flag it as an unknown tag.
		'jsdoc/check-tag-names': ['warn', { definedTags: ['spec'] }],
		'vue/first-attribute-linebreak': 'off',
		'@typescript-eslint/no-explicit-any': 'off',
		'n/no-missing-import': 'off',
		'import/namespace': 'off', // disable namespace checking to avoid parser requirement
		'import/default': 'off', // disable default import checking to avoid parser requirement
		'import/no-named-as-default': 'off', // disable named-as-default checking to avoid parser requirement
		'import/no-named-as-default-member': 'off', // disable named-as-default-member checking to avoid parser requirement
	},
},
// Spread LAST so the Vue 3 rules win over the Vue 2 base.
...conductionVue3Fixes,
])
