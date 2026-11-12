<template>
	<CnDialog
		:open="open"
		:name="name"
		width="confirm"
		@closing="$emit('closing')">
		<div class="clear-dialog-content">
			<h3>⚠️ {{ heading }}</h3>
			<p>{{ description }}</p>
			<p>
				<strong>{{ countText }}</strong>
			</p>
			<p v-if="warning" class="warning-text">{{ warning }}</p>
			<p><strong>This operation may take some time to complete.</strong></p>

			<SettingsDialogActions
				:busy="clearing"
				:label="clearing ? 'Clearing...' : 'Confirm Clear All'"
				@cancel="$emit('closing')"
				@confirm="$emit('confirm')">
				<template #icon>
					<Delete :size="20" />
				</template>
			</SettingsDialogActions>
		</div>
	</CnDialog>
</template>

<script>
import { CnDialog } from '@conduction/nextcloud-vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import SettingsDialogActions from './SettingsDialogActions.vue'

/**
 * Asks before clearing a whole table.
 *
 * @spec exclude Presentational dialog shared by the clear dialogs of the statistics page.
 */
export default {
	name: 'ClearTableDialog',
	components: { CnDialog, Delete, SettingsDialogActions },
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
