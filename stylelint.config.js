module.exports = {
	extends: '@nextcloud/stylelint-config',
	rules: {
		'selector-pseudo-element-no-unknown': [
			true,
			{
				ignorePseudoElements: ['v-deep'],
			},
		],
		// Indentation is prettier's now — the same handover eslint-config-prettier
		// performs for eslint, and for the same reason: two formatters with
		// overlapping jurisdiction make a repo unfixable. They genuinely disagree,
		// on multi-line selectors: prettier continues a wrapped selector list at two
		// tabs, this rule demands one.
		//
		// Handing it over LOSES nothing and GAINS coverage. This rule only ever saw
		// what the `stylelint` script's glob passes it — `src/**` — so it reported
		// clean on `development` while `css/main.css`, outside that glob, sat at 522
		// space-indented lines. Prettier's glob is `**/*.{js,ts,vue,css,scss}` and
		// covers both. `indentation` is also deprecated in stylelint 15 and removed
		// in 16, so it is on its way out regardless.
		indentation: null,
	},
}
