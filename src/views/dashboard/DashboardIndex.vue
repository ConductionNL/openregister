<script setup>
import { dashboardStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<h2 class="pageHeader">
			Dashboard
		</h2>

		<div class="dashboardContent">
			<div v-if="dashboardStore.loading" class="loading">
				<NcLoadingIcon :size="32" />
				<span>Loading registers...</span>
			</div>
			<div v-else-if="dashboardStore.error" class="error">
				<NcEmptyContent :title="dashboardStore.error" icon="icon-error" />
			</div>
			<div v-else-if="!dashboardStore.registers || dashboardStore.registers.length === 0" class="empty">
				<NcEmptyContent title="No registers found" icon="icon-folder" />
			</div>
			<div v-else class="registers">
				<div v-for="register in dashboardStore.registers" :key="register.id" class="registerCard">
					<div class="registerHeader">
						<h2>
							<DatabaseOutline :size="20" />
							{{ register.title }}
						</h2>
						<NcActions :primary="true" menu-name="Actions">
							<template #icon>
								<DotsHorizontal :size="20" />
							</template>
							<NcActionButton :disabled="calculating === register.id" @click="calculateSizes(register)">
								<template #icon>
									<Calculator :size="20" />
								</template>
								Calculate Sizes
							</NcActionButton>
							<NcActionButton @click="navigationStore.setModal('editRegister')">
								<template #icon>
									<Pencil :size="20" />
								</template>
								Edit
							</NcActionButton>
							<NcActionButton @click="navigationStore.setModal('exportRegister')">
								<template #icon>
									<Export :size="20" />
								</template>
								Export
							</NcActionButton>
							<NcActionButton @click="navigationStore.setModal('uploadRegister')">
								<template #icon>
									<Upload :size="20" />
								</template>
								Upload
							</NcActionButton>
							<NcActionButton @click="viewOasDoc(register)">
								<template #icon>
									<ApiIcon :size="20" />
								</template>
								View API Documentation
							</NcActionButton>
							<NcActionButton @click="downloadOas(register)">
								<template #icon>
									<Download :size="20" />
								</template>
								Download API Specification
							</NcActionButton>
							<NcActionButton @click="navigationStore.setDialog('deleteRegister')">
								<template #icon>
									<TrashCanOutline :size="20" />
								</template>
								Delete
							</NcActionButton>
						</NcActions>
					</div>
					<p class="registerDescription">
						{{ register.description }}
					</p>

					<!-- Register Statistics Table -->
					<table class="statisticsTable registerStats">
						<thead>
							<tr>
								<th>{{ t('openregister', 'Type') }}</th>
								<th>{{ t('openregister', 'Total') }}</th>
								<th>{{ t('openregister', 'Size') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr>
								<td>{{ t('openregister', 'Objects') }}</td>
								<td>{{ register.stats?.objects?.total || 0 }}</td>
								<td>{{ formatBytes(register.stats?.objects?.size || 0) }}</td>
							</tr>
							<tr class="subRow">
								<td class="indented">
									{{ t('openregister', 'Invalid') }}
								</td>
								<td>{{ register.stats?.objects?.invalid || 0 }}</td>
								<td>-</td>
							</tr>
							<tr class="subRow">
								<td class="indented">
									{{ t('openregister', 'Deleted') }}
								</td>
								<td>{{ register.stats?.objects?.deleted || 0 }}</td>
								<td>-</td>
							</tr>
							<tr class="subRow">
								<td class="indented">
									{{ t('openregister', 'Locked') }}
								</td>
								<td>{{ register.stats?.objects?.locked || 0 }}</td>
								<td>-</td>
							</tr>
							<tr class="subRow">
								<td class="indented">
									{{ t('openregister', 'Published') }}
								</td>
								<td>{{ register.stats?.objects?.published || 0 }}</td>
								<td>-</td>
							</tr>
							<tr>
								<td>{{ t('openregister', 'Logs') }}</td>
								<td>{{ register.stats?.logs?.total || 0 }}</td>
								<td>{{ formatBytes(register.stats?.logs?.size || 0) }}</td>
							</tr>
							<tr>
								<td>{{ t('openregister', 'Files') }}</td>
								<td>{{ register.stats?.files?.total || 0 }}</td>
								<td>{{ formatBytes(register.stats?.files?.size || 0) }}</td>
							</tr>
						</tbody>
					</table>

					<div class="schemas">
						<div v-for="schema in register.schemas" :key="schema.id" class="schema">
							<div class="schemaHeader" @click="toggleSchema(schema.id)">
								<div class="schemaTitle">
									<FileCodeOutline :size="16" />
									<span>{{ schema.stats?.objects?.total || 0 }} </span>
									{{ schema.title }}
									<span class="schemaSize">({{ formatBytes(schema.stats?.objects?.size || 0) }})</span>
								</div>
								<button class="schemaToggle">
									<ChevronDown v-if="!expandedSchemas.has(schema.id)" :size="20" />
									<ChevronUp v-else :size="20" />
								</button>
							</div>

							<!-- Schema Statistics Table -->
							<table v-if="expandedSchemas.has(schema.id)" class="statisticsTable schemaStats">
								<thead>
									<tr>
										<th>Type</th>
										<th>Total</th>
										<th>Size</th>
									</tr>
								</thead>
								<tbody>
									<tr>
										<td>Objects</td>
										<td>{{ schema.stats?.objects?.total || 0 }}</td>
										<td>{{ formatBytes(schema.stats?.objects?.size || 0) }}</td>
									</tr>
									<tr class="subRow">
										<td class="indented">
											Invalid
										</td>
										<td>{{ schema.stats?.objects?.invalid || 0 }}</td>
										<td>-</td>
									</tr>
									<tr class="subRow">
										<td class="indented">
											Deleted
										</td>
										<td>{{ schema.stats?.objects?.deleted || 0 }}</td>
										<td>-</td>
									</tr>
									<tr>
										<td>Logs</td>
										<td>{{ schema.stats?.logs?.total || 0 }}</td>
										<td>{{ formatBytes(schema.stats?.logs?.size || 0) }}</td>
									</tr>
									<tr>
										<td>Files</td>
										<td>{{ schema.stats?.files?.total || 0 }}</td>
										<td>{{ formatBytes(schema.stats?.files?.size || 0) }}</td>
									</tr>
								</tbody>
							</table>
						</div>
					</div>
				</div>
			</div>
		</div>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcEmptyContent, NcLoadingIcon, NcActions, NcActionButton } from '@nextcloud/vue'
import DatabaseOutline from 'vue-material-design-icons/DatabaseOutline.vue'
import FileCodeOutline from 'vue-material-design-icons/FileCodeOutline.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronUp from 'vue-material-design-icons/ChevronUp.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import Upload from 'vue-material-design-icons/Upload.vue'
import Export from 'vue-material-design-icons/Export.vue'
import ApiIcon from 'vue-material-design-icons/Api.vue'
import Download from 'vue-material-design-icons/Download.vue'
import Calculator from 'vue-material-design-icons/Calculator.vue'
import axios from '@nextcloud/axios'
import { showSuccess, showError } from '@nextcloud/dialogs'

export default {
	name: 'DashboardIndex',
	components: {
		NcAppContent,
		NcEmptyContent,
		NcLoadingIcon,
		NcActions,
		NcActionButton,
		DatabaseOutline,
		FileCodeOutline,
		ChevronDown,
		ChevronUp,
		DotsHorizontal,
		Pencil,
		TrashCanOutline,
		Upload,
		Export,
		ApiIcon,
		Download,
		Calculator,
	},
	data() {
		return {
			expandedSchemas: new Set(),
			calculating: null,
		}
	},
	mounted() {
		console.log('Component mounted, fetching registers...')
		dashboardStore.fetchRegisters()
		console.log('Initial store state:', {
			loading: dashboardStore.loading,
			error: dashboardStore.error,
			registers: dashboardStore.registers,
		})
	},
	methods: {
		toggleSchema(schemaId) {
			if (this.expandedSchemas.has(schemaId)) {
				this.expandedSchemas.delete(schemaId)
			} else {
				this.expandedSchemas.add(schemaId)
			}
		},

		formatBytes(bytes) {
			if (!bytes || bytes === 0) return '0 KB'
			const k = 1024
			const sizes = ['B', 'KB', 'MB', 'GB']
			const i = Math.floor(Math.log(bytes) / Math.log(k))
			return `${parseFloat((bytes / Math.pow(k, i)).toFixed(2))} ${sizes[i]}`
		},

		async calculateSizes(register) {
			console.log('Calculating sizes for register:', register)
			this.calculating = register.id
			try {
				await axios.post(`/index.php/apps/openregister/api/dashboard/calculate/${register.id}`)
				showSuccess(t('openregister', 'Sizes calculated successfully'))
				await dashboardStore.fetchRegisters()
				console.log('Registers refreshed after calculation:', dashboardStore.registers)
			} catch (error) {
				showError(t('openregister', 'Failed to calculate sizes'))
				console.error('Failed to calculate sizes:', error)
			} finally {
				this.calculating = null
			}
		},

		async downloadOas(register) {
			const baseUrl = window.location.origin
			const apiUrl = `${baseUrl}/index.php/apps/openregister/api/registers/${register.id}/oas`
			try {
				const response = await axios.get(apiUrl)
				const blob = new Blob([JSON.stringify(response.data, null, 2)], { type: 'application/json' })
				const downloadLink = document.createElement('a')
				downloadLink.href = URL.createObjectURL(blob)
				downloadLink.download = `${register.title.toLowerCase()}-api-specification.json`
				document.body.appendChild(downloadLink)
				downloadLink.click()
				document.body.removeChild(downloadLink)
				URL.revokeObjectURL(downloadLink.href)
			} catch (error) {
				showError(t('openregister', 'Failed to download API specification'))
				console.error('Error downloading OAS:', error)
			}
		},

		viewOasDoc(register) {
			const baseUrl = window.location.origin
			const apiUrl = `${baseUrl}/index.php/apps/openregister/api/registers/${register.id}/oas`
			window.open(`https://redocly.github.io/redoc/?url=${encodeURIComponent(apiUrl)}`, '_blank')
		},
	},
}
</script>

<style lang="scss" scoped>
.dashboardContent {
	margin-inline: auto;
	max-width: 1200px;
	padding-block: 20px;
	padding-inline: 20px;
}

.loading {
	display: flex;
	align-items: center;
	gap: 10px;
	color: var(--color-text-maxcontrast);
	justify-content: center;
	padding-block: 40px;
}

.registers {
	display: grid;
	grid-template-columns: 1fr;
	gap: 1.5rem;
}

@media screen and (min-width: 880px) {
	.registers {
		grid-template-columns: repeat(2, 1fr);
	}
}

@media screen and (min-width: 1220px) {
	.registers {
		grid-template-columns: repeat(3, 1fr);
	}
}

.registerCard {
	background: var(--color-main-background);
	border-radius: 8px;
	padding-block: 20px;
	padding-inline: 20px;
	box-shadow: 0 2px 8px var(--color-box-shadow);
	min-height: 200px;
	transition: transform 0.2s ease-in-out;
	border: 1px solid var(--color-border);

	&:hover {
		transform: scale(1.01);
		box-shadow: 0 4px 12px var(--color-box-shadow);
	}
}

.registerHeader {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	margin-block-end: 12px;
	padding-block-end: 8px;
	border-block-end: 1px solid var(--color-border);

	h2 {
		display: flex;
		align-items: center;
		gap: 8px;
		margin: 0;
		font-size: 1.2em;
		color: var(--color-main-text);
	}
}

.registerDescription {
	color: var(--color-text-maxcontrast);
	margin-block-end: 16px;
	line-height: 1.5;
}

.schemas {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-block-start: 20px;
	padding-block-start: 16px;
	border-block-start: 1px solid var(--color-border);
}

.schema {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding-block: 8px;
	padding-inline: 12px;
	background-color: var(--color-main-background);

	&:hover {
		background-color: var(--color-background-hover);
	}
}

.schemaHeader {
	display: flex;
	justify-content: space-between;
	align-items: center;
	cursor: pointer;
}

.schemaTitle {
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: 1em;
}

.schemaSize {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.schemaToggle {
	background: none;
	border: none;
	padding: 4px;
	cursor: pointer;
	color: var(--color-text-maxcontrast);

	&:hover {
		color: var(--color-main-text);
	}
}

.statisticsTable {
	width: 100%;
	border-collapse: collapse;
	font-size: 0.9em;

	&.registerStats {
		margin-block: 16px;
		background-color: var(--color-background-hover);
		border-radius: var(--border-radius);
		overflow: hidden;
	}

	&.schemaStats {
		margin-block: 12px;
	}
}

.statisticsTable th,
.statisticsTable td {
	padding-block: 8px;
	padding-inline: 8px;
	text-align: start;
	border: none;
}

.statisticsTable th {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
	background-color: var(--color-background-darker);
}

.statisticsTable tr:hover {
	background-color: var(--color-background-hover);
}

.subRow td {
	color: var(--color-text-maxcontrast);
}

.indented {
	padding-inline-start: 24px !important;
}
</style>
