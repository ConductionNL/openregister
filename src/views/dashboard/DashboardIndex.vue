<script setup>
import { dashboardStore, registerStore, searchTrailStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<div class="dashboardContent">
			<!-- Header -->
			<div class="viewHeader">
				<div class="headerWithActions">
					<div class="headerContent">
						<h1 class="viewHeaderTitleIndented">
							{{ pageTitle }}
						</h1>
						<p>
							{{ t('openregister', 'Overview of system analytics and search insights') }}
						</p>
					</div>
					<div class="headerActions">
						<NcButton type="secondary" @click="refreshDashboard">
							<template #icon>
								<NcLoadingIcon v-if="refreshing" :size="20" />
								<Refresh v-else :size="20" />
							</template>
							{{ t('openregister', 'Refresh') }}
						</NcButton>
					</div>
				</div>
			</div>

			<div v-if="dashboardStore.loading || searchTrailStore.statisticsLoading" class="error">
				<NcEmptyContent name="Loading" description="Loading dashboard data...">
					<template #icon>
						<NcLoadingIcon :size="64" />
					</template>
				</NcEmptyContent>
			</div>
			<div v-else-if="dashboardStore.error" class="error">
				<NcEmptyContent name="Error" :description="dashboardStore.error">
					<template #icon>
						<AlertCircle :size="64" />
					</template>
				</NcEmptyContent>
			</div>
			<div v-else class="chartsContainer">
				<!-- Search Traffic Chart -->
				<div class="chartCard">
					<h3>Search Traffic</h3>
					<div v-if="searchTrailStore.activity.daily && searchTrailStore.activity.daily.length > 0">
						<apexchart
							type="area"
							height="350"
							:options="searchTrafficChartOptions"
							:series="searchTrafficSeries" />
					</div>
					<div v-else class="noData">
						<p>No search activity data available</p>
						<small>Search trail functionality may not be enabled or configured</small>
					</div>
				</div>

				<!-- Popular Search Terms Table -->
				<div class="chartCard">
					<h3>Popular Search Terms</h3>
					<div class="searchTermsTable">
						<table v-if="searchTrailStore.popularTerms.length > 0" class="table">
							<thead>
								<tr>
									<th>Search Term</th>
									<th>Count</th>
									<th>Percentage</th>
									<th>Effectiveness</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="term in searchTrailStore.popularTerms" :key="term.term">
									<td class="searchTerm">
										{{ term.term }}
									</td>
									<td class="count">
										{{ term.count }}
									</td>
									<td class="percentage">
										{{ term.percentage }}%
									</td>
									<td class="effectiveness">
										<span
											:class="['effectiveness-badge', term.effectiveness]"
											:title="term.effectiveness === 'high' ? 'High effectiveness' : 'Low effectiveness'">
											{{ term.effectiveness }}
										</span>
									</td>
								</tr>
							</tbody>
						</table>
						<div v-else class="noData">
							<p>No search terms data available</p>
							<small>Search trail functionality may not be enabled or configured</small>
						</div>
					</div>
				</div>

				<!-- Search Statistics -->
				<div class="chartCard">
					<h3>Search Statistics</h3>
					<div class="statisticsGrid">
						<div class="statItem">
							<div class="statValue">
								{{ searchTrailStore.statistics.total.toLocaleString() }}
							</div>
							<div class="statLabel">
								Total Searches
							</div>
						</div>
						<div class="statItem">
							<div class="statValue">
								{{ searchTrailStore.statistics.totalResults.toLocaleString() }}
							</div>
							<div class="statLabel">
								Total Results
							</div>
						</div>
						<div class="statItem">
							<div class="statValue">
								{{ searchTrailStore.statistics.averageResultsPerSearch.toFixed(1) }}
							</div>
							<div class="statLabel">
								Avg Results/Search
							</div>
						</div>
						<div class="statItem">
							<div class="statValue">
								{{ searchTrailStore.statistics.averageExecutionTime.toFixed(0) }}ms
							</div>
							<div class="statLabel">
								Avg Execution Time
							</div>
						</div>
						<div class="statItem">
							<div class="statValue">
								{{ (searchTrailStore.statistics.successRate * 100).toFixed(1) }}%
							</div>
							<div class="statLabel">
								Success Rate
							</div>
						</div>
						<div class="statItem">
							<div class="statValue">
								{{ searchTrailStore.statistics.uniqueSearchTerms.toLocaleString() }}
							</div>
							<div class="statLabel">
								Unique Terms
							</div>
						</div>
					</div>
					<div v-if="searchTrailStore.statistics.total === 0" class="noData">
						<small>Search trail functionality may not be enabled or configured</small>
					</div>
				</div>

				<!-- Audit Trail Actions Chart -->
				<div class="chartCard">
					<h3>Audit Trail Actions</h3>
					<apexchart
						type="line"
						height="350"
						:options="auditTrailChartOptions"
						:series="dashboardStore.chartData.auditTrailActions?.series || []" />
				</div>

				<!-- Objects by Register Chart -->
				<div class="chartCard">
					<h3>Objects by Register</h3>
					<apexchart
						type="pie"
						height="350"
						:options="registerChartOptions"
						:series="dashboardStore.chartData.objectsByRegister?.series || []"
						:labels="dashboardStore.chartData.objectsByRegister?.labels || []" />
				</div>

				<!-- Objects by Schema Chart -->
				<div class="chartCard">
					<h3>Objects by Schema</h3>
					<apexchart
						type="pie"
						height="350"
						:options="schemaChartOptions"
						:series="dashboardStore.chartData.objectsBySchema?.series || []"
						:labels="dashboardStore.chartData.objectsBySchema?.labels || []" />
				</div>

				<!-- Objects by Size Chart -->
				<div class="chartCard">
					<h3>Objects by Size Distribution</h3>
					<apexchart
						type="bar"
						height="350"
						:options="sizeChartOptions"
						:series="[{ name: 'Objects', data: dashboardStore.chartData.objectsBySize?.series || [] }]" />
				</div>
			</div>
		</div>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcEmptyContent, NcLoadingIcon, NcButton } from '@nextcloud/vue'
import VueApexCharts from 'vue-apexcharts'
import { showError } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'

export default {
	name: 'DashboardIndex',
	components: {
		NcAppContent,
		NcEmptyContent,
		NcLoadingIcon,
		NcButton,
		apexchart: VueApexCharts,
		AlertCircle,
		Refresh,
	},
	data() {
		return {
			expandedSchemas: [],
			calculating: null,
			showSchemas: {},
			refreshing: false,
			searchTrafficChartOptions: {
				chart: {
					type: 'area',
					toolbar: {
						show: true,
					},
					zoom: {
						enabled: true,
					},
				},
				xaxis: {
					categories: [],
					title: {
						text: 'Date',
					},
				},
				yaxis: {
					title: {
						text: 'Number of Searches',
					},
				},
				colors: ['#1976D2'],
				stroke: {
					curve: 'smooth',
					width: 2,
				},
				fill: {
					type: 'gradient',
					gradient: {
						shade: 'light',
						type: 'vertical',
						opacityFrom: 0.7,
						opacityTo: 0.3,
					},
				},
				legend: {
					position: 'top',
				},
				theme: {
					mode: 'light',
				},
			},
			auditTrailChartOptions: {
				chart: {
					type: 'line',
					toolbar: {
						show: true,
					},
					zoom: {
						enabled: true,
					},
				},
				xaxis: {
					categories: [],
					title: {
						text: 'Date',
					},
				},
				yaxis: {
					title: {
						text: 'Number of Actions',
					},
				},
				colors: ['#41B883', '#E46651', '#00D8FF'],
				stroke: {
					curve: 'smooth',
					width: 2,
				},
				legend: {
					position: 'top',
				},
				theme: {
					mode: 'light',
				},
			},
			registerChartOptions: {
				chart: {
					type: 'pie',
				},
				labels: dashboardStore.chartData.objectsByRegister?.labels || [],
				legend: {
					position: 'bottom',
				},
				responsive: [{
					breakpoint: 480,
					options: {
						chart: {
							width: 200,
						},
						legend: {
							position: 'bottom',
						},
					},
				}],
			},
			schemaChartOptions: {
				chart: {
					type: 'pie',
				},
				labels: dashboardStore.chartData.objectsBySchema?.labels || [],
				legend: {
					position: 'bottom',
				},
				responsive: [{
					breakpoint: 480,
					options: {
						chart: {
							width: 200,
						},
						legend: {
							position: 'bottom',
						},
					},
				}],
			},
			sizeChartOptions: {
				chart: {
					type: 'bar',
				},
				plotOptions: {
					bar: {
						horizontal: false,
						columnWidth: '55%',
						endingShape: 'rounded',
					},
				},
				xaxis: {
					categories: [],
					title: {
						text: 'Size Range',
					},
				},
				yaxis: {
					title: {
						text: 'Number of Objects',
					},
				},
				fill: {
					opacity: 1,
				},
			},
		}
	},
	computed: {
		pageTitle() {
			return 'Dashboard'
		},
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
		searchTrafficSeries() {
			if (!searchTrailStore.activity.daily || searchTrailStore.activity.daily.length === 0) {
				return []
			}

			const data = searchTrailStore.activity.daily.map(item => ({
				x: item.period,
				y: item.searches,
			}))

			return [{
				name: 'Searches',
				data,
			}]
		},
	},
	watch: {
		'dashboardStore.chartData.auditTrailActions'(newVal) {
			if (newVal) {
				this.auditTrailChartOptions.xaxis.categories = newVal.labels || []
			}
		},
		'dashboardStore.chartData.objectsByRegister'(newVal) {
			if (newVal) {
				this.registerChartOptions.labels = newVal.labels || []
			}
		},
		'dashboardStore.chartData.objectsBySchema'(newVal) {
			if (newVal) {
				this.schemaChartOptions.labels = newVal.labels || []
			}
		},
		'dashboardStore.chartData.objectsBySize'(newVal) {
			if (newVal) {
				this.sizeChartOptions.xaxis.categories = newVal.labels || []
			}
		},
		'searchTrailStore.activity.daily'(newVal) {
			if (newVal && newVal.length > 0) {
				this.searchTrafficChartOptions.xaxis.categories = newVal.map(item => item.period)
			}
		},
	},
	async mounted() {
		// Load dashboard data
		dashboardStore.preload()
		dashboardStore.fetchAllChartData()

		// Load search trail data with error handling
		try {
			await this.loadSearchTrailData()
		} catch (error) {
			console.warn('Search trail data not available:', error)
			// Set empty data for graceful fallback
			this.setEmptySearchTrailData()
		}
	},
	methods: {
		setEmptySearchTrailData() {
			// Set empty data so UI doesn't break
			searchTrailStore.setStatistics({
				total_searches: 0,
				total_results: 0,
				avg_results_per_search: 0,
				avg_response_time: 0,
				success_rate: 0,
				unique_search_terms: 0,
				unique_users: 0,
				unique_organizations: 0,
				query_complexity: {
					simple: 0,
					medium: 0,
					complex: 0,
				},
			})
			searchTrailStore.setPopularTerms({ results: [] })
			searchTrailStore.setActivity({ daily: { activity: [] } })
		},
		async loadSearchTrailData() {
			try {
				// Fetch search trail statistics
				await searchTrailStore.fetchStatistics()

				// Fetch popular search terms
				await searchTrailStore.fetchPopularTerms()

				// Fetch search activity data for daily chart
				await searchTrailStore.fetchActivity('daily')
			} catch (error) {
				console.error('Error loading search trail data:', error)
				// Don't show error notification for this, just use fallback data
				this.setEmptySearchTrailData()
			}
		},
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
				showError('Failed to calculate sizes')
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
				showError('Failed to download API specification')
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

		async refreshDashboard() {
			this.refreshing = true
			try {
				// Refresh dashboard data
				await dashboardStore.preload()
				await dashboardStore.fetchAllChartData()

				// Refresh search trail data
				await this.loadSearchTrailData()
			} catch (error) {
				console.error('Error refreshing dashboard:', error)
				showError('Failed to refresh dashboard data')
			} finally {
				this.refreshing = false
			}
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

.chartsContainer {
	display: grid;
	grid-template-columns: repeat(2, 1fr);
	gap: 20px;
	padding: 20px;
}

.chartCard {
	background: var(--color-main-background);
	border-radius: 8px;
	padding: 20px;
	box-shadow: 0 2px 8px var(--color-box-shadow);
	border: 1px solid var(--color-border);

	h3 {
		margin: 0 0 20px 0;
		font-size: 1.2em;
		color: var(--color-main-text);
	}
}

.searchTermsTable {
	.table {
		width: 100%;
		border-collapse: collapse;
		margin-top: 10px;

		th, td {
			padding: 12px;
			text-align: left;
			border-bottom: 1px solid var(--color-border);
		}

		th {
			background-color: var(--color-background-dark);
			font-weight: 600;
			color: var(--color-main-text);
		}

		tbody tr:hover {
			background-color: var(--color-background-hover);
		}
	}

	.searchTerm {
		font-weight: 500;
		color: var(--color-main-text);
	}

	.count {
		font-weight: 600;
		color: var(--color-primary);
	}

	.percentage {
		color: var(--color-text-maxcontrast);
	}

	.effectiveness-badge {
		display: inline-block;
		padding: 4px 8px;
		border-radius: 4px;
		font-size: 0.8em;
		font-weight: 500;
		text-transform: uppercase;

		&.high {
			background-color: var(--color-success);
			color: white;
		}

		&.low {
			background-color: var(--color-error);
			color: white;
		}
	}

	.noData {
		text-align: center;
		padding: 40px;
		color: var(--color-text-maxcontrast);
	}
}

.statisticsGrid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
	gap: 20px;
	margin-top: 10px;
}

.statItem {
	text-align: center;
	padding: 20px;
	background-color: var(--color-background-dark);
	border-radius: 8px;
	border: 1px solid var(--color-border);

	.statValue {
		font-size: 2em;
		font-weight: 700;
		color: var(--color-primary);
		margin-bottom: 5px;
	}

	.statLabel {
		font-size: 0.9em;
		color: var(--color-text-maxcontrast);
		font-weight: 500;
	}
}

@media screen and (max-width: 1024px) {
	.chartsContainer {
		grid-template-columns: 1fr;
	}

	.statisticsGrid {
		grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
	}

	.headerWithActions {
		flex-direction: column;
		align-items: flex-start;
		gap: 16px;
	}

	.headerActions {
		align-self: stretch;
	}
}

/* Header with Actions Styles */
.headerWithActions {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 20px;
}

.headerContent {
	flex: 1;
}

.headerActions {
	display: flex;
	gap: 8px;
	align-items: center;
}
</style>
