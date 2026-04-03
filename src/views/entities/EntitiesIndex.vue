<template>
	<NcAppContent :show-details="sidebarOpen" @update:showDetails="toggleSidebar">
		<CnIndexPage
			ref="indexPage"
			title="Entities"
			description="Manage and view detected entities from files and objects"
			:show-title="true"
			:show-add="false"
			:objects="entitiesList"
			:columns="tableColumns"
			:pagination="paginationData"
			:view-mode="viewMode"
			:selectable="false"
			:show-edit-action="false"
			:show-copy-action="false"
			:show-delete-action="false"
			:show-form-dialog="false"
			:show-mass-import="false"
			:show-mass-export="false"
			:show-mass-copy="false"
			:show-mass-delete="false"
			show-view-toggle
			:actions="customActions"
			:loading="loading"
			:refreshing="isRefreshing"
			empty-text="No entities found"
			@row-click="viewEntity"
			@refresh="handleRefresh"
			@page-changed="onPageChanged"
			@page-size-changed="onPageSizeChanged"
			@view-mode-change="viewMode = $event">
			<!-- Custom card template -->
			<template #card="{ object }">
				<CnCard
					:title="object.value"
					:labels="mapEntityLabels(object)"
					:stats="mapEntityStats(object)">
					<template #icon>
						<AccountOutline :size="20" />
					</template>
				</CnCard>
			</template>

			<!-- Custom column: value with icon -->
			<template #column-value="{ row }">
				<div class="entityValueCell">
					<AccountOutline :size="20" class="entityIcon" />
					<strong>{{ row.value }}</strong>
				</div>
			</template>

			<!-- Custom column: type badge -->
			<template #column-type="{ row }">
				<CnStatusBadge :label="row.type" variant="primary" size="small" />
			</template>

			<!-- Custom column: category badge -->
			<template #column-category="{ row }">
				<CnStatusBadge :label="row.category" variant="default" size="small" />
			</template>

			<!-- Custom column: detected at -->
			<template #column-detectedAt="{ row }">
				{{ formatDate(row.detectedAt) }}
			</template>

			<!-- Filter toggle in header actions -->
			<template #header-actions>
				<NcButton
					type="tertiary"
					:aria-label="t('openregister', 'Toggle search sidebar')"
					@click="toggleSidebar">
					<template #icon>
						<FilterVariant :size="20" />
					</template>
					{{ sidebarOpen ? t('openregister', 'Hide Filters') : t('openregister', 'Show Filters') }}
				</NcButton>
			</template>
		</CnIndexPage>

		<!-- Search Sidebar -->
		<template #details>
			<EntitiesSidebar
				:search.sync="searchQuery"
				:type.sync="typeFilter"
				:category.sync="categoryFilter"
				@update:search="handleSearchUpdate"
				@update:type="handleTypeUpdate"
				@update:category="handleCategoryUpdate" />
		</template>
	</NcAppContent>
</template>

<script>
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'

import { NcAppContent, NcButton } from '@nextcloud/vue'
import { CnIndexPage, CnCard, CnStatusBadge } from '@conduction/nextcloud-vue'

import AccountOutline from 'vue-material-design-icons/AccountOutline.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import FilterVariant from 'vue-material-design-icons/FilterVariant.vue'

import EntitiesSidebar from '../../components/EntitiesSidebar.vue'

export default {
	name: 'EntitiesIndex',
	components: {
		NcAppContent,
		NcButton,
		CnIndexPage,
		CnCard,
		CnStatusBadge,
		AccountOutline,
		FilterVariant,
		EntitiesSidebar,
	},
	data() {
		return {
			entitiesList: [],
			loading: false,
			isRefreshing: false,
			totalEntities: 0,
			viewMode: 'table',
			sidebarOpen: false,
			searchQuery: '',
			typeFilter: null,
			categoryFilter: null,
			pagination: {
				page: 1,
				limit: 20,
			},
		}
	},
	computed: {
		tableColumns() {
			return [
				{ key: 'value', label: t('openregister', 'Value'), sortable: true },
				{ key: 'type', label: t('openregister', 'Type') },
				{ key: 'category', label: t('openregister', 'Category') },
				{ key: 'detectedAt', label: t('openregister', 'Detected At'), sortable: true },
				{ key: 'relationCount', label: t('openregister', 'Relations') },
			]
		},
		paginationData() {
			const page = this.pagination.page
			const limit = this.pagination.limit
			const total = this.totalEntities
			const pages = Math.ceil(total / limit)
			return { page, pages, total, limit }
		},
		customActions() {
			return [
				{
					label: 'View',
					icon: Eye,
					handler: (row) => this.viewEntity(row),
				},
			]
		},
	},
	mounted() {
		this.loadEntities()
	},
	methods: {
		t,

		toggleSidebar() {
			this.sidebarOpen = !this.sidebarOpen
		},

		handleSearchUpdate(query) {
			this.searchQuery = query
			this.pagination.page = 1
			this.loadEntities()
		},

		handleTypeUpdate(type) {
			this.typeFilter = type
			this.pagination.page = 1
			this.loadEntities()
		},

		handleCategoryUpdate(category) {
			this.categoryFilter = category
			this.pagination.page = 1
			this.loadEntities()
		},

		async loadEntities() {
			this.loading = true
			try {
				const params = {
					limit: this.pagination.limit,
					offset: (this.pagination.page - 1) * this.pagination.limit,
				}

				if (this.searchQuery) {
					params.search = this.searchQuery
				}

				if (this.typeFilter) {
					params.type = this.typeFilter
				}

				if (this.categoryFilter) {
					params.category = this.categoryFilter
				}

				const response = await axios.get(
					generateUrl('/apps/openregister/api/entities'),
					{ params },
				)

				if (response.data.success) {
					this.entitiesList = response.data.data
					this.totalEntities = response.data.count || this.entitiesList.length
				}
			} catch (error) {
				console.error('Failed to load entities:', error)
				showError(t('openregister', 'Failed to load entities'))
			} finally {
				this.loading = false
			}
		},

		async handleRefresh() {
			this.isRefreshing = true
			try {
				await this.loadEntities()
			} finally {
				this.isRefreshing = false
			}
		},

		onPageChanged(page) {
			this.pagination.page = page
			this.loadEntities()
		},

		onPageSizeChanged(pageSize) {
			this.pagination.page = 1
			this.pagination.limit = pageSize
			this.loadEntities()
		},

		viewEntity(entity) {
			this.$router.push({ name: 'entityDetails', params: { id: entity.id } })
		},

		formatDate(date) {
			if (!date) return '-'
			return new Date(date).toLocaleString()
		},

		mapEntityLabels(entity) {
			const labels = []
			if (entity.type) {
				labels.push({ text: entity.type, color: 'primary' })
			}
			if (entity.category) {
				labels.push({ text: entity.category, color: 'info' })
			}
			return labels
		},

		mapEntityStats(entity) {
			return [
				{ label: t('openregister', 'Detected At'), value: this.formatDate(entity.detectedAt) },
				{ label: t('openregister', 'Relations'), value: String(entity.relationCount || 0) },
			]
		},
	},
}
</script>

<style scoped>
.entityValueCell {
	display: flex;
	align-items: center;
	gap: 8px;
}

.entityIcon {
	color: var(--color-primary-element);
	flex-shrink: 0;
}

</style>
