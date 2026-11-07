<template>
	<NcAppContent :showDetails="sidebarOpen" @update:showDetails="toggleSidebar">
		<div class="viewContainer">
			<!-- Header -->
			<FilterToggleHeader
				:title="t('openregister', 'Templates')"
				:description="
					t('openregister', 'Manage document templates and themes')
				"
				:sidebarOpen="sidebarOpen"
				@toggle="toggleSidebar" />

			<!-- Actions Bar -->
			<ListActionsBar
				:summary="
					templatesList.length
						? t(
								'openregister',
								'Showing {showing} of {total} templates',
								{
									showing: templatesList.length,
									total: totalTemplates,
								},
							)
						: ''
				"
				:actions="[refreshAction(refreshTemplates)]" />

			<!-- Templates Table -->
			<div class="tableContainer">
				<NcLoadingIcon v-if="loading" :size="64" />

				<NcEmptyContent
					v-else-if="!templatesList.length"
					:name="t('openregister', 'Templates are coming soon')"
					:description="
						t(
							'openregister',
							'The templates feature is not available yet. This page is a placeholder and will list templates once the feature ships.',
						)
					">
					<template #icon>
						<FileOutline :size="64" />
					</template>
				</NcEmptyContent>

				<table v-else class="templatesTable">
					<thead>
						<tr>
							<th scope="col" class="column-name">
								{{ t('openregister', 'Name') }}
							</th>
							<th scope="col" class="column-type">
								{{ t('openregister', 'Type') }}
							</th>
							<th scope="col" class="column-description">
								{{ t('openregister', 'Description') }}
							</th>
							<th scope="col" class="column-updated">
								{{ t('openregister', 'Updated At') }}
							</th>
							<th scope="col" class="column-actions">
								{{ t('openregister', 'Actions') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="template in templatesList" :key="template.id">
							<td class="column-name">
								<span class="template-name">{{
									template.name
								}}</span>
							</td>
							<td class="column-type">
								<span class="badge badge-type">{{
									template.type
								}}</span>
							</td>
							<td class="column-description">
								{{ template.description || '-' }}
							</td>
							<td class="column-updated">
								{{ formatDate(template.updatedAt) }}
							</td>
							<td class="column-actions">
								<ActionMenu :actions="templateActions(template)" />
							</td>
						</tr>
					</tbody>
				</table>

				<!-- Pagination -->
				<OffsetPager
					:offset="offset"
					:limit="limit"
					:total="totalTemplates"
					@previous="previousPage"
					@next="nextPage" />
			</div>
		</div>
	</NcAppContent>
</template>

<script>
import { t } from '@nextcloud/l10n'
import { NcAppContent, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import EyeOutline from 'vue-material-design-icons/EyeOutline.vue'
import FileOutline from 'vue-material-design-icons/FileOutline.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import ActionMenu from '../../components/shared/ActionMenu.vue'
import FilterToggleHeader from '../../components/shared/FilterToggleHeader.vue'
import ListActionsBar from '../../components/shared/ListActionsBar.vue'
import OffsetPager from '../../components/shared/OffsetPager.vue'
import { refreshAction } from '../../services/menuActions.js'
import { offsetPaging } from '../offsetPaging.js'

/**
 * Main view for managing templates
 */
export default {
	name: 'TemplatesIndex',
	components: {
		ActionMenu,
		ListActionsBar,
		OffsetPager,
		FilterToggleHeader,
		NcAppContent,
		NcLoadingIcon,
		NcEmptyContent,
		FileOutline,
	},

	mixins: [offsetPaging('totalTemplates', 'loadTemplates')],

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

	computed: {},

	mounted() {
		this.loadTemplates()
	},

	methods: {
		refreshAction,

		/**
		 * The row menu of a template.
		 *
		 * @spec exclude Client-side menu composition.
		 * @param {object} template The template
		 * @return {Array<object>} The ActionMenu entries
		 */
		templateActions(template) {
			return [
				{
					label: t('openregister', 'View Details'),
					icon: EyeOutline,
					run: () => this.viewTemplate(template),
				},
			]
		},

		t,

		/**
		 * Toggle sidebar visibility
		 *
		 * @spec openspec/specs/admin-list-views/spec.md
		 * @return {void}
		 */
		toggleSidebar() {
			this.sidebarOpen = !this.sidebarOpen
		},

		/**
		 * Load templates from the API
		 *
		 * @spec exclude UI plumbing — list load hydrating local state (stubbed pending API); list contract owned by admin-list-views.
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
			} catch {
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
		 * @spec exclude UI plumbing — delegates to loadTemplates.
		 * @return {void}
		 */
		refreshTemplates() {
			this.loadTemplates()
		},

		/**
		 * View template details
		 *
		 * @spec exclude UI plumbing — unimplemented navigation stub, no observable contract.
		 * @param {object} _template - Template object
		 * @return {void}
		 */
		viewTemplate(_template) {
			// TODO: Navigate to template details page when available
		},

		/**
		 * Format date for display
		 *
		 * @spec exclude UI plumbing — pure display formatter, no observable contract.
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

<style scoped lang="scss">
@use '../../styles/views.scss' as *;

@include view-container-2;

@include table-container;

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

@include badge-rules;

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
</style>
