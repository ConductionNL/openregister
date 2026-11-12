/**
 * What the AVG dialogs that act on one case share: their props and the
 * denial-ground options of the active pack.
 *
 * @spec exclude Presentational dialog options shared by the AVG case dialogs.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { resolveGroundOptions } from '../../store/modules/avg.js'

/** The props of a dialog that acts on one AVG case under the active pack. */
export const caseDialogProps = {
	caseId: {
		type: String,
		required: true,
	},

	pack: {
		type: Object,
		default: null,
	},
}

/**
 * The active pack's denial grounds as NcSelect options, as a computed
 * property of a case dialog.
 *
 * @spec exclude Presentation glue: the resolution is owned by the store helper.
 * @return {Array<{value: string, label: string, citation: string}>} The options
 */
export function groundOptions() {
	return resolveGroundOptions(this.pack)
}
