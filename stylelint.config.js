module.exports = {
	extends: '@nextcloud/stylelint-config',
	rules: {
		'selector-pseudo-element-no-unknown': [
			true,
			{
				ignorePseudoElements: ['v-deep'],
			},
		],
		// Indentation is prettier's — the same handover eslint-config-prettier
		// performs for eslint, and for the same reason: two formatters with
		// overlapping jurisdiction make a repo unfixable. Prettier's glob is
		// `**/*.{js,ts,vue,css,scss}` (the `format` script) and covers both `src/**`
		// and `css/main.css`, so nothing is lost by stylelint not indenting.
		//
		// The `indentation: null` entry that used to sit here is gone: stylelint
		// REMOVED the rule in v16, and from v17 a removed rule name is an "Unknown
		// rule" error even when set to null. Keeping the entry cost 213 errors.
	},
}
