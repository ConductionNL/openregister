<script setup>
import { sourceStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<div class="viewContainer">
			<!-- Header -->
			<div class="viewHeader">
				<h1 class="viewHeaderTitleIndented">
					{{ t('openregister', 'Sources') }}
				</h1>
				<p>{{ t('openregister', 'Manage your data sources and their configurations') }}</p>
			</div>

			<!-- Actions Bar -->
			<div class="viewActionsBar">
				<div class="viewInfo">
					<span class="viewTotalCount">
						{{ t('openregister', 'Showing {showing} of {total} sources', { showing: paginatedSources.length, total: sourceStore.sourceList.length }) }}
					</span>
					<span v-if="selectedSources.length > 0" class="viewIndicator">
						({{ t('openregister', '{count} selected', { count: selectedSources.length }) }})
					</span>
				</div>
				<div class="viewActions">
					<div class="viewModeSwitchContainer">
						<NcCheckboxRadioSwitch
							v-model="viewMode"
							v-tooltip="'See sources as cards'"
							:button-variant="true"
							value="cards"
							name="view_mode_radio"
							type="radio"
							button-variant-grouped="horizontal">
							Cards
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch
							v-model="viewMode"
							v-tooltip="'See sources as a table'"
							:button-variant="true"
							value="table"
							name="view_mode_radio"
							type="radio"
							button-variant-grouped="horizontal">
							Table
						</NcCheckboxRadioSwitch>
					</div>

					<NcActions
						:force-name="true"
						:inline="2"
						menu-name="Actions">
						<NcActionButton
							:primary="true"
							close-after-click
							@click="sourceStore.setSourceItem(null); navigationStore.setModal('editSource')">
							<template #icon>
								<Plus :size="20" />
							</template>
							Add Source
						</NcActionButton>
						<NcActionButton
							close-after-click
							@click="sourceStore.refreshSourceList()">
							<template #icon>
								<Refresh :size="20" />
							</template>
							Refresh
						</NcActionButton>
					</NcActions>
				</div>
			</div>

			<!-- Loading, Error, and Empty States -->
			<NcEmptyContent v-if="sourceStore.loading || sourceStore.error || !sourceStore.sourceList.length"
				:name="emptyContentName"
				:description="emptyContentDescription">
				<template #icon>
					<NcLoadingIcon v-if="sourceStore.loading" :size="64" />
					<DatabaseArrowRightOutline v-else :size="64" />
				</template>
			</NcEmptyContent>

			<!-- Content -->
			<div v-else>
				<template v-if="viewMode === 'cards'">
					<div class="cardGrid">
						<div v-for="source in paginatedSources" :key="source.id" class="card">
							<div class="cardHeader">
								<h2 v-tooltip.bottom="source.description">
									<DatabaseArrowRightOutline :size="20" />
									{{ source.title }}
								</h2>
								<NcActions :primary="true" menu-name="Actions">
									<template #icon>
										<DotsHorizontal :size="20" />
									</template>
									<NcActionButton close-after-click @click="sourceStore.setSourceItem(source); navigationStore.setModal('viewSource')">
										<template #icon>
											<Eye :size="20" />
										</template>
										View
									</NcActionButton>
									<NcActionButton close-after-click @click="sourceStore.setSourceItem(source); navigationStore.setModal('editSource')">
										<template #icon>
											<Pencil :size="20" />
										</template>
										Edit
									</NcActionButton>
									<NcActionButton close-after-click @click="sourceStore.setSourceItem(source); navigationStore.setDialog('deleteSource')">
										<template #icon>
											<TrashCanOutline :size="20" />
										</template>
										Delete
									</NcActionButton>
								</NcActions>
							</div>
							<!-- Source Details -->
							<div class="sourceDetails">
								<p v-if="source.description" class="sourceDescription">
									{{ source.description }}
								</p>
								<div class="sourceInfo">
									<div class="sourceInfoItem">
										<strong>{{ t('openregister', 'Type') }}:</strong>
										<span>{{ source.type || 'Unknown' }}</span>
									</div>
									<div v-if="source.databaseUrl" class="sourceInfoItem">
										<strong>{{ t('openregister', 'Database URL') }}:</strong>
										<span class="truncatedUrl">{{ source.databaseUrl }}</span>
									</div>
									<div class="sourceInfoItem">
										<strong>{{ t('openregister', 'Registers') }}:</strong>
										<span>{{ getSourceRegisterCount(source.id) }}</span>
									</div>
								</div>
							</div>
						</div>
					</div>
				</template>
				<template v-else>
					<div class="viewTableContainer">
						<table class="viewTable">
							<thead>
								<tr>
									<th class="tableColumnCheckbox">
										<NcCheckboxRadioSwitch
											:checked="allSelected"
											:indeterminate="someSelected"
											@update:checked="toggleSelectAll" />
									</th>
									<th>{{ t('openregister', 'Title') }}</th>
									<th>{{ t('openregister', 'Type') }}</th>
									<th>{{ t('openregister', 'Database URL') }}</th>
									<th>{{ t('openregister', 'Registers') }}</th>
									<th>{{ t('openregister', 'Created') }}</th>
									<th>{{ t('openregister', 'Updated') }}</th>
									<th class="tableColumnActions">
										{{ t('openregister', 'Actions') }}
									</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="source in paginatedSources"
									:key="source.id"
									class="viewTableRow"
									:class="{ viewTableRowSelected: selectedSources.includes(source.id) }">
									<td class="tableColumnCheckbox">
										<NcCheckboxRadioSwitch
											:checked="selectedSources.includes(source.id)"
											@update:checked="(checked) => toggleSourceSelection(source.id, checked)" />
									</td>
									<td class="tableColumnTitle">
										<div class="titleContent">
											<strong>{{ source.title }}</strong>
											<span v-if="source.description" class="textDescription textEllipsis">{{ source.description }}</span>
										</div>
									</td>
									<td>{{ source.type || 'Unknown' }}</td>
									<td class="tableColumnConstrained">
										<span v-if="source.databaseUrl" class="truncatedUrl">{{ source.databaseUrl }}</span>
										<span v-else>-</span>
									</td>
									<td>{{ getSourceRegisterCount(source.id) }}</td>
									<td>{{ source.created ? new Date(source.created).toLocaleDateString({day: '2-digit', month: '2-digit', year: 'numeric'}) + ', ' + new Date(source.created).toLocaleTimeString({hour: '2-digit', minute: '2-digit', second: '2-digit'}) : '-' }}</td>
									<td>{{ source.updated ? new Date(source.updated).toLocaleDateString({day: '2-digit', month: '2-digit', year: 'numeric'}) + ', ' + new Date(source.updated).toLocaleTimeString({hour: '2-digit', minute: '2-digit', second: '2-digit'}) : '-' }}</td>
									<td class="tableColumnActions">
										<NcActions :primary="false">
											<template #icon>
												<DotsHorizontal :size="20" />
											</template>
											<NcActionButton close-after-click @click="sourceStore.setSourceItem(source); navigationStore.setModal('viewSource')">
												<template #icon>
													<Eye :size="20" />
												</template>
												View
											</NcActionButton>
											<NcActionButton close-after-click @click="sourceStore.setSourceItem(source); navigationStore.setModal('editSource')">
												<template #icon>
													<Pencil :size="20" />
												</template>
												Edit
											</NcActionButton>
											<NcActionButton close-after-click @click="sourceStore.setSourceItem(source); navigationStore.setDialog('deleteSource')">
												<template #icon>
													<TrashCanOutline :size="20" />
												</template>
												Delete
											</NcActionButton>
										</NcActions>
									</td>
								</tr>
							</tbody>
						</table>
					</div>
				</template>
			</div>

			<!-- Pagination -->
			<PaginationComponent
				v-if="sourceStore.sourceList.length > 0"
				:current-page="pagination.page || 1"
				:total-pages="Math.ceil(sourceStore.sourceList.length / (pagination.limit || 20))"
				:total-items="sourceStore.sourceList.length"
				:current-page-size="pagination.limit || 20"
				:min-items-to-show="10"
				@page-changed="onPageChanged"
				@page-size-changed="onPageSizeChanged" />
		</div>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcEmptyContent, NcLoadingIcon, NcActions, NcActionButton, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import DatabaseArrowRightOutline from 'vue-material-design-icons/DatabaseArrowRightOutline.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Eye from 'vue-material-design-icons/Eye.vue'

import PaginationComponent from '../../components/PaginationComponent.vue'

export default {
	name: 'SourcesIndex',
	components: {
		NcAppContent,
		NcEmptyContent,
		NcLoadingIcon,
		NcActions,
		NcActionButton,
		NcCheckboxRadioSwitch,
		DatabaseArrowRightOutline,
		DotsHorizontal,
		Pencil,
		TrashCanOutline,
		Refresh,
		Plus,
		Eye,
		PaginationComponent,
	},
	data() {
		return {
			viewMode: 'cards',
			selectedSources: [],
			pagination: {
				page: 1,
				limit: 20,
			},
		}
	},
	computed: {
		paginatedSources() {
			const start = ((this.pagination.page || 1) - 1) * (this.pagination.limit || 20)
			const end = start + (this.pagination.limit || 20)
			return sourceStore.sourceList.slice(start, end)
		},
		allSelected() {
			return sourceStore.sourceList.length > 0 && sourceStore.sourceList.every(source => this.selectedSources.includes(source.id))
		},
		someSelected() {
			return this.selectedSources.length > 0 && !this.allSelected
		},
		emptyContentName() {
			if (sourceStore.loading) {
				return t('openregister', 'Loading sources...')
			} else if (sourceStore.error) {
				return sourceStore.error
			} else if (!sourceStore.sourceList.length) {
				return t('openregister', 'No sources found')
			}
			return ''
		},
		emptyContentDescription() {
			if (sourceStore.loading) {
				return t('openregister', 'Please wait while we fetch your sources.')
			} else if (sourceStore.error) {
				return t('openregister', 'Please try again later.')
			} else if (!sourceStore.sourceList.length) {
				return t('openregister', 'No sources are available.')
			}
			return ''
		},
	},
	mounted() {
		sourceStore.refreshSourceList()
	},
	methods: {
		toggleSelectAll(checked) {
			if (checked) {
				this.selectedSources = sourceStore.sourceList.map(source => source.id)
			} else {
				this.selectedSources = []
			}
		},
		toggleSourceSelection(sourceId, checked) {
			if (checked) {
				this.selectedSources.push(sourceId)
			} else {
				this.selectedSources = this.selectedSources.filter(id => id !== sourceId)
			}
		},
		onPageChanged(page) {
			this.pagination.page = page
		},
		onPageSizeChanged(pageSize) {
			this.pagination.page = 1
			this.pagination.limit = pageSize
		},
		getSourceRegisterCount(sourceId) {
			// This would need to be implemented based on how registers are linked to sources
			// For now, return a placeholder
			return '-'
		},
	},
}
</script>

<style scoped>
.sourceDetails {
	margin-top: 1rem;
}

.sourceDescription {
	color: var(--color-text-lighter);
	margin-bottom: 1rem;
}

.sourceInfo {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.sourceInfoItem {
	display: flex;
	gap: 0.5rem;
}

.sourceInfoItem strong {
	min-width: 100px;
}

.truncatedUrl {
	max-width: 200px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	display: inline-block;
}
</style>
