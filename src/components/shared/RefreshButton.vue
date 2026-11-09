<template>
	<NcButton :variant="variant" :disabled="loading" @click="$emit('click')">
		<template #icon>
			<NcLoadingIcon v-if="loading" :size="20" />
			<Refresh v-else :size="20" />
		</template>
		<slot>{{ t('openregister', 'Refresh') }}</slot>
	</NcButton>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'

/**
 * A button that reloads a list: spinner while loading, disabled meanwhile.
 * The label defaults to "Refresh"; pass a slot for another one.
 *
 * @spec exclude Presentational reload button shared by the list pages.
 */
export default {
	name: 'RefreshButton',
	components: { NcButton, NcLoadingIcon, Refresh },
	props: {
		loading: { type: Boolean, default: false },
		variant: { type: String, default: 'tertiary' },
	},

	emits: ['click'],
	methods: { t },
}
</script>
