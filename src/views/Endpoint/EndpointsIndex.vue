<script setup>
import { translate as t } from '@nextcloud/l10n'
import { endpointStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<CnIndexPage
			ref="indexPage"
			title="Endpoints"
			description="Manage API endpoints"
			:show-title="true"
			:schema="endpointSchema"
			:objects="endpointStore.endpointList"
			:columns="tableColumns"
			:view-mode="viewMode"
			:show-form-dialog="true"
			:show-copy-action="false"
			:show-edit-action="true"
			:show-delete-action="true"
			:show-mass-import="false"
			:show-mass-export="false"
			:show-mass-copy="false"
			:show-mass-delete="false"
			show-view-toggle
			add-label="Add Endpoint"
			empty-text="No endpoints found"
			mass-action-name-field="name"
			:actions="customActions"
			:refreshing="isRefreshing"
			@create="onCreateEndpoint"
			@edit="onEditEndpoint"
			@delete="onDeleteEndpoint"
			@refresh="handleRefresh"
			@view-mode-change="viewMode = $event">
			<!-- Custom column: name with description -->
			<template #column-name="{ row }">
				<div class="titleContent">
					<strong>{{ row.name }}</strong>
					<span v-if="row.description" class="textDescription textEllipsis">{{ row.description }}</span>
				</div>
			</template>
		</CnIndexPage>
	</NcAppContent>
</template>

<script>
import { NcAppContent } from '@nextcloud/vue'
import { CnIndexPage } from '@conduction/nextcloud-vue'
import PlayCircle from 'vue-material-design-icons/PlayCircle.vue'

export default {
	name: 'EndpointsIndex',
	components: {
		NcAppContent,
		CnIndexPage,
	},
	data() {
		return {
			viewMode: 'table',
			isRefreshing: false,
		}
	},
	computed: {
		endpointSchema() {
			return {
				title: 'Endpoint',
				properties: {
					name: {
						type: 'string',
						title: t('openregister', 'Name'),
						required: true,
						maxLength: 255,
						order: 1,
					},
					description: {
						type: 'string',
						title: t('openregister', 'Description'),
						format: 'textarea',
						order: 2,
					},
					endpoint: {
						type: 'string',
						title: t('openregister', 'Endpoint Path'),
						description: '/api/example/{{id}}',
						required: true,
						maxLength: 255,
						order: 3,
					},
					method: {
						type: 'string',
						title: t('openregister', 'Method'),
						required: true,
						enum: ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS', 'HEAD'],
						order: 4,
					},
					targetType: {
						type: 'string',
						title: t('openregister', 'Target Type'),
						required: true,
						enum: ['view', 'agent', 'webhook', 'register', 'schema'],
						order: 5,
					},
					targetId: {
						type: 'string',
						title: t('openregister', 'Target ID'),
						description: t('openregister', 'ID of the target resource'),
						order: 6,
					},
					version: {
						type: 'string',
						title: t('openregister', 'Version'),
						description: '0.0.0',
						order: 7,
					},
					inputMapping: {
						type: 'string',
						title: t('openregister', 'Input Mapping'),
						description: t('openregister', 'ID of input mapping (optional)'),
						order: 8,
					},
					outputMapping: {
						type: 'string',
						title: t('openregister', 'Output Mapping'),
						description: t('openregister', 'ID of output mapping (optional)'),
						order: 9,
					},
				},
				required: ['name', 'endpoint', 'method', 'targetType'],
			}
		},
		tableColumns() {
			return [
				{ key: 'name', label: t('openregister', 'Name'), sortable: true },
				{ key: 'method', label: t('openregister', 'Method') },
				{ key: 'endpoint', label: t('openregister', 'Endpoint Path') },
				{ key: 'targetType', label: t('openregister', 'Target Type') },
				{ key: 'version', label: t('openregister', 'Version') },
			]
		},
		customActions() {
			return [
				{
					label: t('openregister', 'Test'),
					icon: PlayCircle,
					handler: (row) => this.handleTest(row),
				},
			]
		},
	},
	mounted() {
		endpointStore.refreshEndpointList()
	},
	methods: {
		async onCreateEndpoint(formData) {
			try {
				await endpointStore.createEndpoint(formData)
				this.$refs.indexPage.setFormResult({ success: true })
			} catch (error) {
				this.$refs.indexPage.setFormResult({
					error: error.message || 'An error occurred while creating the endpoint',
				})
			}
		},
		async onEditEndpoint(formData) {
			try {
				await endpointStore.updateEndpoint(formData)
				this.$refs.indexPage.setFormResult({ success: true })
			} catch (error) {
				this.$refs.indexPage.setFormResult({
					error: error.message || 'An error occurred while updating the endpoint',
				})
			}
		},
		async onDeleteEndpoint(id) {
			const endpoint = endpointStore.endpointList.find(e => e.id === id)
			if (!endpoint) return
			try {
				await endpointStore.deleteEndpoint(endpoint)
				this.$refs.indexPage.setSingleDeleteResult({ success: true })
			} catch (error) {
				this.$refs.indexPage.setSingleDeleteResult({
					error: error.message || 'An error occurred while deleting the endpoint',
				})
			}
		},
		handleTest(endpoint) {
			endpointStore.testEndpoint(endpoint)
				.then((result) => {
					if (result.success) {
						OCP.Toast.success('Endpoint tested successfully')
					} else {
						OCP.Toast.error(`Endpoint test failed: ${result.error || result.message}`)
					}
				})
				.catch((error) => {
					OCP.Toast.error(`Error testing endpoint: ${error.message}`)
				})
		},
		async handleRefresh() {
			this.isRefreshing = true
			try {
				await endpointStore.refreshEndpointList()
			} finally {
				this.isRefreshing = false
			}
		},
	},
}
</script>

<style scoped>
.titleContent {
	display: flex;
	flex-direction: column;
}
</style>
