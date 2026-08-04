/**
 * OpenRegister Reference Widget Registration
 *
 * Registers the ObjectReferenceWidget for rendering rich previews of
 * OpenRegister objects in the Nextcloud Smart Picker / vue-richtext.
 *
 * @category Reference
 * @package
 * @license  EUPL-1.2
 */

// eslint-disable-next-line import/no-unresolved
import { registerWidget } from '@nextcloud/vue-richtext'

registerWidget('openregister-object', async () => {
	const { default: ObjectReferenceWidget } = await import('./ObjectReferenceWidget.vue')
	return ObjectReferenceWidget
})
