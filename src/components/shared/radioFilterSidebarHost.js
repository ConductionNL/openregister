/**
 * What the sidebars built on RadioFilterSidebar share: the two-way bound
 * search text, and `t` for their templates.
 *
 * @spec exclude Client-side props of the radio filter sidebars.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { t } from '@nextcloud/l10n'

export const radioFilterSidebarHost = {
	props: {
		/**
		 * @spec exclude two-way-bound search prop, UI plumbing
		 */
		search: {
			type: String,
			default: '',
		},
	},

	methods: {
		t,
	},
}
