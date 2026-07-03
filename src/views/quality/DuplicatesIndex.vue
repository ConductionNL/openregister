<template>
	<NcAppContent>
		<div class="viewContainer">
			<div class="viewHeader">
				<h1>{{ t('openregister', 'Duplicate Candidates') }}</h1>
				<p>{{ t('openregister', 'Read-only view of candidate duplicate pairs detected for a register and schema. Merge execution is out of scope here.') }}</p>
			</div>

			<RegisterSchemaSelector />

			<NcEmptyContent
				v-if="!hasSelection"
				:name="t('openregister', 'Select a register and schema')"
				:description="t('openregister', 'Choose a register and schema above to see its duplicate candidates.')">
				<template #icon>
					<ContentDuplicate :size="64" />
				</template>
			</NcEmptyContent>

			<template v-else-if="loading">
				<NcLoadingIcon :size="32" />
			</template>

			<template v-else-if="duplicates.length === 0">
				<NcEmptyContent
					:name="t('openregister', 'No duplicate candidates found')"
					:description="t('openregister', 'No candidate pairs were detected for this register and schema.')">
					<template #icon>
						<ContentDuplicate :size="64" />
					</template>
				</NcEmptyContent>
			</template>

			<template v-else>
				<table class="duplicatesTable">
					<thead>
						<tr>
							<th>{{ t('openregister', 'Object A') }}</th>
							<th>{{ t('openregister', 'Object B') }}</th>
							<th>{{ t('openregister', 'Score') }}</th>
							<th>{{ t('openregister', 'Matched on') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="(pair, index) in duplicates" :key="index">
							<td>{{ pair.objectA }}</td>
							<td>{{ pair.objectB }}</td>
							<td>{{ pair.score }}</td>
							<td>{{ (pair.matchedOn || []).join(', ') }}</td>
						</tr>
					</tbody>
				</table>
				<div v-if="duplicatesTotal > duplicatesLimit" class="pagination">
					<NcButton :disabled="duplicatesOffset === 0" @click="previousPage">
						{{ t('openregister', 'Previous') }}
					</NcButton>
					<span class="paginationInfo">
						{{ t('openregister', 'Showing {from}-{to} of {total}', {
							from: duplicatesOffset + 1,
							to: Math.min(duplicatesOffset + duplicatesLimit, duplicatesTotal),
							total: duplicatesTotal,
						}) }}
					</span>
					<NcButton :disabled="duplicatesOffset + duplicatesLimit >= duplicatesTotal" @click="nextPage">
						{{ t('openregister', 'Next') }}
					</NcButton>
				</div>
			</template>
		</div>
	</NcAppContent>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcAppContent, NcEmptyContent, NcLoadingIcon, NcButton } from '@nextcloud/vue'
import ContentDuplicate from 'vue-material-design-icons/ContentDuplicate.vue'
import RegisterSchemaSelector from './RegisterSchemaSelector.vue'
import { qualityStore } from '../../store/store.js'

export default {
	name: 'DuplicatesIndex',

	components: {
		NcAppContent,
		NcEmptyContent,
		NcLoadingIcon,
		NcButton,
		ContentDuplicate,
		RegisterSchemaSelector,
	},

	computed: {
		/**
		 * Expose the l10n translate helper to the template.
		 *
		 * @spec exclude UI plumbing — template translation helper
		 * @return {Function}
		 */
		t() {
			return t
		},

		/**
		 * @spec exclude UI plumbing — proxies the store's hasSelection getter, no backend contract of its own
		 */
		hasSelection() {
			return qualityStore.hasSelection
		},

		/**
		 * @spec exclude UI plumbing — proxies the store's loading flag, no backend contract of its own
		 */
		loading() {
			return qualityStore.isLoading
		},

		/**
		 * @spec openspec/changes/mdm-frontend/specs/mdm-frontend/spec.md#candidate-pairs-render-with-score-and-matched-attributes
		 */
		duplicates() {
			return qualityStore.duplicates
		},

		/**
		 * @spec openspec/changes/mdm-frontend/specs/mdm-frontend/spec.md#pagination-follows-the-endpoint-envelope
		 */
		duplicatesTotal() {
			return qualityStore.duplicatesTotal
		},

		/**
		 * @spec openspec/changes/mdm-frontend/specs/mdm-frontend/spec.md#pagination-follows-the-endpoint-envelope
		 */
		duplicatesLimit() {
			return qualityStore.duplicatesLimit
		},

		/**
		 * @spec openspec/changes/mdm-frontend/specs/mdm-frontend/spec.md#pagination-follows-the-endpoint-envelope
		 */
		duplicatesOffset() {
			return qualityStore.duplicatesOffset
		},
	},

	/**
	 * @spec openspec/changes/mdm-frontend/specs/mdm-frontend/spec.md#selection-persists-across-mdm-views
	 */
	mounted() {
		if (this.hasSelection) {
			this.loadData()
		}
		this.$watch(
			() => [qualityStore.selectedRegister, qualityStore.selectedSchema],
			() => this.loadData(),
		)
	},

	methods: {
		/**
		 * Load duplicate candidates for the current selection. No request
		 * is issued unless both register and schema are selected.
		 *
		 * @spec exclude UI data-loading orchestration — delegates to store actions which carry the @spec tags
		 */
		async loadData() {
			if (!qualityStore.hasSelection) return
			await qualityStore.fetchDuplicates(qualityStore.selectedRegister, qualityStore.selectedSchema, { limit: 20, offset: 0 })
		},

		/**
		 * @spec exclude UI pagination handler — delegates to fetchDuplicates
		 */
		async previousPage() {
			const offset = Math.max(0, this.duplicatesOffset - this.duplicatesLimit)
			await qualityStore.fetchDuplicates(qualityStore.selectedRegister, qualityStore.selectedSchema, { limit: this.duplicatesLimit, offset })
		},

		/**
		 * @spec exclude UI pagination handler — delegates to fetchDuplicates
		 */
		async nextPage() {
			const offset = this.duplicatesOffset + this.duplicatesLimit
			await qualityStore.fetchDuplicates(qualityStore.selectedRegister, qualityStore.selectedSchema, { limit: this.duplicatesLimit, offset })
		},
	},
}
</script>

<style scoped>
.viewContainer {
	padding: 20px;
	max-width: 1200px;
}

.viewHeader h1 {
	margin-bottom: 4px;
}

.duplicatesTable {
	width: 100%;
	border-collapse: collapse;
	margin-top: 16px;
}

.duplicatesTable th,
.duplicatesTable td {
	text-align: left;
	padding: 8px;
	border-bottom: 1px solid var(--color-border);
}

.pagination {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-top: 12px;
}
</style>
