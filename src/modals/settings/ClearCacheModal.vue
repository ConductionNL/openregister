<template>
	<ConfirmDialogFrame
		v-if="show"
		name="Clear Cache"
		size="small"
		:closable="!clearing"
		:loading="clearing"
		:cancelDisabled="clearing"
		closeLabel="Cancel"
		:confirmLabel="clearing ? 'Clearing...' : 'Clear Cache'"
		showConfirm
		@cancel="$emit('close')"
		@confirm="confirmClear">
		<div class="dialog-content">
			<h3>🗑️ Clear Cache</h3>
			<p class="warning-text">
				Select the type of cache to clear. This action cannot be undone and
				may temporarily impact performance.
			</p>

			<div class="cache-type-selection">
				<h4>Cache Type:</h4>
				<div
					v-for="(group, index) in cacheTypeGroups"
					:key="index"
					class="radio-group">
					<NcCheckboxRadioSwitch
						v-for="option in group"
						:key="option.value"
						v-model="localCacheType"
						name="cache_type"
						:value="option.value"
						type="radio">
						{{ option.label }}
					</NcCheckboxRadioSwitch>
				</div>
			</div>
		</div>

		<template #confirmIcon>
			<Delete :size="20" />
		</template>
	</ConfirmDialogFrame>
</template>

<script>
import { NcCheckboxRadioSwitch } from '@nextcloud/vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import ConfirmDialogFrame from '../shared/ConfirmDialogFrame.vue'

/**
 * The cache types to choose from, in rows of radios.
 */
const CACHE_TYPE_GROUPS = [
	[
		{ value: 'all', label: 'Clear All Cache (Recommended)' },
		{ value: 'object', label: 'Object Cache Only' },
	],
	[
		{ value: 'schema', label: 'Schema Cache Only' },
		{ value: 'facet', label: 'Facet Cache Only' },
	],
	[{ value: 'distributed', label: 'Distributed Cache Only' }],
]

export default {
	name: 'ClearCacheModal',

	components: { ConfirmDialogFrame, NcCheckboxRadioSwitch, Delete },

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

	emits: ['close', 'confirm', 'cache-type-changed'],

	data() {
		return {
			localCacheType: this.cacheType,
		}
	},

	computed: {
		/**
		 * The cache types to choose from, for display.
		 *
		 * @spec exclude UI display helper — static radio layout.
		 * @return {Array<Array<object>>}
		 */
		cacheTypeGroups() {
			return CACHE_TYPE_GROUPS
		},
	},

	watch: {
		/**
		 * @param {string} newValue The cache type the parent selected
		 * @spec exclude watcher syncing local cache-type from prop
		 */
		cacheType(newValue) {
			this.localCacheType = newValue
		},

		/**
		 * @param {string} newValue The cache type the user selected
		 * @spec exclude watcher emitting cache-type change
		 */
		localCacheType(newValue) {
			this.$emit('cache-type-changed', newValue)
		},
	},

	methods: {
		/**
		 * @spec openspec/specs/platform-administration-modals/spec.md
		 */
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
