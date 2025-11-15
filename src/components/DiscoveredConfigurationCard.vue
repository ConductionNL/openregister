<template>
	<div class="discoveredCard">
		<div class="cardHeader">
			<CogOutline :size="24" />
			<h3>{{ configuration.config.title }}</h3>
			<span v-if="configuration.config.app" class="appBadge">
				{{ configuration.config.app }}
			</span>
		</div>

	<p v-if="loading" class="cardDescription loading">
		<NcLoadingIcon :size="16" />
		Obtaining additional information...
	</p>
	<p v-else class="cardDescription">
		{{ configuration.config.description || 'No description available' }}
	</p>

	<div class="cardMeta">
		<a
			v-if="configuration.repository"
			:href="`https://github.com/${configuration.repository}`"
			target="_blank"
			rel="noopener noreferrer"
			class="metaItem metaLink">
			<SourceBranch :size="16" />
			{{ formatSource() }}
		</a>
		<a
			v-if="configuration.organization && configuration.organization.url"
			:href="configuration.organization.url"
			target="_blank"
			rel="noopener noreferrer"
			class="metaItem metaLink">
			<OfficeBuilding :size="16" />
			{{ configuration.organization.name }}
		</a>
		<span v-if="configuration.stars" class="metaItem">
			<Star :size="16" />
			{{ configuration.stars }}
		</span>
	</div>

		<div class="cardActions">
		<NcButton
			type="primary"
			@click="$emit('import')">
			<template #icon>
				<CloudUpload :size="20" />
			</template>
			Import
		</NcButton>
			<NcButton
				v-if="configuration.url"
				@click="openInNewTab(configuration.url)">
				<template #icon>
					<OpenInNew :size="20" />
				</template>
				View Source
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'
import SourceBranch from 'vue-material-design-icons/SourceBranch.vue'
import Star from 'vue-material-design-icons/Star.vue'
import CloudUpload from 'vue-material-design-icons/CloudUpload.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import OfficeBuilding from 'vue-material-design-icons/OfficeBuilding.vue'

export default {
	name: 'DiscoveredConfigurationCard',
	components: {
		NcButton,
		NcLoadingIcon,
		CogOutline,
		SourceBranch,
		Star,
		CloudUpload,
		OpenInNew,
		OfficeBuilding,
	},
	props: {
		configuration: {
			type: Object,
			required: true,
		},
	},
	emits: ['import'],
	data() {
		return {
			loading: false,
			enriched: false,
		}
	},
	mounted() {
		// Automatically enrich configuration details when card is mounted
		this.enrichDetails()
	},
	methods: {
		async enrichDetails() {
			// Skip if already enriched or if we already have a description
			if (this.enriched || (this.configuration.config.description && this.configuration.config.version !== 'v.unknown')) {
				return
			}

			// Skip if we don't have the necessary info to enrich
			if (!this.configuration.owner || !this.configuration.repo || !this.configuration.path) {
				return
			}

			this.loading = true
			try {
				const params = new URLSearchParams({
					source: 'github', // TODO: detect from configuration
					owner: this.configuration.owner,
					repo: this.configuration.repo,
					path: this.configuration.path,
					branch: this.configuration.branch || 'main',
				})

				const response = await fetch(`/index.php/apps/openregister/api/configurations/enrich?${params}`)
				if (response.ok) {
					const details = await response.json()
					
					// Update configuration with enriched details
					this.configuration.config = {
						...this.configuration.config,
						...details,
					}
					
					this.enriched = true
				}
			} catch (error) {
				console.error('Failed to enrich configuration details:', error)
			} finally {
				this.loading = false
			}
		},
		formatSource() {
			if (this.configuration.repository) {
				return this.configuration.repository
			} else if (this.configuration.project_id) {
				return `GitLab: ${this.configuration.project_id}`
			}
			return 'Unknown source'
		},
		openInNewTab(url) {
			window.open(url, '_blank')
		},
	},
}
</script>

<style scoped>
.discoveredCard {
	border: 2px solid var(--color-border);
	border-radius: 8px;
	padding: 16px;
	display: flex;
	flex-direction: column;
	gap: 12px;
	transition: all 0.2s;
	background-color: var(--color-main-background);
}

.discoveredCard:hover {
	border-color: var(--color-primary);
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.cardHeader {
	display: flex;
	align-items: center;
	gap: 12px;
}

.cardHeader h3 {
	margin: 0;
	font-size: 1.1em;
	flex: 1;
}

.appBadge {
	padding: 4px 8px;
	background-color: var(--color-primary);
	color: white;
	border-radius: 10px;
	font-size: 0.75em;
	font-weight: 600;
	text-transform: uppercase;
}

.cardDescription {
	color: var(--color-text-lighter);
	font-size: 0.9em;
	margin: 0;
	line-height: 1.5;
}

.cardDescription.loading {
	display: flex;
	align-items: center;
	gap: 8px;
	font-style: italic;
	color: var(--color-text-maxcontrast);
}

.cardMeta {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	padding-top: 8px;
	border-top: 1px solid var(--color-border);
}

.metaItem {
	display: flex;
	align-items: center;
	gap: 4px;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.metaLink {
	color: var(--color-primary);
	text-decoration: none;
	transition: all 0.2s;
}

.metaLink:hover {
	color: var(--color-primary-element);
	text-decoration: underline;
}

.cardActions {
	display: flex;
	gap: 8px;
	margin-top: 4px;
}
</style>

