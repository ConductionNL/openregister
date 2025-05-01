<script setup>
import { registerStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<div class="registerDetailContent">
			<!-- Main content area for schema charts -->
			<div class="schemaCharts">
				<div v-if="!register" class="loadingContainer">
					<NcLoadingIcon :size="32" />
					<span>Loading register data...</span>
				</div>
				<div v-else-if="!register.schemas?.length" class="emptyContainer">
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
				<div v-else class="schemaGrid">
					<div v-for="schema in register.schemas" :key="schema.id" class="schemaCard">
						<div class="schemaHeader">
							<h3>
								<FileCodeOutline :size="20" />
								{{ schema.title }}
							</h3>
							<NcActions :primary="true" menu-name="Schema Actions">
								<template #icon>
									<DotsHorizontal :size="20" />
								</template>
								<NcActionButton @click="editSchema(schema)">
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
						<div class="schemaChart">
							<apexchart
								type="pie"
								height="200"
								:options="getSchemaChartOptions(schema)"
								:series="[
									schema.stats?.objects?.valid || 0,
									schema.stats?.objects?.invalid || 0,
									schema.stats?.objects?.deleted || 0,
									schema.stats?.objects?.locked || 0,
									schema.stats?.objects?.published || 0
								]" />
						</div>
					</div>
				</div>
			</div>
		</div>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcEmptyContent, NcLoadingIcon, NcActions, NcActionButton, NcButton } from '@nextcloud/vue'
import VueApexCharts from 'vue-apexcharts'
import FileCodeOutline from 'vue-material-design-icons/FileCodeOutline.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import formatBytes from '../../services/formatBytes.js'

export default {
	name: 'RegisterDetail',
	components: {
		NcAppContent,
		NcEmptyContent,
		NcLoadingIcon,
		NcActions,
		NcActionButton,
		NcButton,
		apexchart: VueApexCharts,
		FileCodeOutline,
		DotsHorizontal,
		Pencil,
	},
	computed: {
		register() {
			return registerStore.getRegisterItem
		},
	},
	methods: {
		getSchemaChartOptions(schema) {
			return {
				chart: {
					type: 'pie',
				},
				labels: ['Valid', 'Invalid', 'Deleted', 'Locked', 'Published'],
				legend: {
					position: 'bottom',
					fontSize: '14px',
				},
				colors: ['#41B883', '#E46651', '#00D8FF', '#DD6B20', '#38A169'],
				tooltip: {
					y: {
						formatter(val) {
							return val + ' objects'
						},
					},
				},
			}
		},

		editSchema(schema) {
			registerStore.setSchemaItem(schema)
			navigationStore.setModal('editSchema')
		},
	},
}
</script>

<style lang="scss" scoped>
.registerDetailContent {
	margin-inline: auto;
	max-width: 1200px;
	padding: 20px;
}

.loadingContainer {
	display: flex;
	align-items: center;
	gap: 10px;
	color: var(--color-text-maxcontrast);
	justify-content: center;
	padding-block: 40px;
}

.schemaGrid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
	gap: 20px;
}

.schemaCard {
	background: var(--color-main-background);
	border-radius: 8px;
	padding: 20px;
	box-shadow: 0 2px 8px var(--color-box-shadow);
	border: 1px solid var(--color-border);
}

.schemaHeader {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 16px;

	h3 {
		display: flex;
		align-items: center;
		gap: 8px;
		margin: 0;
		font-size: 1.1em;
	}
}

.schemaStats {
	display: grid;
	grid-template-columns: repeat(2, 1fr);
	gap: 12px;
	margin-bottom: 16px;
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
</style>
