<template>
	<NcDialog
		v-if="show"
		name="Clear Cache"
		:can-close="!clearing"
		@closing="$emit('close')">
		<div class="dialog-content">
			<h3>🗑️ Clear Cache</h3>
			<p class="warning-text">
				Select the type of cache to clear. This action cannot be undone and may temporarily impact performance.
			</p>

			<div class="cache-type-selection">
				<h4>Cache Type:</h4>
				<div class="radio-group">
					<NcCheckboxRadioSwitch
						:checked.sync="localCacheType"
						name="cache_type"
						value="all"
						type="radio">
						Clear All Cache (Recommended)
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch
						:checked.sync="localCacheType"
						name="cache_type"
						value="object"
						type="radio">
						Object Cache Only
					</NcCheckboxRadioSwitch>
				</div>
				<div class="radio-group">
					<NcCheckboxRadioSwitch
						:checked.sync="localCacheType"
						name="cache_type"
						value="schema"
						type="radio">
						Schema Cache Only
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch
						:checked.sync="localCacheType"
						name="cache_type"
						value="facet"
						type="radio">
						Facet Cache Only
					</NcCheckboxRadioSwitch>
				</div>
				<div class="radio-group">
					<NcCheckboxRadioSwitch
						:checked.sync="localCacheType"
						name="cache_type"
						value="distributed"
						type="radio">
						Distributed Cache Only
					</NcCheckboxRadioSwitch>
				</div>
			</div>
		</div>

		<template #actions>
			<NcButton
				:disabled="clearing"
				@click="$emit('close')">
				<template #icon>
					<Cancel :size="20" />
				</template>
				Cancel
			</NcButton>
			<NcButton
				type="error"
				:disabled="clearing"
				@click="confirmClear">
				<template #icon>
					<NcLoadingIcon v-if="clearing" :size="20" />
					<Delete v-else :size="20" />
				</template>
				{{ clearing ? 'Clearing...' : 'Clear Cache' }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcDialog, NcButton, NcLoadingIcon, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import Delete from 'vue-material-design-icons/Delete.vue'

export default {
	name: 'ClearCacheModal',

	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcCheckboxRadioSwitch,
		Cancel,
		Delete,
	},

	props: {
		show: {
			type: Boolean,
			default: false,
		},
		clearing: {
			type: Boolean,
			default: false,
		},
		cacheType: {
			type: String,
			default: 'all',
		},
	},

	emits: ['close', 'confirm'],

	data() {
		return {
			localCacheType: this.cacheType,
		}
	},

	watch: {
		cacheType(newValue) {
			this.localCacheType = newValue
		},
		localCacheType(newValue) {
			this.$emit('cache-type-changed', newValue)
		},
	},

	methods: {
		confirmClear() {
			this.$emit('confirm', this.localCacheType)
		},
	},
}
</script>

<style scoped>
.dialog-content {
	padding: 0 20px;
}

.dialog-content h3 {
	color: var(--color-primary);
	margin-bottom: 1rem;
	font-size: 1.2rem;
}

.warning-text {
	color: var(--color-text-light);
	line-height: 1.5;
	margin-bottom: 1.5rem;
}

.cache-type-selection {
	margin-bottom: 1rem;
}

.cache-type-selection h4 {
	margin: 0 0 1rem 0;
	color: var(--color-text);
	font-size: 1rem;
}

.radio-group {
	display: flex;
	gap: 1rem;
	margin-bottom: 0.75rem;
}

.radio-group > * {
	flex: 1;
}

/* Responsive design for smaller screens */
@media (max-width: 768px) {
	.radio-group {
		flex-direction: column;
		gap: 0.5rem;
	}

	.radio-group > * {
		flex: none;
	}
}
</style>
