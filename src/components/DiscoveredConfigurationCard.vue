<template>
	<div class="discoveredCard">
		<div class="cardHeader">
			<CogOutline :size="24" />
			<h3>{{ configuration.config.title }}</h3>
			<span v-if="configuration.config.app" class="appBadge">
				{{ configuration.config.app }}
			</span>
		</div>

		<p class="cardDescription">
			{{ configuration.config.description || 'No description available' }}
		</p>

		<div class="cardMeta">
			<span class="metaItem">
				<SourceBranch :size="16" />
				{{ formatSource() }}
			</span>
			<span class="metaItem">
				<TagOutline :size="16" />
				v{{ configuration.config.version || '1.0.0' }}
			</span>
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
					<Download :size="20" />
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
import { NcButton } from '@nextcloud/vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'
import SourceBranch from 'vue-material-design-icons/SourceBranch.vue'
import TagOutline from 'vue-material-design-icons/TagOutline.vue'
import Star from 'vue-material-design-icons/Star.vue'
import Download from 'vue-material-design-icons/Download.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'

export default {
	name: 'DiscoveredConfigurationCard',
	components: {
		NcButton,
		CogOutline,
		SourceBranch,
		TagOutline,
		Star,
		Download,
		OpenInNew,
	},
	props: {
		configuration: {
			type: Object,
			required: true,
		},
	},
	emits: ['import'],
	methods: {
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

.cardActions {
	display: flex;
	gap: 8px;
	margin-top: 4px;
}
</style>

