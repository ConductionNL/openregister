<template>
	<NcDialog
		:open="open"
		name="Clear Cache"
		:can-close="!clearing"
		@closing="$emit('closing')">
		<div class="clear-cache-dialog">
			<div class="clear-cache-options">
				<h3>🗑️ Clear Cache</h3>
				<p class="warning-text">
					Select the type of cache to clear. This action cannot be undone
					and may temporarily impact performance.
				</p>

				<div class="cache-type-selection">
					<h4>Cache Type:</h4>
					<NcCheckboxRadioSwitch
						v-model="selectedCacheType"
						name="cache_type"
						value="all"
						type="radio">
						Clear All Cache (Recommended)
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch
						v-model="selectedCacheType"
						name="cache_type"
						value="object"
						type="radio">
						Object Cache Only
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch
						v-model="selectedCacheType"
						name="cache_type"
						value="schema"
						type="radio">
						Schema Cache Only
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch
						v-model="selectedCacheType"
						name="cache_type"
						value="facet"
						type="radio">
						Facet Cache Only
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch
						v-model="selectedCacheType"
						name="cache_type"
						value="distributed"
						type="radio">
						Distributed Cache Only
					</NcCheckboxRadioSwitch>
				</div>
			</div>
			<div class="dialog-actions">
				<NcButton :disabled="clearing" @click="$emit('closing')">
					Cancel
				</NcButton>
				<NcButton
					variant="error"
					:disabled="clearing"
					@click="$emit('confirm')">
					<template #icon>
						<NcLoadingIcon v-if="clearing" :size="20" />
						<Delete v-else :size="20" />
					</template>
					{{ clearing ? 'Clearing...' : 'Clear Cache' }}
				</NcButton>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDialog,
	NcLoadingIcon,
} from '@nextcloud/vue'
import Delete from 'vue-material-design-icons/Delete.vue'

export default {
	name: 'ClearCacheDialog',
	components: { NcButton, NcCheckboxRadioSwitch, NcDialog, NcLoadingIcon, Delete },
	props: {
		open: { type: Boolean, required: true },
		clearing: { type: Boolean, default: false },
		cacheType: { type: String, default: 'all' },
	},
	emits: ['closing', 'confirm', 'update:cacheType'],
	computed: {
		/**
		 * The radio selection. It still lives in the parent — and behind the
		 * parent, the settings store — so the confirm handler reads the value
		 * it always did; this pair only moves it across the prop/event boundary.
		 */
		selectedCacheType: {
			/**
			 * Read the selected clear-cache type from the parent.
			 *
			 * @spec exclude UI plumbing — computed getter proxies the prop
			 * @return {string}
			 */
			get() {
				return this.cacheType
			},
			/**
			 * Write the selected clear-cache type back to the parent.
			 *
			 * @param {string} value The new clear-cache type.
			 * @spec exclude UI plumbing — computed setter emits to the parent
			 * @return {void}
			 */
			set(value) {
				this.$emit('update:cacheType', value)
			},
		},
	},
}
</script>

<style scoped>
.clear-cache-dialog {
	padding: 20px;
}

.clear-cache-options h3 {
	margin: 0 0 16px 0;
	color: var(--color-text-light);
}

.warning-text {
	color: var(--color-text-light);
	margin: 0 0 20px 0;
	line-height: 1.5;
}

.cache-type-selection h4 {
	margin: 0 0 12px 0;
	color: var(--color-text-light);
}

.dialog-actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 20px;
}
</style>
