<script setup>
import { registerStore, dashboardStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcAppSidebar
		v-if="register"
		ref="sidebar"
		v-model="activeTab"
		:name="register.title"
		:subtitle="register.description"
		subname="Register Details"
		:open="navigationStore.sidebarState.register"
		@update:open="(e) => {
			navigationStore.setSidebarState('register', e)
		}">
		<template #secondary-actions>
			<NcButton @click="navigationStore.setModal('editRegister')">
				<template #icon>
					<Pencil :size="20" />
				</template>
				{{ t('openregister', 'Edit Register') }}
			</NcButton>
			<NcButton @click="calculateSizes">
				<template #icon>
					<Calculator :size="20" />
				</template>
				{{ t('openregister', 'Calculate Sizes') }}
			</NcButton>
			<NcButton @click="downloadOas">
				<template #icon>
					<Download :size="20" />
				</template>
				{{ t('openregister', 'Download API Spec') }}
			</NcButton>
			<NcButton @click="viewOasDoc">
				<template #icon>
					<ApiIcon :size="20" />
				</template>
				{{ t('openregister', 'View API Docs') }}
			</NcButton>
		</template>

		<NcAppSidebarTab id="stats-tab" name="Statistics" :order="1">
			<template #icon>
				<ChartBar :size="20" />
			</template>

			<div class="section">
				<div class="sectionTitle">
					{{ t('openregister', 'Statistics') }}
				</div>
				<div class="statsContainer">
					<table class="statisticsTable">
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
								<td>-</td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>
		</NcAppSidebarTab>

		<NcAppSidebarTab id="schemas-tab" name="Schemas" :order="2">
			<template #icon>
				<FileCodeOutline :size="20" />
			</template>

			<div class="section">
				<div class="sectionTitle">
					{{ t('openregister', 'Schemas') }}
				</div>
				<div v-if="!register.schemas?.length" class="emptyContainer">
					<NcEmptyContent
						:title="t('openregister', 'No schemas found')"
						icon="icon-folder">
						<template #action>
							<NcButton @click="navigationStore.setModal('editRegister')">
								{{ t('openregister', 'Add Schema') }}
							</NcButton>
						</template>
					</NcEmptyContent>
				</div>
				<div v-else class="schemaList">
					<div v-for="schema in register.schemas" :key="schema.id" class="schemaItem">
						<div class="schemaHeader">
							<h3>
								<FileCodeOutline :size="20" />
								{{ schema.title }}
							</h3>
							<NcActions :primary="true" menu-name="Schema Actions">
								<template #icon>
									<DotsHorizontal :size="20" />
								</template>
								<NcActionButton close-after-click  @click="editSchema(schema)">
									<template #icon>
										<Pencil :size="20" />
									</template>
									Edit Schema
								</NcActionButton>
							</NcActions>
						</div>
						<div class="schemaStats">
							<div class="statItem">
								<span class="statLabel">{{ t('openregister', 'Total Objects') }}</span>
								<span class="statValue">{{ schema.stats?.objects?.total || 0 }}</span>
							</div>
							<div class="statItem">
								<span class="statLabel">{{ t('openregister', 'Total Size') }}</span>
								<span class="statValue">{{ formatBytes(schema.stats?.objects?.size || 0) }}</span>
							</div>
						</div>
					</div>
				</div>
			</div>
		</NcAppSidebarTab>
	</NcAppSidebar>
</template>

<script>
import { NcAppSidebar, NcAppSidebarTab, NcButton, NcEmptyContent, NcActions, NcActionButton } from '@nextcloud/vue'
import { showError } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import ChartBar from 'vue-material-design-icons/ChartBar.vue'
import FileCodeOutline from 'vue-material-design-icons/FileCodeOutline.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Calculator from 'vue-material-design-icons/Calculator.vue'
import Download from 'vue-material-design-icons/Download.vue'
import ApiIcon from 'vue-material-design-icons/Api.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import formatBytes from '../../services/formatBytes.js'

export default {
	name: 'RegisterSideBar',
	components: {
		NcAppSidebar,
		NcAppSidebarTab,
		NcButton,
		NcEmptyContent,
		NcActions,
		NcActionButton,
		ChartBar,
		FileCodeOutline,
		Pencil,
		Calculator,
		Download,
		ApiIcon,
		DotsHorizontal,
	},
	computed: {
		register() {
			// Find the register in the dashboard store using the ID from register store
			const registerId = registerStore.getRegisterItem?.id
			return dashboardStore.registers.find(r => r.id === registerId)
		},
		activeTab: {
			get() {
				return registerStore.getActiveTab
			},
			set(value) {
				registerStore.setActiveTab(value)
			},
		},
	},
	methods: {
		async calculateSizes() {
			if (!this.register) return

			try {
				await dashboardStore.calculateSizes(this.register.id)
				await dashboardStore.fetchRegisters()
			} catch (error) {
				console.error('Error calculating sizes:', error)
				showError(t('openregister', 'Failed to calculate sizes'))
			}
		},

		async downloadOas() {
			if (!this.register) return

			const baseUrl = window.location.origin
			const apiUrl = `${baseUrl}/index.php/apps/openregister/api/registers/${this.register.id}/oas`
			try {
				const response = await axios.get(apiUrl)
				const blob = new Blob([JSON.stringify(response.data, null, 2)], { type: 'application/json' })
				const downloadLink = document.createElement('a')
				downloadLink.href = URL.createObjectURL(blob)
				downloadLink.download = `${this.register.title.toLowerCase()}-api-specification.json`
				document.body.appendChild(downloadLink)
				downloadLink.click()
				document.body.removeChild(downloadLink)
				URL.revokeObjectURL(downloadLink.href)
			} catch (error) {
				showError(t('openregister', 'Failed to download API specification'))
				console.error('Error downloading OAS:', error)
			}
		},

		viewOasDoc() {
			if (!this.register) return

			const baseUrl = window.location.origin
			const apiUrl = `${baseUrl}/index.php/apps/openregister/api/registers/${this.register.id}/oas`
			window.open(`https://redocly.github.io/redoc/?url=${encodeURIComponent(apiUrl)}`, '_blank')
		},

		editSchema(schema) {
			registerStore.setSchemaItem(schema)
			navigationStore.setModal('editSchema')
		},
	},
}
</script>

<style lang="scss" scoped>
.section {
	padding: 12px 0;
	border-bottom: 1px solid var(--color-border);

	&:last-child {
		border-bottom: none;
	}
}

.sectionTitle {
	color: var(--color-text-maxcontrast);
	font-size: 14px;
	font-weight: bold;
	padding: 0 16px;
	margin: 0 0 12px 0;
}

.statsContainer {
	padding: 0 16px;
}

.statisticsTable {
	width: 100%;
	border-collapse: collapse;
	font-size: 0.9em;

	td {
		padding: 4px 8px;
		border-bottom: 1px solid var(--color-border);

		&:nth-child(2),
		&:nth-child(3) {
			text-align: right;
		}
	}

	.subRow td {
		color: var(--color-text-maxcontrast);
	}

	.indented {
		padding-left: 24px;
	}

	tr:last-child td {
		border-bottom: none;
	}
}

.schemaList {
	padding: 0 16px;
}

.schemaItem {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 8px;
	margin-bottom: 12px;
	padding: 12px;

	&:last-child {
		margin-bottom: 0;
	}
}

.schemaHeader {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 8px;

	h3 {
		display: flex;
		align-items: center;
		gap: 8px;
		margin: 0;
		font-size: 1em;
	}
}

.schemaStats {
	display: grid;
	grid-template-columns: repeat(2, 1fr);
	gap: 8px;
}

.statItem {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.statLabel {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.statValue {
	font-size: 1.1em;
	font-weight: 600;
}

.emptyContainer {
	padding: 0 16px;
}
</style>
