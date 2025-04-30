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
					</div>
					<p class="register-description">
						{{ register.description }}
					</p>

					<!-- Register Statistics Table -->
					<table class="statistics-table register-stats">
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
								<td>{{ register.stats.objects.total }}</td>
								<td>{{ formatBytes(register.stats.objects.size) }}</td>
							</tr>
							<tr class="sub-row">
								<td class="indented">
									Invalid
								</td>
								<td>{{ register.stats.objects.invalid }}</td>
								<td>-</td>
							</tr>
							<tr class="sub-row">
								<td class="indented">
									Deleted
								</td>
								<td>{{ register.stats.objects.deleted }}</td>
								<td>-</td>
							</tr>
							<tr>
								<td>Logs</td>
								<td>{{ register.stats.logs.total }}</td>
								<td>{{ formatBytes(register.stats.logs.size) }}</td>
							</tr>
							<tr>
								<td>Files</td>
								<td>{{ register.stats.files.total }}</td>
								<td>{{ formatBytes(register.stats.files.size) }}</td>
							</tr>
						</tbody>
					</table>

					<div class="schemas">
						<div v-for="schema in register.schemas" :key="schema.id" class="schema">
							<div class="schema-header" @click="toggleSchema(schema.id)">
								<div class="schema-title">
									<FileCodeOutline :size="16" />
									<span>{{ schema.stats.objects.total }} </span>
									{{ schema.title }}
									<span class="schema-size">({{ formatBytes(schema.stats.objects.size) }})</span>
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
										<td>{{ schema.stats.objects.total }}</td>
										<td>{{ formatBytes(schema.stats.objects.size) }}</td>
									</tr>
									<tr class="sub-row">
										<td class="indented">
											Invalid
										</td>
										<td>{{ schema.stats.objects.invalid }}</td>
										<td>-</td>
									</tr>
									<tr class="sub-row">
										<td class="indented">
											Deleted
										</td>
										<td>{{ schema.stats.objects.deleted }}</td>
										<td>-</td>
									</tr>
									<tr>
										<td>Logs</td>
										<td>{{ schema.stats.logs.total }}</td>
										<td>{{ formatBytes(schema.stats.logs.size) }}</td>
									</tr>
									<tr>
										<td>Files</td>
										<td>{{ schema.stats.files.total }}</td>
										<td>{{ formatBytes(schema.stats.files.size) }}</td>
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
import { NcAppContent, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import DatabaseOutline from 'vue-material-design-icons/DatabaseOutline.vue'
import FileCodeOutline from 'vue-material-design-icons/FileCodeOutline.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronUp from 'vue-material-design-icons/ChevronUp.vue'
import { ref, computed, onMounted } from 'vue'
import { useDashboardStore } from '../../store/modules/dashboard.js'

export default {
	name: 'DashboardIndex',
	components: {
		NcAppContent,
		NcEmptyContent,
		NcLoadingIcon,
		DatabaseOutline,
		FileCodeOutline,
		ChevronDown,
		ChevronUp,
	},
	setup() {
		const store = useDashboardStore()
		const expandedSchemas = ref(new Set())

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
