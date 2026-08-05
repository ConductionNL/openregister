<template>
	<NcAppContent>
		<div class="viewContainer" :class="{ withDetail: selectedObject }">
			<div class="viewMain">
				<div class="viewHeader">
					<h1>{{ t('openregister', 'Master entities') }}</h1>
					<p>{{ t('openregister', 'Survivorship-enabled objects for a register and schema, with their materialised quality columns.') }}</p>
				</div>

				<RegisterSchemaSelector />

				<div v-if="hasSelection && masterEntities.length" class="sortBar">
					<NcSelect
						v-model="sortModel"
						class="sortSelect"
						:options="sortOptions"
						:input-label="t('openregister', 'Sort by')"
						:clearable="false"
						label="label"
						@update:modelValue="handleSortChange" />
				</div>

				<NcEmptyContent
					v-if="!hasSelection"
					:name="t('openregister', 'Select a register and schema')"
					:description="t('openregister', 'Choose a survivorship-enabled register and schema above to list its master entities.')">
					<template #icon>
						<AccountMultipleOutline :size="64" />
					</template>
				</NcEmptyContent>

				<template v-else-if="loading">
					<NcLoadingIcon :size="32" />
				</template>

				<template v-else-if="masterEntities.length === 0">
					<NcEmptyContent
						:name="t('openregister', 'No master entities found')"
						:description="t('openregister', 'This schema has no objects yet.')">
						<template #icon>
							<AccountMultipleOutline :size="64" />
						</template>
					</NcEmptyContent>
				</template>

				<table v-else class="masterEntitiesTable">
					<thead>
						<tr>
							<th>{{ t('openregister', 'Id') }}</th>
							<th>{{ t('openregister', 'Quality score') }}</th>
							<th>{{ t('openregister', 'Quality status') }}</th>
							<th>{{ t('openregister', 'Actions') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="entity in masterEntities"
							:key="entity.id"
							data-testid="mdm-master-entity-row"
							:class="{ selectedRow: selectedObject && selectedObject.id === entity.id }">
							<td>{{ entity.id }}</td>
							<td>{{ entity.qualityScore ?? '—' }}</td>
							<td><span :class="'badge badge-status-' + entity.qualityStatus">{{ entity.qualityStatus ?? '—' }}</span></td>
							<td>
								<NcButton variant="tertiary" data-testid="mdm-view-golden-record" @click="selectEntity(entity)">
									{{ t('openregister', 'View golden record') }}
								</NcButton>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<GoldenRecordDetail
				v-if="selectedObject"
				:object="goldenRecord || selectedObject"
				@close="selectedObject = null" />
		</div>
	</NcAppContent>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcAppContent, NcEmptyContent, NcLoadingIcon, NcButton, NcSelect } from '@nextcloud/vue'
import AccountMultipleOutline from 'vue-material-design-icons/AccountMultipleOutline.vue'
import RegisterSchemaSelector from './RegisterSchemaSelector.vue'
import GoldenRecordDetail from './GoldenRecordDetail.vue'
import { qualityStore } from '../../store/store.js'

export default {
	name: 'MasterEntitiesIndex',

	components: {
		NcAppContent,
		NcEmptyContent,
		NcLoadingIcon,
		NcButton,
		NcSelect,
		AccountMultipleOutline,
		RegisterSchemaSelector,
		GoldenRecordDetail,
	},

	data() {
		return {
			selectedObject: null,
			sortModel: { id: 'qualityScore-asc', label: t('openregister', 'Quality score (worst first)') },
			sortOptions: [
				{ id: 'qualityScore-asc', label: t('openregister', 'Quality score (worst first)') },
				{ id: 'qualityScore-desc', label: t('openregister', 'Quality score (best first)') },
			],
		}
	},

	computed: {
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
		 * @spec openspec/specs/mdm-frontend/spec.md
		 */
		masterEntities() {
			return qualityStore.masterEntities
		},

		/**
		 * @spec openspec/specs/mdm-frontend/spec.md
		 */
		goldenRecord() {
			return qualityStore.goldenRecord
		},
	},

	/**
	 * @spec openspec/specs/mdm-frontend/spec.md
	 */
	mounted() {
		if (this.hasSelection) {
			this.loadData()
		}
		this.$watch(
			() => [qualityStore.selectedRegister, qualityStore.selectedSchema],
			() => {
				this.selectedObject = null
				this.loadData()
			},
		)
	},

	methods: {
		/**
		 * Load master-entity objects (via the existing object-read
		 * endpoint) for the current selection, sorted by quality. No
		 * request is issued unless both register and schema are selected.
		 *
		 * @spec exclude UI data-loading orchestration — delegates to store actions which carry the @spec tags
		 */
		async loadData() {
			if (!qualityStore.hasSelection) return
			const [sort, order] = (this.sortModel?.id ?? 'qualityScore-asc').split('-')
			await qualityStore.fetchMasterEntities(qualityStore.selectedRegister, qualityStore.selectedSchema, { _sort: sort, _order: order })
		},

		/**
		 * @spec exclude UI event handler — re-sorts the master-entity listing, no backend contract of its own
		 */
		async handleSortChange() {
			await this.loadData()
		},

		/**
		 * Select a master entity and open its golden-record detail panel.
		 *
		 * @param {object} entity Selected master-entity row.
		 * @spec openspec/specs/mdm-frontend/spec.md#requirement-master-entity-list-with-golden-record-detail
		 */
		async selectEntity(entity) {
			this.selectedObject = entity
			await qualityStore.fetchGoldenRecord(qualityStore.selectedRegister, qualityStore.selectedSchema, entity.id)
		},
	},
}
</script>

<style scoped>
.viewContainer {
	padding: 20px;
	display: flex;
	gap: 16px;
	max-width: 1400px;
}

.viewMain {
	flex: 1;
	min-width: 0;
}

.viewHeader h1 {
	margin-bottom: 4px;
}

.sortBar {
	margin-bottom: 12px;
}

.sortSelect {
	max-width: 280px;
}

.masterEntitiesTable {
	width: 100%;
	border-collapse: collapse;
}

.masterEntitiesTable th,
.masterEntitiesTable td {
	text-align: left;
	padding: 8px;
	border-bottom: 1px solid var(--color-border);
}

.selectedRow {
	background-color: var(--color-background-hover);
}

.badge {
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	background-color: var(--color-background-dark);
}
</style>
