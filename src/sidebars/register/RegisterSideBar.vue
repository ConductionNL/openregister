<script setup>
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import {
	dashboardStore,
	navigationStore,
	registerStore,
	schemaStore,
} from '../../store/store.js'
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
		@update:open="
			(e) => {
				navigationStore.setSidebarState('register', e)
			}
		">
		<template #secondary-actions>
			<NcActionButton @click="showEditDialog = true">
				<template #icon>
					<Pencil :size="20" />
				</template>
				{{ t('openregister', 'Edit Register') }}
			</NcActionButton>
			<NcActionButton @click="calculateSizes">
				<template #icon>
					<Calculator :size="20" />
				</template>
				{{ t('openregister', 'Calculate Sizes') }}
			</NcActionButton>
			<NcActionButton @click="downloadOas">
				<template #icon>
					<Download :size="20" />
				</template>
				{{ t('openregister', 'Download API Spec') }}
			</NcActionButton>
			<NcActionButton @click="viewOasDoc">
				<template #icon>
					<ApiIcon :size="20" />
				</template>
				{{ t('openregister', 'View API Docs') }}
			</NcActionButton>
		</template>

		<NcAppSidebarTab id="stats-tab" name="Statistics" :order="1">
			<template #icon>
				<ChartBar :size="20" />
			</template>

			<div class="section">
				<div class="sectionTitle">
					{{ t('openregister', 'Statistics') }}
				</div>
				<div class="statsStack">
					<CnStatsBlock
						:title="t('openregister', 'Objects')"
						:count="register.stats?.objects?.total || 0"
						:countLabel="
							n(
								'openregister',
								'object',
								'objects',
								register.stats?.objects?.total || 0,
							)
						"
						:icon="PackageVariantClosed"
						variant="primary"
						horizontal
						showZeroCount
						:breakdown="objectsBreakdown" />
					<CnStatsBlock
						:title="t('openregister', 'Logs')"
						:count="register.stats?.logs?.total || 0"
						:countLabel="
							n(
								'openregister',
								'log',
								'logs',
								register.stats?.logs?.total || 0,
							)
						"
						:icon="TextBoxOutline"
						horizontal
						showZeroCount
						:breakdown="sizeBreakdown(register.stats?.logs?.size)" />
					<CnStatsBlock
						:title="t('openregister', 'Files')"
						:count="register.stats?.files?.total || 0"
						:countLabel="
							n(
								'openregister',
								'file',
								'files',
								register.stats?.files?.total || 0,
							)
						"
						:icon="FileDocumentOutline"
						horizontal
						showZeroCount
						:breakdown="sizeBreakdown(register.stats?.files?.size)" />
					<CnStatsBlock
						:title="t('openregister', 'Schemas')"
						:count="register.schemas?.length || 0"
						:countLabel="
							n(
								'openregister',
								'schema',
								'schemas',
								register.schemas?.length || 0,
							)
						"
						:icon="FileCodeOutline"
						horizontal
						showZeroCount />
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
							<NcButton @click="showEditDialog = true">
								{{ t('openregister', 'Add Schema') }}
							</NcButton>
						</template>
					</NcEmptyContent>
				</div>
				<div v-else class="schemaList">
					<CnItemCard
						v-for="schema in register.schemas"
						:key="schema.id"
						:title="schema.title"
						:icon="FileCodeOutline">
						<template #actions>
							<NcActions :primary="true" menuName="Schema Actions">
								<template #icon>
									<DotsHorizontal :size="20" />
								</template>
								<NcActionButton
									closeAfterClick
									@click="editSchema(schema)">
									<template #icon>
										<Pencil :size="20" />
									</template>
									Edit Schema
								</NcActionButton>
							</NcActions>
						</template>
						<CnKpiGrid :columns="2">
							<CnStatsBlock
								:title="t('openregister', 'Total Objects')"
								:count="schema.stats?.objects?.total || 0"
								showZeroCount />
							<CnStatsBlock
								:title="t('openregister', 'Total Size')"
								:count="0"
								showZeroCount
								:breakdown="
									sizeBreakdown(schema.stats?.objects?.size)
								" />
						</CnKpiGrid>
					</CnItemCard>
				</div>
			</div>
		</NcAppSidebarTab>
	</NcAppSidebar>

	<EditRegisterFormDialog
		v-if="showEditDialog"
		:item="register"
		withLanguages
		:afterSave="() => dashboardStore.fetchRegisters()"
		@close="showEditDialog = false" />
</template>

<script>
import {
	CnItemCard,
	CnKpiGrid,
	CnStatsBlock,
} from '@conduction/nextcloud-vue'
import { showError } from '@nextcloud/dialogs'
import {
	NcActionButton,
	NcActions,
	NcAppSidebar,
	NcAppSidebarTab,
	NcButton,
	NcEmptyContent,
} from '@nextcloud/vue'
import ApiIcon from 'vue-material-design-icons/Api.vue'
import Calculator from 'vue-material-design-icons/Calculator.vue'
import ChartBar from 'vue-material-design-icons/ChartBar.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Download from 'vue-material-design-icons/Download.vue'
import FileCodeOutline from 'vue-material-design-icons/FileCodeOutline.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import PackageVariantClosed from 'vue-material-design-icons/PackageVariantClosed.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TextBoxOutline from 'vue-material-design-icons/TextBoxOutline.vue'
import EditRegisterFormDialog from '../../modals/register/EditRegisterFormDialog.vue'
import formatBytes from '../../services/formatBytes.js'
import {
	downloadRegisterOas,
	viewRegisterOas,
} from '../../services/registerOas.js'

export default {
	name: 'RegisterSideBar',
	components: {
		EditRegisterFormDialog,
		NcAppSidebar,
		NcAppSidebarTab,
		NcButton,
		NcEmptyContent,
		NcActions,
		NcActionButton,
		CnStatsBlock,
		CnKpiGrid,
		CnItemCard,
		ChartBar,
		FileCodeOutline,
		Pencil,
		Calculator,
		Download,
		ApiIcon,
		DotsHorizontal,
	},

	data() {
		return {
			// Icon components for CnStatsBlock
			PackageVariantClosed,
			TextBoxOutline,
			FileDocumentOutline,
			showEditDialog: false,
		}
	},

	computed: {
		/**
		 * Resolve the active register (with stats) from the dashboard store.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-9
		 * @return {object|undefined} The active register record
		 */
		register() {
			// Find the register in the dashboard store using the ID from the register
			// store, falling back to the route param on a deep link / page refresh.
			// Mirrors RegisterDetail.register — the whole sidebar is v-if'd on this.
			const registerId =
				registerStore.getRegisterItem?.id || this.$route.params.id
			if (!registerId) {
				return undefined
			}
			// Route params are strings, the API returns numeric ids — compare as strings.
			return dashboardStore.registers.find(
				(r) => String(r.id) === String(registerId),
			)
		},

		activeTab: {
			/**
			 * @spec exclude Tab-switch state accessor; proxies the active tab to/from the register store, no domain behavior.
			 */
			get() {
				return registerStore.getActiveTab
			},

			/**
			 * @param {string} value
			 * @spec exclude Tab-switch state mutator; proxies the active tab to the register store, no domain behavior.
			 */
			set(value) {
				registerStore.setActiveTab(value)
			},
		},

		/**
		 * @spec exclude Presentation-only formatter; builds the object stats breakdown (size/invalid/deleted/locked) for display.
		 * @return {object} Breakdown descriptor for the objects stats block
		 */
		objectsBreakdown() {
			const stats = this.register?.stats?.objects
			const breakdown = {
				size: formatBytes(stats.size),
				invalid: stats.invalid || 0,
				deleted: stats.deleted || 0,
				locked: stats.locked || 0,
			}
			return breakdown
		},
	},

	methods: {
		/**
		 * @spec exclude Presentation-only formatter; renders a byte size as a stats breakdown descriptor.
		 * @param {number} size - Byte size
		 * @return {object|null} Breakdown descriptor or null
		 */
		sizeBreakdown(size) {
			if (!size) return null
			return { size: formatBytes(size) }
		},

		/**
		 * Trigger a server-side size recalculation for the register and refresh stats.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-11
		 * @return {Promise<void>}
		 */
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

		/**
		 * Fetch the register's generated OpenAPI spec and download it as a JSON file.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-10
		 * @return {Promise<void>}
		 */
		async downloadOas() {
			if (!this.register) return

			try {
				await downloadRegisterOas(this.register)
			} catch (error) {
				showError(t('openregister', 'Failed to download API specification'))
				console.error('Error downloading OAS:', error)
			}
		},

		/**
		 * Open the register's generated OpenAPI spec in an interactive ReDoc viewer.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-10
		 * @return {void}
		 */
		viewOasDoc() {
			if (!this.register) return

			viewRegisterOas(this.register.id)
		},

		/**
		 * Stage a schema for editing and open the schema-edit modal.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-9
		 * @param {object} schema - The schema to edit
		 * @return {void}
		 */
		editSchema(schema) {
			registerStore.setSchemaItem(schema)
			navigationStore.setModal('editSchema')
		},
	},
}
</script>

<style lang="scss" scoped>
@use '../../styles/sidebars.scss' as *;

.section {
	padding: 12px 0;
	border-bottom: 1px solid var(--color-border);

	&:last-child {
		border-bottom: none;
	}
}

@include section-title;

@include stats-stack;

.schemaList {
	padding: 0 16px;
}

.emptyContainer {
	padding: 0 16px;
}
</style>
