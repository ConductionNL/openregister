<template>
	<NcDialog :open="open" :name="name" @closing="$emit('closing')">
		<div class="clear-dialog-content">
			<h3>⚠️ {{ heading }}</h3>
			<p>{{ description }}</p>
			<p>
				<strong>{{ countText }}</strong>
			</p>
			<p v-if="warning" class="warning-text">{{ warning }}</p>
			<p><strong>This operation may take some time to complete.</strong></p>

			<div class="dialog-actions">
				<NcButton @click="$emit('closing')">
					{{ t('openregister', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="error"
					:disabled="clearing"
					@click="$emit('confirm')">
					<template #icon>
						<NcLoadingIcon v-if="clearing" :size="20" />
						<Delete v-else :size="20" />
					</template>
					{{ clearing ? 'Clearing...' : 'Confirm Clear All' }}
				</NcButton>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcDialog, NcLoadingIcon } from '@nextcloud/vue'
import Delete from 'vue-material-design-icons/Delete.vue'

/**
 * Asks before clearing a whole table.
 *
 * @spec exclude Presentational dialog shared by the clear dialogs of the statistics page.
 */
export default {
	name: 'ClearTableDialog',
	components: { Delete, NcButton, NcDialog, NcLoadingIcon },
	props: {
		open: { type: Boolean, required: true },
		clearing: { type: Boolean, default: false },
		name: { type: String, required: true },
		heading: { type: String, required: true },
		description: { type: String, required: true },
		countText: { type: String, required: true },
		warning: { type: String, default: '' },
	},

	emits: ['closing', 'confirm'],
	methods: { t },
}
</script>

<style scoped lang="scss">
@use '../../styles/dialogs.scss' as *;

.clear-dialog-content {
	padding: 20px;
}

@include clear-dialog-content-h3-rules;

.clear-dialog-content .warning-text {
	color: var(--color-error);
	font-weight: 600;
}

@include dialog-actions;
</style>
