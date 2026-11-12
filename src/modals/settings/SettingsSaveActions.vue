<template>
	<NcButton @click="$emit('cancel')">
		{{ t('openregister', 'Cancel') }}
	</NcButton>
	<NcButton variant="primary" :disabled="saving" @click="$emit('save')">
		<template #icon>
			<NcLoadingIcon v-if="saving" :size="20" />
			<ContentSave v-else :size="20" />
		</template>
		{{
			saving
				? t('openregister', 'Saving...')
				: t('openregister', 'Save Configuration')
		}}
	</NcButton>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'

/**
 * The cancel and save buttons of a settings modal.
 *
 * @spec exclude Presentational dialog actions shared by the settings modals.
 */
export default {
	name: 'SettingsSaveActions',
	components: { NcButton, NcLoadingIcon, ContentSave },

	props: {
		saving: { type: Boolean, required: true },
	},

	emits: ['cancel', 'save'],
}
</script>
