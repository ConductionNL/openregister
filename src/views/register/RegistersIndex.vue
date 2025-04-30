<script setup>
import { dashboardStore, registerStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<span class="pageHeaderContainer">
			<h2 class="pageHeader">
				Registers
			</h2>

			<NcActions
				:force-name="true"
				:inline="1"
				:primary="true"
				menu-name="Dashboard actions">
				<NcActionButton @click="registerStore.setRegisterItem(null); navigationStore.setModal('editRegister')">
					<template #icon>
						<Plus :size="20" />
					</template>
					Add Register
				</NcActionButton>
				<NcActionButton @click="dashboardStore.fetchRegisters()">
					<template #icon>
						<Refresh :size="20" />
					</template>
					Refresh
				</NcActionButton>
				<NcActionButton @click="registerStore.setRegisterItem(null); navigationStore.setModal('importRegister')">
					<template #icon>
						<Upload :size="20" />
					</template>
					Import
				</NcActionButton>
				<NcActionButton @click="openAllApisDoc">
					<template #icon>
						<ApiIcon :size="20" />
					</template>
					View APIs
				</NcActionButton>
			</NcActions>
		</span>

		<div class="dashboardContent">
			<div v-if="dashboardStore.loading" class="loading">
				<NcLoadingIcon :size="32" />
				<span>Loading registers...</span>
			</div>
			<div v-else-if="dashboardStore.error" class="error">
				<NcEmptyContent :title="dashboardStore.error" icon="icon-error" />
			</div>
			<div v-else-if="!filteredRegisters.length" class="empty">
				<NcEmptyContent title="No registers found" icon="icon-folder" />
			</div>
			<div v-else class="registers">
				<div v-for="register in filteredRegisters" :key="register.id" class="registerCard">
					<div class="registerHeader">
						<h2 v-tooltip.bottom="register.description">
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
							<NcActionButton @click="registerStore.setRegisterItem({
								...register,
								schemas: register.schemas.map(schema => schema.id)
							}); navigationStore.setModal('editRegister')">
								<template #icon>
									<Pencil :size="20" />
								</template>
								Edit
							</NcActionButton>
							<NcActionButton @click="registerStore.setRegisterItem(register); navigationStore.setModal('exportRegister')">
								<template #icon>
									<Export :size="20" />
								</template>
								Export
							</NcActionButton>
							<NcActionButton @click="registerStore.setRegisterItem(register); navigationStore.setModal('uploadRegister')">
								<template #icon>
									<Upload :size="20" />
								</template>
								Upload
							</NcActionButton>
							<NcActionButton @click="registerStore.setRegisterItem(register); viewOasDoc(register)">
								<template #icon>
									<ApiIcon :size="20" />
								</template>
								View API Documentation
							</NcActionButton>
							<NcActionButton @click="registerStore.setRegisterItem(register); downloadOas(register)">
								<template #icon>
									<Download :size="20" />
								</template>
								Download API Specification
							</NcActionButton>
							<NcActionButton @click="registerStore.setRegisterItem(register); navigationStore.setDialog('deleteRegister')">
								<template #icon>
									<TrashCanOutline :size="20" />
								</template>
								Delete
							</NcActionButton>
						</NcActions>
					</div>

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
							<tr>
								<td>{{ t('openregister', 'Schemas') }}</td>
								<td>{{ register.schemas?.length || 0 }}</td>
								<td>
									<button class="schemaToggle" @click.stop="toggleSchemaVisibility(register.id)">
										{{ isSchemasVisible(register.id) ? t('openregister', 'Hide') : t('openregister', 'Show') }}
									</button>
								</td>
							</tr>
						</tbody>
					</table>

					<!-- Schemas section with v-show -->
					<div v-show="isSchemasVisible(register.id)" class="schemas">
						<div v-for="schema in register.schemas" :key="schema.id" class="schema">
							<div
								class="schemaHeader"
								@click="toggleSchema(schema.id)">
								<div class="schemaTitle">
									<FileCodeOutline :size="16" />
									<span>{{ schema.stats?.objects?.total || 0 }} </span>
									{{ schema.title }}
									<span class="schemaSize">({{ formatBytes(schema.stats?.objects?.size || 0) }})</span>
								</div>
								<button class="schemaToggle">
									<ChevronUp v-if="isSchemaExpanded(schema.id)" :size="20" />
									<ChevronDown v-else :size="20" />
								</button>
							</div>

							<div v-show="isSchemaExpanded(schema.id)" class="schemaContent">
								<table class="statisticsTable schemaStats">
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
											<td>{{ schema.stats?.objects?.total || 0 }}</td>
											<td>{{ formatBytes(schema.stats?.objects?.size || 0) }}</td>
										</tr>
										<tr class="subRow">
											<td class="indented">
												{{ t('openregister', 'Invalid') }}
											</td>
											<td>{{ schema.stats?.objects?.invalid || 0 }}</td>
											<td>-</td>
										</tr>
										<tr class="subRow">
											<td class="indented">
												{{ t('openregister', 'Deleted') }}
											</td>
											<td>{{ schema.stats?.objects?.deleted || 0 }}</td>
											<td>-</td>
										</tr>
										<tr class="subRow">
											<td class="indented">
												{{ t('openregister', 'Locked') }}
											</td>
											<td>{{ schema.stats?.objects?.locked || 0 }}</td>
											<td>-</td>
										</tr>
										<tr class="subRow">
											<td class="indented">
												{{ t('openregister', 'Published') }}
											</td>
											<td>{{ schema.stats?.objects?.published || 0 }}</td>
											<td>-</td>
										</tr>
										<tr>
											<td>{{ t('openregister', 'Logs') }}</td>
											<td>{{ schema.stats?.logs?.total || 0 }}</td>
											<td>{{ formatBytes(schema.stats?.logs?.size || 0) }}</td>
										</tr>
										<tr>
											<td>{{ t('openregister', 'Files') }}</td>
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
		</div>
	</NcAppContent>
</template>

<script>
import { tooltip, NcAppContent, NcEmptyContent, NcLoadingIcon, NcActions, NcActionButton } from '@nextcloud/vue'
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
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'

export default {
	name: 'RegistersIndex',
	directives: {
		tooltip,
	},
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
		Refresh,
		Plus,
	},
	data() {
		return {
			expandedSchemas: [],
			calculating: null,
			showSchemas: {},
		}
	},
	computed: {
		filteredRegisters() {
			return dashboardStore.registers.filter(register =>
				register.title !== 'System Totals'
				&& register.title !== 'Orphaned Items',
			)
		},
		isSchemaExpanded() {
			return (schemaId) => this.expandedSchemas.includes(schemaId)
		},
		isSchemasVisible() {
			return (registerId) => this.showSchemas[registerId] || false
		},
	},
	mounted() {
		dashboardStore.preload()
	},
	methods: {
		toggleSchema(schemaId) {
			const index = this.expandedSchemas.indexOf(schemaId)
			if (index > -1) {
				this.expandedSchemas.splice(index, 1)
			} else {
				this.expandedSchemas.push(schemaId)
			}

			// Force reactivity update
			this.expandedSchemas = [...this.expandedSchemas]
		},

		formatBytes(bytes) {
			if (!bytes || bytes === 0) return '0 KB'
			const k = 1024
			const sizes = ['B', 'KB', 'MB', 'GB']
			const i = Math.floor(Math.log(bytes) / Math.log(k))
			return `${parseFloat((bytes / Math.pow(k, i)).toFixed(2))} ${sizes[i]}`
		},

		async calculateSizes(register) {
			// Set the active register in the store
			registerStore.setRegisterItem(register)

			// Set the calculating state for this register
			this.calculating = register.id
			try {
				// Call the dashboard store to calculate sizes
				await dashboardStore.calculateSizes(register.id)
				// Refresh the registers list to get updated sizes
				await dashboardStore.fetchRegisters()
			} catch (error) {
				console.error('Error calculating sizes:', error)
				showError(t('openregister', 'Failed to calculate sizes'))
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

		toggleSchemaVisibility(registerId) {
			this.$set(this.showSchemas, registerId, !this.showSchemas[registerId])
		},

		openAllApisDoc() {
			const baseUrl = window.location.origin
			const apiUrl = `${baseUrl}/apps/openregister/api/registers/oas`
			window.open(`https://redocly.github.io/redoc/?url=${encodeURIComponent(apiUrl)}`, '_blank')
		},
	},
}
</script>

<style lang="scss" scoped>
.pageHeaderContainer {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 0;
}

.pageHeader {
	font-family: system-ui, -apple-system, "Segoe UI", Roboto, Oxygen-Sans, Cantarell, Ubuntu, "Helvetica Neue", "Noto Sans", "Liberation Sans", Arial, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol", "Noto Color Emoji";
	font-size: 30px;
	font-weight: 600;
	margin-left: 50px;
}

/* Add styles for the action buttons container */
:deep(.button-vue) {
	margin-top: 15px;
	margin-right: 15px;
	padding-right: 15px;
}

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
	margin-block-end: 8px;
	background-color: var(--color-main-background);

	&:last-child {
		margin-block-end: 0;
	}
}

.schemaHeader {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding-block: 8px;
	padding-inline: 12px;
	cursor: pointer;
	transition: background-color 0.2s ease;

	&:hover {
		background-color: var(--color-background-hover);
	}
}

.schemaTitle {
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: 0.9em;
}

.schemaSize {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin-inline-start: 4px;
}

.schemaToggle {
	background: none;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 4px 8px;
	cursor: pointer;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	transition: all 0.2s ease;

	&:hover {
		color: var(--color-main-text);
		background-color: var(--color-background-hover);
	}
}

.schemaContent {
	border-block-start: 1px solid var(--color-border);
	background-color: var(--color-background-hover);
	padding: 12px;
}

.statisticsTable {
	width: 100%;
	border-collapse: collapse;
	font-size: 0.9em;
	background: var(--color-main-background);
	border-radius: var(--border-radius);
	overflow: hidden;

	th, td {
		padding: 8px 12px;
		text-align: start;
		border-block-end: 1px solid var(--color-border);
	}

	th {
		background-color: var(--color-background-darker);
		color: var(--color-text-maxcontrast);
		font-weight: normal;
	}

	tr:last-child td {
		border-block-end: none;
	}

	.subRow td {
		color: var(--color-text-maxcontrast);
	}

	.indented {
		padding-inline-start: 24px;
	}
}
</style>
