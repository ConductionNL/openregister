<template>
	<NcButton
		variant="error"
		:disabled="loading || saving || rebasing"
		@click="$emit('rebase')">
		<template #icon>
			<NcLoadingIcon v-if="rebasing" :size="20" />
			<Refresh v-else :size="20" />
		</template>
		Rebase
	</NcButton>
	<NcButton
		variant="primary"
		:disabled="loading || saving || rebasing"
		@click="$emit('save')">
		<template #icon>
			<NcLoadingIcon v-if="saving" :size="20" />
			<Save v-else :size="20" />
		</template>
		Save
	</NcButton>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import Save from 'vue-material-design-icons/ContentSave.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'

/**
 * The two actions of a settings section: rebase and save.
 *
 * @spec exclude Presentational actions shared by the settings sections.
 */
export default {
	name: 'SectionSaveActions',
	components: { NcButton, NcLoadingIcon, Refresh, Save },
	props: {
		loading: { type: Boolean, default: false },
		saving: { type: Boolean, default: false },
		rebasing: { type: Boolean, default: false },
	},

	emits: ['rebase', 'save'],
}
</script>
