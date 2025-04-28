<template>
	<NcAppContent>
		<h2 class="pageHeader">
			Dashboard
		</h2>
		<div class="dashboard-content">
			<div class="stats">
				<div
					v-for="(stat, key) in statsConfig"
					:key="key"
					class="clickable"
					@click="navigateTo(key)">
					<h5>{{ stat.label }}</h5>
					<div class="content">
						<NcLoadingIcon v-if="isLoading" :size="44" />
						<template v-else>
							<template v-if="key === 'objects' && stats[key] >= 1000">
								<div class="stat-value">
									{{ abbreviatedValue(stats[key]) }}
									<span class="tooltip">
										{{ stats[key] }}
									</span>
								</div>
							</template>
							<template v-else>
								{{ stats[key] || 0 }}
							</template>
						</template>
					</div>
				</div>
			</div>
		</div>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcLoadingIcon } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { navigationStore } from '../../store/store.js'

export default {
	name: 'DashboardIndex',
	components: {
		NcAppContent,
		NcLoadingIcon,
	},
	data() {
		return {
			isLoading: true,
			stats: {
				registers: 0,
				schemas: 0,
				objects: 10234,
			},
			statsConfig: {
				registers: { label: 'Registers' },
				schemas: { label: 'Schemas' },
				objects: { label: 'Objects' },
			},
		}
	},
	mounted() {
		this.fetchRegisters()
		this.fetchSchemas()
		this.fetchObjects()
	},
	methods: {
		async fetchRegisters() {
			try {
				const response = await axios.get(generateUrl('/apps/openregister/api/registers'))
				this.stats.registers = response.data.results.length
			} catch (error) {
				console.error('Error fetching stats:', error)
			}
		},
		async fetchSchemas() {
			try {
				const response = await axios.get(generateUrl('/apps/openregister/api/schemas'))
				this.stats.schemas = response.data.results.length
			} catch (error) {
				console.error('Error fetching stats:', error)
			}
		},
		async fetchObjects() {
			try {
				const response = await axios.get(generateUrl('/apps/openregister/api/objects'))
				this.stats.objects = response.data.results.length
			} catch (error) {
				console.error('Error fetching stats:', error)
			} finally {
				this.isLoading = false
			}
		},
		navigateTo(section) {

			if (section === 'objects') {
				navigationStore.setSelected('tableSearch')
			} else {
				navigationStore.setSelected(section)
			}

		},
		abbreviatedValue(value) {
			if (value >= 1000) {
				let abbreviated = (value / 1000).toFixed(1)
				abbreviated = abbreviated.endsWith('.0') ? abbreviated.slice(0, -2) : abbreviated
				return `${abbreviated}k`
			}
			return value
		},
	},
}
</script>

<style>
.dashboard-content {
	margin-inline: auto;
	max-width: 1000px;
	display: flex;
	flex-direction: column;
	justify-content: center;
	align-items: center;
}

.dashboard-content > * {
	margin-block-end: 4rem;
}

.dashboard-content > .stats {
	display: grid;
	grid-template-columns: 1fr;
	gap: 1rem;
}

@media screen and (min-width: 880px) {
	.dashboard-content > .stats {
		grid-template-columns: 1fr 1fr;
	}
}

@media screen and (min-width: 1024px) {
	.dashboard-content > .stats {
		grid-template-columns: repeat(2, 1fr);
	}
}

@media screen and (min-width: 1220px) {
	.dashboard-content > .stats {
		grid-template-columns: repeat(3, 1fr);
	}
}

@media screen and (min-width: 1590px) {
	.dashboard-content > .stats {
		grid-template-columns: repeat(3, 1fr);
	}
}

.dashboard-content > .stats > div {
	padding: 1rem;
	height: 150px;
	width: 250px;
	border-radius: 8px;
	cursor: pointer;
	transition: transform 0.2s ease-in-out;
	position: relative;
}

.dashboard-content > .stats > div:hover {
	transform: scale(1.02);
}

.dashboard-content > .stats > div > h5 {
	margin: 0;
	font-weight: normal;
}

.dashboard-content > .stats > div > .content {
	display: flex;
	justify-content: center;
	align-items: center;
	height: calc(100% - 40px);
	font-size: 3.5rem;
}

/* Tooltip styles */
.stat-value {
	position: relative;
	display: inline-block;
}

.stat-value .tooltip {
	visibility: hidden;
	position: absolute;
	bottom: -40px;
	left: 50%;
	transform: translateX(-50%);
	background-color: #333;
	color: #fff;
	padding: 5px 8px;
	border-radius: 4px;
	font-size: 1rem;
	white-space: nowrap;
	z-index: 100;
	opacity: 0;
	transition: opacity 0.3s, visibility 0.3s;
}

.stat-value:hover .tooltip {
	visibility: visible;
	opacity: 1;
}

/* Theme styles */
@media (prefers-color-scheme: light) {
	.dashboard-content > .stats > div {
		background-color: rgba(0, 0, 0, 0.07);
	}
}

@media (prefers-color-scheme: dark) {
	.dashboard-content > .stats > div {
		background-color: rgba(255, 255, 255, 0.1);
	}

	.stat-value .tooltip {
		background-color: #222;
	}
}

body[data-theme-light] .dashboard-content > .stats > div {
	background-color: rgba(0, 0, 0, 0.07);
}

body[data-theme-dark] .dashboard-content > .stats > div {
	background-color: rgba(255, 255, 255, 0.1);
}

body[data-theme-dark] .stat-value .tooltip {
	background-color: #222;
}

.clickable {
	cursor: pointer;
}
</style>
