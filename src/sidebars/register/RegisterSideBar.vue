<script setup>
import { registerStore, dashboardStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcAppSidebar
		v-if="register"
		ref="sidebar"
		v-model="activeTab"
		:title="register.title"
		:subtitle="register.description"
		subname="Register Details">
		<template #secondary-actions>
			<NcButton @click="navigationStore.setModal('editRegister')">
				<template #icon>
					<Pencil :size="20" />
				</template>
				{{ t('openregister', 'Edit Register') }}
			</NcButton>
		</template>

		<NcAppSidebarTab id="details-tab" name="Details" :order="1">
			<template #icon>
				<Information :size="20" />
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
						</tbody>
					</table>
				</div>
			</div>

			<div class="section">
				<h3 class="section-title">
					{{ t('openregister', 'Actions') }}
				</h3>
				<div class="actionButtons">
					<NcButton
						:disabled="calculating"
						@click="calculateSizes">
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
				</div>
			</div>
		</NcAppSidebarTab>
	</NcAppSidebar>
</template>

<script>
import { NcAppSidebar, NcAppSidebarTab, NcButton } from '@nextcloud/vue'
import { showError } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import Information from 'vue-material-design-icons/Information.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Calculator from 'vue-material-design-icons/Calculator.vue'
import Download from 'vue-material-design-icons/Download.vue'
import ApiIcon from 'vue-material-design-icons/Api.vue'
import formatBytes from '../../services/formatBytes.js'
// Ensure data is loaded
dashboardStore.preload()

export default {
	name: 'RegisterSideBar',
	components: {
		NcAppSidebar,
		NcAppSidebarTab,
		NcButton,
		Information,
		Pencil,
		Calculator,
		Download,
		ApiIcon,
	},
	data() {
		return {
			activeTab: 'details-tab',
			calculating: false,
		}
	},
	computed: {
		register() {
			return registerStore.getRegisterItem
		},
	},
	methods: {

		async calculateSizes() {
			if (!this.register) return

			this.calculating = true
			try {
				await dashboardStore.calculateSizes(this.register.id)
				await dashboardStore.fetchRegisters()
			} catch (error) {
				console.error('Error calculating sizes:', error)
				showError(t('openregister', 'Failed to calculate sizes'))
			} finally {
				this.calculating = false
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

.actionButtons {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 0 16px;
}
</style>
