/**
 * OpenRegister Reference Widget Registration
 *
 * Registers the ObjectReferenceWidget for rendering rich previews of
 * OpenRegister objects in the Nextcloud Smart Picker / vue-richtext.
 *
 * @category Reference
 * @package  OCA.OpenRegister.Reference
 * @license  EUPL-1.2
 */

import { registerWidget } from '@nextcloud/vue-richtext'

registerWidget('openregister-object', async () => {
	const { default: ObjectReferenceWidget } = await import('./ObjectReferenceWidget.vue')
	return ObjectReferenceWidget
})
