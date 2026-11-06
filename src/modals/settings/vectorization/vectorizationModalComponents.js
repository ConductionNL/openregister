/**
 * The components the file and object vectorization modals render.
 *
 * @spec exclude UI plumbing shared by the vectorization modals.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { NcCheckboxRadioSwitch, NcDialog } from '@nextcloud/vue'
import VectorizationActions from './VectorizationActions.vue'
import VectorizationModeChoice from './VectorizationModeChoice.vue'
import VectorizationNumberField from './VectorizationNumberField.vue'
import VectorizationPrediction from './VectorizationPrediction.vue'
import VectorizationProgress from './VectorizationProgress.vue'
import VectorizationScope from './VectorizationScope.vue'

export const vectorizationModalComponents = {
	NcCheckboxRadioSwitch,
	NcDialog,
	VectorizationActions,
	VectorizationModeChoice,
	VectorizationNumberField,
	VectorizationPrediction,
	VectorizationProgress,
	VectorizationScope,
}
