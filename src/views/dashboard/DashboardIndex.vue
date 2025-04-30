<template>
	<NcAppContent>
		<h2 class="pageHeader">
			Dashboard
		</h2>

		<div class="dashboard-content">
			<div v-if="loading" class="loading">
				<NcLoadingIcon :size="32" />
				<span>Loading registers...</span>
			</div>
			<div v-else-if="error" class="error">
				<NcEmptyContent :title="error" icon="icon-error" />
			</div>
			<div v-else-if="!registers || registers.length === 0" class="empty">
				<NcEmptyContent title="No registers found" icon="icon-folder" />
			</div>
			<div v-else class="registers">
				<div v-for="register in registers" :key="register.id" class="register-card">
					<div class="register-header">
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
					<p class="register-description">
						{{ register.description }}
					</p>

					<!-- Register Statistics Table -->
					<table class="statistics-table register-stats">
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
							<tr class="sub-row">
								<td class="indented">
									{{ t('openregister', 'Invalid') }}
								</td>
								<td>{{ register.stats?.objects?.invalid || 0 }}</td>
								<td>-</td>
							</tr>
							<tr class="sub-row">
								<td class="indented">
									{{ t('openregister', 'Deleted') }}
								</td>
								<td>{{ register.stats?.objects?.deleted || 0 }}</td>
								<td>-</td>
							</tr>
							<tr class="sub-row">
								<td class="indented">
									{{ t('openregister', 'Locked') }}
								</td>
								<td>{{ register.stats?.objects?.locked || 0 }}</td>
								<td>-</td>
							</tr>
							<tr class="sub-row">
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
							<div class="schema-header" @click="toggleSchema(schema.id)">
								<div class="schema-title">
									<FileCodeOutline :size="16" />
									<span>{{ schema.stats?.objects?.total || 0 }} </span>
									{{ schema.title }}
									<span class="schema-size">({{ formatBytes(schema.stats?.objects?.size || 0) }})</span>
								</div>
								<button class="schema-toggle">
									<ChevronDown v-if="!expandedSchemas.has(schema.id)" :size="20" />
									<ChevronUp v-else :size="20" />
								</button>
							</div>

							<!-- Schema Statistics Table -->
							<table v-if="expandedSchemas.has(schema.id)" class="statistics-table schema-stats">
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
									<tr class="sub-row">
										<td class="indented">
											Invalid
										</td>
										<td>{{ schema.stats?.objects?.invalid || 0 }}</td>
										<td>-</td>
									</tr>
									<tr class="sub-row">
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
import { ref, computed, onMounted } from 'vue'
import { useDashboardStore } from '../../store/modules/dashboard.js'
import { useNavigationStore } from '../../store/modules/navigation.js'
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
	setup() {
		const store = useDashboardStore()
		const navigationStore = useNavigationStore()
		const expandedSchemas = ref(new Set())
		const calculating = ref(null)

		// Computed properties
		const loading = computed(() => store.loading)
		const error = computed(() => store.error)
		const registers = computed(() => store.registers)

		// Methods
		const toggleSchema = (schemaId) => {
			if (expandedSchemas.value.has(schemaId)) {
				expandedSchemas.value.delete(schemaId)
			} else {
				expandedSchemas.value.add(schemaId)
			}
		}

		const formatBytes = (bytes) => {
			if (!bytes || bytes === 0) return '0 KB'
			const k = 1024
			const sizes = ['B', 'KB', 'MB', 'GB']
			const i = Math.floor(Math.log(bytes) / Math.log(k))
			return `${parseFloat((bytes / Math.pow(k, i)).toFixed(2))} ${sizes[i]}`
		}

		const calculateSizes = async (register) => {
			calculating.value = register.id
			try {
				await axios.post(`/index.php/apps/openregister/api/dashboard/calculate/${register.id}`)
				showSuccess(t('openregister', 'Sizes calculated successfully'))
				await store.fetchRegisters() // Refresh data
			} catch (error) {
				showError(t('openregister', 'Failed to calculate sizes'))
				console.error('Failed to calculate sizes:', error)
			} finally {
				calculating.value = null
			}
		}

		const downloadOas = async (register) => {
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
		}

		const viewOasDoc = (register) => {
			const baseUrl = window.location.origin
			const apiUrl = `${baseUrl}/index.php/apps/openregister/api/registers/${register.id}/oas`
			window.open(`https://redocly.github.io/redoc/?url=${encodeURIComponent(apiUrl)}`, '_blank')
		}

		// Fetch data on component mount
		onMounted(() => {
			store.fetchRegisters()
		})

		return {
			loading,
			error,
			registers,
			expandedSchemas,
			toggleSchema,
			formatBytes,
			calculating,
			calculateSizes,
			downloadOas,
			viewOasDoc,
			navigationStore,
		}
	},
}
</script>

<style lang="scss" scoped>
.dashboard-content {
	margin-inline: auto;
	max-width: 1200px;
	padding: 20px;
}

.loading {
	display: flex;
	align-items: center;
	gap: 10px;
	color: var(--color-text-maxcontrast);
	justify-content: center;
	padding: 40px;
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

.register-card {
	background: var(--color-main-background);
	border-radius: 8px;
	padding: 20px;
	box-shadow: 0 2px 8px var(--color-box-shadow);
	min-height: 200px;
	transition: transform 0.2s ease-in-out;
	border: 1px solid var(--color-border);

	&:hover {
		transform: scale(1.01);
		box-shadow: 0 4px 12px var(--color-box-shadow);
	}
}

.register-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	margin-bottom: 12px;
	padding-bottom: 8px;
	border-bottom: 1px solid var(--color-border);

	h2 {
		display: flex;
		align-items: center;
		gap: 8px;
		margin: 0;
		font-size: 1.2em;
		color: var(--color-main-text);
	}
}

.register-description {
	color: var(--color-text-maxcontrast);
	margin-bottom: 16px;
	line-height: 1.5;
}

.schemas {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-top: 20px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}

.schema {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 8px 12px;
	background-color: var(--color-main-background);

	&:hover {
		background-color: var(--color-background-hover);
	}
}

.schema-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	cursor: pointer;
}

.schema-title {
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: 1em;
}

.schema-size {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.schema-toggle {
	background: none;
	border: none;
	padding: 4px;
	cursor: pointer;
	color: var(--color-text-maxcontrast);

	&:hover {
		color: var(--color-main-text);
	}
}

.statistics-table {
	width: 100%;
	border-collapse: collapse;
	font-size: 0.9em;

	&.register-stats {
		margin: 16px 0;
		background-color: var(--color-background-hover);
		border-radius: var(--border-radius);
		overflow: hidden;
	}

	&.schema-stats {
		margin: 12px 0;
	}
}

.statistics-table th,
.statistics-table td {
	padding: 8px;
	text-align: left;
	border: none;
}

.statistics-table th {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
	background-color: var(--color-background-darker);
}

.statistics-table tr:hover {
	background-color: var(--color-background-hover);
}

.sub-row td {
	color: var(--color-text-maxcontrast);
}

.indented {
	padding-left: 24px !important;
}
</style>
