<script setup>
import { configurationStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.modal === 'viewConfiguration'"
		:name="`View Configuration: ${configurationStore.configurationItem?.title || 'Unknown'}`"
		size="large"
		:can-close="false">
		<div class="formContainer viewConfigurationDialog">
			<!-- Configuration Details -->
			<div class="configurationDetailsGrid">
				<div class="configurationMainInfo">
					<h2>{{ configurationStore.configurationItem?.title }}</h2>
					<p v-if="configurationStore.configurationItem?.description" class="configurationDescription">
						{{ configurationStore.configurationItem.description }}
					</p>
				</div>

				<div class="configurationProperties">
					<div class="propertyItem">
						<strong>{{ t('openregister', 'Type') }}:</strong>
						<span>{{ configurationStore.configurationItem?.type || 'Unknown' }}</span>
					</div>
					<div v-if="configurationStore.configurationItem?.owner" class="propertyItem">
						<strong>{{ t('openregister', 'Owner') }}:</strong>
						<span>{{ configurationStore.configurationItem.owner }}</span>
					</div>
					<div v-if="configurationStore.configurationItem?.created" class="propertyItem">
						<strong>{{ t('openregister', 'Created') }}:</strong>
						<span>{{ new Date(configurationStore.configurationItem.created).toLocaleString() }}</span>
					</div>
					<div v-if="configurationStore.configurationItem?.updated" class="propertyItem">
						<strong>{{ t('openregister', 'Updated') }}:</strong>
						<span>{{ new Date(configurationStore.configurationItem.updated).toLocaleString() }}</span>
					</div>
				</div>
			</div>

			<!-- Tabs for additional information -->
			<div class="tabContainer">
				<div class="tabHeaders">
					<button
						v-for="(tab, index) in tabs"
						:key="tab"
						class="tabHeader"
						:class="{ active: activeTab === index }"
						@click="activeTab = index">
						{{ tab }}
					</button>
				</div>

				<div class="tabContent">
					<!-- Configuration Tab -->
					<div v-if="activeTab === 0" class="tabPanel">
						<div v-if="configurationStore.configurationItem?.configuration" class="configurationJsonContainer">
							<h3>{{ t('openregister', 'Configuration Data') }}</h3>
							<div class="jsonViewer">
								<pre>{{ JSON.stringify(configurationStore.configurationItem.configuration, null, 2) }}</pre>
							</div>
						</div>
						<div v-else class="emptyTabContent">
							<NcEmptyContent
								:name="t('openregister', 'No configuration data')"
								:description="t('openregister', 'This configuration has no data defined.')">
								<template #icon>
									<CogOutline :size="64" />
								</template>
							</NcEmptyContent>
						</div>
					</div>

					<!-- Logs Tab -->
					<div v-if="activeTab === 1" class="tabPanel">
						<div class="emptyTabContent">
							<NcEmptyContent
								:name="t('openregister', 'No logs found')"
								:description="t('openregister', 'No logs are available for this configuration.')">
								<template #icon>
									<PostOutline :size="64" />
								</template>
							</NcEmptyContent>
						</div>
					</div>
				</div>
			</div>
		</div>

		<template #actions>
			<NcActionButton close-after-click @click="editConfiguration">
				<template #icon>
					<Pencil :size="20" />
				</template>
				Edit Configuration
			</NcActionButton>
			<NcActionButton close-after-click @click="exportConfiguration">
				<template #icon>
					<Download :size="20" />
				</template>
				Export Configuration
			</NcActionButton>
			<NcActionButton close-after-click @click="deleteConfiguration">
				<template #icon>
					<TrashCanOutline :size="20" />
				</template>
				Delete Configuration
			</NcActionButton>
			<NcButton type="primary" @click="closeModal">
				<template #icon>
					<Cancel :size="20" />
				</template>
				Close
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcDialog,
	NcButton,
	NcActionButton,
	NcEmptyContent,
} from '@nextcloud/vue'

import Cancel from 'vue-material-design-icons/Cancel.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import Download from 'vue-material-design-icons/Download.vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'
import PostOutline from 'vue-material-design-icons/PostOutline.vue'

export default {
	name: 'ViewConfiguration',
	components: {
		NcDialog,
		NcButton,
		NcActionButton,
		NcEmptyContent,
		Cancel,
		Pencil,
		TrashCanOutline,
		Download,
		CogOutline,
		PostOutline,
	},
	data() {
		return {
			activeTab: 0,
			tabs: [
				this.t('openregister', 'Configuration'),
				this.t('openregister', 'Logs'),
			],
		}
	},
	methods: {
		closeModal() {
			navigationStore.setModal(false)
		},
		editConfiguration() {
			navigationStore.setModal('editConfiguration')
		},
		exportConfiguration() {
			navigationStore.setModal('exportConfiguration')
		},
		deleteConfiguration() {
			navigationStore.setModal(false)
			navigationStore.setDialog('deleteConfiguration')
		},
	},
}
</script>

<style scoped>
.viewConfigurationDialog {
	display: flex;
	flex-direction: column;
	gap: 1.5rem;
}

.configurationDetailsGrid {
	display: flex;
	flex-direction: column;
	gap: 1rem;
}

.configurationMainInfo h2 {
	margin: 0 0 0.5rem 0;
	color: var(--color-main-text);
}

.configurationDescription {
	color: var(--color-text-lighter);
	margin: 0;
}

.configurationProperties {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
	padding: 1rem;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius);
}

.propertyItem {
	display: flex;
	gap: 0.5rem;
}

.propertyItem strong {
	min-width: 120px;
	color: var(--color-text-lighter);
}

.tabContainer {
	margin-top: 1rem;
}

.tabHeaders {
	display: flex;
	border-bottom: 1px solid var(--color-border);
	margin-bottom: 1rem;
}

.tabHeader {
	padding: 0.75rem 1rem;
	background: none;
	border: none;
	cursor: pointer;
	color: var(--color-text-lighter);
	border-bottom: 2px solid transparent;
	transition: all 0.2s ease;
}

.tabHeader:hover {
	color: var(--color-main-text);
	background-color: var(--color-background-hover);
}

.tabHeader.active {
	color: var(--color-primary);
	border-bottom-color: var(--color-primary);
}

.tabContent {
	min-height: 200px;
}

.tabPanel {
	padding: 1rem 0;
}

.configurationJsonContainer {
	display: flex;
	flex-direction: column;
	gap: 1rem;
}

.configurationJsonContainer h3 {
	margin: 0;
	color: var(--color-main-text);
}

.jsonViewer {
	background-color: var(--color-background-dark);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 1rem;
	overflow: auto;
	max-height: 400px;
}

.jsonViewer pre {
	margin: 0;
	white-space: pre-wrap;
	word-wrap: break-word;
	font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
	font-size: 0.875rem;
	line-height: 1.4;
	color: var(--color-main-text);
}

.emptyTabContent {
	display: flex;
	justify-content: center;
	align-items: center;
	min-height: 200px;
}
</style>
