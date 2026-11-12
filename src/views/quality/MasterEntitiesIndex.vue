<template>
	<NcAppContent>
		<div class="viewContainer" :class="{ withDetail: selectedObject }">
			<div class="viewMain">
				<QualityPageFrame
					:title="t('openregister', 'Master entities')"
					:description="t('openregister', 'Survivorship-enabled objects for a register and schema, with their materialised quality columns.')"
					:selectDescription="t('openregister', 'Choose a survivorship-enabled register and schema above to list its master entities.')"
					:empty="masterEntities.length === 0"
					:emptyName="t('openregister', 'No master entities found')"
					:emptyDescription="t('openregister', 'This schema has no objects yet.')">
					<template #toolbar>
						<div v-if="hasSelection && masterEntities.length" class="sortBar">
							<NcSelect
								v-model="sortModel"
								class="sortSelect"
								:options="sortOptions"
								:inputLabel="t('openregister', 'Sort by')"
								:clearable="false"
								label="label"
								@update:modelValue="handleSortChange" />
						</div>
					</template>
					<template #icon>
						<AccountMultipleOutline :size="64" />
					</template>
					<table class="masterEntitiesTable">
						<thead>
							<tr>
								<th scope="col">
									{{ t('openregister', 'Id') }}
								</th>
								<th scope="col">
									{{ t('openregister', 'Quality score') }}
								</th>
								<th scope="col">
									{{ t('openregister', 'Quality status') }}
								</th>
								<th scope="col">
									{{ t('openregister', 'Actions') }}
								</th>
							</tr>
						</thead>
						<tbody>
							<tr
								v-for="entity in masterEntities"
								:key="entity.id"
								data-testid="mdm-master-entity-row"
								:class="{
									selectedRow:
										selectedObject
										&& selectedObject.id === entity.id,
								}">
								<td>{{ entity.id }}</td>
								<td>{{ entity.qualityScore ?? '—' }}</td>
								<td>
									<span
										:class="
											'badge badge-status-' + entity.qualityStatus
										"
										>{{ entity.qualityStatus ?? '—' }}</span
									>
								</td>
								<td>
									<NcButton
										variant="tertiary"
										data-testid="mdm-view-golden-record"
										@click="selectEntity(entity)">
										{{ t('openregister', 'View golden record') }}
									</NcButton>
								</td>
							</tr>
						</tbody>
					</table>
				</QualityPageFrame>
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
import {
	NcAppContent,
	NcButton,
	NcSelect,
} from '@nextcloud/vue'
import AccountMultipleOutline from 'vue-material-design-icons/AccountMultipleOutline.vue'
import GoldenRecordDetail from './GoldenRecordDetail.vue'
import { qualityStore } from '../../store/store.js'
import { qualityListPage, QualityPageFrame } from './qualityListPage.js'

/**
 * The sort option that lists the lowest quality scores first.
 *
 * @return {{id: string, label: string}} A fresh option
 */
function worstFirstSort() {
	return {
		id: 'qualityScore-asc',
		label: t('openregister', 'Quality score (worst first)'),
	}
}

/**
 * The sort option that lists the highest quality scores first.
 *
 * @return {{id: string, label: string}} A fresh option
 */
function bestFirstSort() {
	return {
		id: 'qualityScore-desc',
		label: t('openregister', 'Quality score (best first)'),
	}
}

export default {
	name: 'MasterEntitiesIndex',

	components: {
		NcAppContent,
		NcButton,
		NcSelect,
		AccountMultipleOutline,
		QualityPageFrame,
		GoldenRecordDetail,
	},

	mixins: [qualityListPage()],

	data() {
		return {
			selectedObject: null,
			sortModel: worstFirstSort(),
			sortOptions: [worstFirstSort(), bestFirstSort()],
		}
	},

	computed: {
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

	methods: {
		/**
		 * Drop the open record and reload for the new register and schema.
		 *
		 * @spec exclude UI plumbing — delegates to loadData
		 */
		selectionChanged() {
			this.selectedObject = null
			this.loadData()
		},

		/**
		 * Load master-entity objects (via the existing object-read
		 * endpoint) for the current selection, sorted by quality. No
		 * request is issued unless both register and schema are selected.
		 *
		 * @spec exclude UI data-loading orchestration — delegates to store actions which carry the @spec tags
		 */
		async loadData() {
			if (!qualityStore.hasSelection) return
			const [sort, order] = (this.sortModel?.id ?? 'qualityScore-asc').split(
				'-',
			)
			await qualityStore.fetchMasterEntities(
				qualityStore.selectedRegister,
				qualityStore.selectedSchema,
				{ _sort: sort, _order: order },
			)
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
			await qualityStore.fetchGoldenRecord(
				qualityStore.selectedRegister,
				qualityStore.selectedSchema,
				entity.id,
			)
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../styles/views.scss' as *;

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

@include badge-2;
</style>
