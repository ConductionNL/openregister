module.exports = {
	extends: '@nextcloud/stylelint-config',
	rules: {
		'selector-pseudo-element-no-unknown': [true, {
			ignorePseudoElements: ['v-deep'],
		}],
	},
}
