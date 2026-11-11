<template>
	<NcButton variant="primary" @click="$emit('click', $event)">
		<template #icon>
			<NcLoadingIcon v-if="saving" :size="20" />
			<ContentSave v-else :size="20" />
		</template>
		{{ t('openregister', 'Save') }}
	</NcButton>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'

/**
 * The primary Save button of a form: the save icon, or a spinner while it
 * saves. Other attributes (disabled, data-testid) go to the NcButton.
 *
 * @spec exclude Presentational save action shared by the editor forms.
 */
export default {
	name: 'SaveButton',
	components: { NcButton, NcLoadingIcon, ContentSave },

	props: {
		/** Whether the save is running. */
		saving: { type: Boolean, default: false },
	},

	emits: ['click'],

	methods: { t },
}
</script>
