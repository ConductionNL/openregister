import { translate } from '@nextcloud/l10n'

/**
 * The `t` method a component's template translates with, shared so each
 * component that only translates in its template need not declare it.
 *
 * @spec exclude Template translation helper, UI plumbing.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
export default {
	methods: {
		t: translate,
	},
}
