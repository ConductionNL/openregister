<template>
	<NcAppContent :show-details="sidebarOpen" @update:showDetails="toggleSidebar">
		<div class="viewContainer">
			<!-- Header -->
			<div class="viewHeader">
				<div class="viewHeaderTitle">
					<h1 class="viewHeaderTitleIndented">
						{{ t('openregister', 'Templates') }}
					</h1>
					<NcButton
						type="tertiary"
						:aria-label="t('openregister', 'Toggle search sidebar')"
						@click="toggleSidebar">
						<template #icon>
							<FilterVariant :size="20" />
						</template>
						{{ sidebarOpen ? t('openregister', 'Hide Filters') : t('openregister', 'Show Filters') }}
					</NcButton>
				</div>
				<p>
					{{ t('openregister', 'Manage document templates and themes') }}
				</p>
			</div>

			<!-- Actions Bar -->
			<div class="viewActionsBar">
				<div class="viewInfo">
					<span v-if="templatesList.length" class="viewTotalCount">
						{{ t('openregister', 'Showing {showing} of {total} templates', {
							showing: templatesList.length,
							total: totalTemplates
						}) }}
					</span>
				</div>
				<div class="viewActions">
					<NcActions
						:force-name="true"
						:inline="1"
						menu-name="Actions">
						<NcActionButton
							close-after-click
							@click="refreshTemplates">
							<template #icon>
								<Refresh :size="20" />
							</template>
							{{ t('openregister', 'Refresh') }}
						</NcActionButton>
					</NcActions>
				</div>
			</div>

			<!-- Templates Table -->
			<div class="tableContainer">
				<NcLoadingIcon v-if="loading" :size="64" />

				<NcEmptyContent
					v-else-if="!templatesList.length"
					:name="t('openregister', 'No templates found')"
					:description="t('openregister', 'No templates have been created yet')">
					<template #icon>
						<FileOutline :size="64" />
					</template>
				</NcEmptyContent>

				<table v-else class="templatesTable">
					<thead>
						<tr>
							<th class="column-name">
								{{ t('openregister', 'Name') }}
							</th>
							<th class="column-type">
								{{ t('openregister', 'Type') }}
							</th>
							<th class="column-description">
								{{ t('openregister', 'Description') }}
							</th>
							<th class="column-updated">
								{{ t('openregister', 'Updated At') }}
							</th>
							<th class="column-actions">
								{{ t('openregister', 'Actions') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="template in templatesList" :key="template.id">
							<td class="column-name">
								<span class="template-name">{{ template.name }}</span>
							</td>
							<td class="column-type">
								<span class="badge badge-type">{{ template.type }}</span>
							</td>
							<td class="column-description">
								{{ template.description || '-' }}
							</td>
							<td class="column-updated">
								{{ formatDate(template.updatedAt) }}
							</td>
							<td class="column-actions">
								<NcActions>
									<NcActionButton
										close-after-click
										@click="viewTemplate(template)">
										<template #icon>
											<EyeOutline :size="20" />
										</template>
										{{ t('openregister', 'View Details') }}
									</NcActionButton>
								</NcActions>
							</td>
						</tr>
					</tbody>
				</table>

				<!-- Pagination -->
				<div v-if="totalTemplates > limit" class="pagination">
					<NcButton
						:disabled="offset === 0"
						@click="previousPage">
						{{ t('openregister', 'Previous') }}
					</NcButton>
					<span class="pagination-info">
						{{ t('openregister', 'Page {current} of {total}', {
							current: currentPage,
							total: totalPages
						}) }}
					</span>
					<NcButton
						:disabled="offset + limit >= totalTemplates"
						@click="nextPage">
						{{ t('openregister', 'Next') }}
					</NcButton>
				</div>
			</div>
		</div>
	</NcAppContent>
</template>

<script>
import { t } from '@nextcloud/l10n'

import {
	NcAppContent,
	NcActions,
	NcActionButton,
	NcButton,
	NcLoadingIcon,
	NcEmptyContent,
} from '@nextcloud/vue'

import FileOutline from 'vue-material-design-icons/FileOutline.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import FilterVariant from 'vue-material-design-icons/FilterVariant.vue'
import EyeOutline from 'vue-material-design-icons/EyeOutline.vue'

/**
 * Main view for managing templates
 */
export default {
	name: 'TemplatesIndex',
	components: {
		NcAppContent,
		NcActions,
		NcActionButton,
		NcButton,
		NcLoadingIcon,
		NcEmptyContent,
		FileOutline,
		Refresh,
		FilterVariant,
		EyeOutline,
	},
	data() {
		return {
			templatesList: [],
			loading: false,
			totalTemplates: 0,
			limit: 50,
			offset: 0,
			sidebarOpen: false,
		}
	},
	computed: {
		/**
		 * Get current page number
		 *
		 * @return {number} Current page
		 */
		currentPage() {
			return Math.floor(this.offset / this.limit) + 1
		},

		/**
		 * Get total number of pages
		 *
		 * @return {number} Total pages
		 */
		totalPages() {
			return Math.ceil(this.totalTemplates / this.limit)
		},
	},
	mounted() {
		this.loadTemplates()
	},
	methods: {
		t,

		/**
		 * Toggle sidebar visibility
		 *
		 * @return {void}
		 */
		toggleSidebar() {
			this.sidebarOpen = !this.sidebarOpen
		},

		/**
		 * Load templates from the API
		 *
		 * @return {Promise<void>}
		 */
		async loadTemplates() {
			this.loading = true
			try {
				// TODO: Replace with actual templates API endpoint when available
				// For now, show empty state
				this.templatesList = []
				this.totalTemplates = 0

				// Uncomment when API is available:
				// const params = {
				//     limit: this.limit,
				//     offset: this.offset,
				// }
				//
				// const response = await axios.get(
				//     generateUrl('/apps/openregister/api/templates'),
				//     { params },
				// )
				//
				// if (response.data.success) {
				//     this.templatesList = response.data.data
				//     this.totalTemplates = response.data.count || this.templatesList.length
				// }
			} catch (error) {
				// TODO: Uncomment when API is implemented
				// console.error('Failed to load templates:', error)
				// showError(t('openregister', 'Failed to load templates'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * Refresh the templates list
		 *
		 * @return {void}
		 */
		refreshTemplates() {
			this.loadTemplates()
		},

		/**
		 * Go to previous page
		 *
		 * @return {void}
		 */
		previousPage() {
			if (this.offset > 0) {
				this.offset = Math.max(0, this.offset - this.limit)
				this.loadTemplates()
			}
		},

		/**
		 * Go to next page
		 *
		 * @return {void}
		 */
		nextPage() {
			if (this.offset + this.limit < this.totalTemplates) {
				this.offset += this.limit
				this.loadTemplates()
			}
		},

		/**
		 * View template details
		 *
		 * @param {object} template - Template object
		 * @return {void}
		 */
		viewTemplate(template) {
			// TODO: Navigate to template details page when available
			// console.log('View template:', template)
		},

		/**
		 * Format date for display
		 *
		 * @param {string} date - Date string
		 * @return {string} Formatted date
		 */
		formatDate(date) {
			if (!date) return '-'
			return new Date(date).toLocaleString()
		},
	},
}
</script>

<style scoped>
.viewContainer {
	padding: 20px;
	max-width: 100%;
}

.viewHeader {
	margin-bottom: 20px;
}

.viewHeaderTitle {
	display: flex;
	align-items: center;
	gap: 16px;
	margin-bottom: 8px;
}

.viewHeaderTitleIndented {
	margin: 0;
	font-size: 28px;
	font-weight: 600;
}

.viewHeader p {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.viewActionsBar {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 20px;
	padding: 12px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius-large);
}

.viewInfo {
	display: flex;
	gap: 12px;
	align-items: center;
}

.viewTotalCount {
	font-weight: 600;
}

.viewActions {
	display: flex;
	gap: 8px;
}

.tableContainer {
	background: var(--color-main-background);
	border-radius: var(--border-radius-large);
	overflow: hidden;
}

.templatesTable {
	width: 100%;
	border-collapse: collapse;
}

.templatesTable thead {
	background: var(--color-background-hover);
	border-bottom: 2px solid var(--color-border);
}

.templatesTable th {
	padding: 12px 16px;
	text-align: left;
	font-weight: 600;
	white-space: nowrap;
}

.templatesTable td {
	padding: 12px 16px;
	border-bottom: 1px solid var(--color-border);
}

.templatesTable tbody tr:hover {
	background: var(--color-background-hover);
}

.template-name {
	font-weight: 500;
}

.badge {
	display: inline-block;
	padding: 4px 8px;
	border-radius: 12px;
	font-size: 12px;
	font-weight: 600;
	text-transform: uppercase;
}

.badge-type {
	background: var(--color-primary-light);
	color: var(--color-primary-element);
}

.column-name {
	min-width: 200px;
}

.column-type {
	width: 120px;
}

.column-description {
	min-width: 300px;
}

.column-updated {
	width: 180px;
}

.column-actions {
	width: 50px;
}

.pagination {
	display: flex;
	justify-content: center;
	align-items: center;
	gap: 16px;
	padding: 20px;
	border-top: 1px solid var(--color-border);
}

.pagination-info {
	color: var(--color-text-maxcontrast);
	font-size: 14px;
}
</style>


