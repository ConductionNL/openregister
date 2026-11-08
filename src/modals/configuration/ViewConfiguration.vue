<script setup>
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { configurationStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog
		v-if="navigationStore.modal === 'viewConfiguration'"
		:name="`View Configuration: ${configurationStore.configurationItem?.title || 'Unknown'}`"
		size="large"
		noClose>
		<div class="formContainer viewConfigurationDialog">
			<!-- Configuration Details -->
			<div class="configurationDetailsGrid">
				<div class="configurationMainInfo">
					<h2>{{ configurationStore.configurationItem?.title }}</h2>
					<p
						v-if="configurationStore.configurationItem?.description"
						class="configurationDescription">
						{{ configurationStore.configurationItem.description }}
					</p>
				</div>

				<div class="configurationProperties">
					<div class="propertyItem">
						<strong>{{ t('openregister', 'Type') }}:</strong>
						<span>{{
							configurationStore.configurationItem?.type || 'Unknown'
						}}</span>
					</div>
					<div
						v-if="configurationStore.configurationItem?.owner"
						class="propertyItem">
						<strong>{{ t('openregister', 'Owner') }}:</strong>
						<span>{{ configurationStore.configurationItem.owner }}</span>
					</div>
					<TimestampProperties
						:created="configurationStore.configurationItem?.created"
						:updated="configurationStore.configurationItem?.updated" />
				</div>
			</div>

			<!-- Tabs for additional information -->
			<ViewDialogTabs v-model="activeTab" :tabs="tabs">
				<!-- Configuration Tab -->
				<div v-if="activeTab === 0" class="tabPanel">
					<div
						v-if="
							configurationStore.configurationItem?.configuration
						"
						class="configurationJsonContainer">
						<h3>{{ t('openregister', 'Configuration Data') }}</h3>
						<div class="jsonViewer">
							<pre>{{
								JSON.stringify(
									configurationStore.configurationItem
										.configuration,
									null,
									2,
								)
							}}</pre>
						</div>
					</div>
					<div v-else class="emptyTabContent">
						<NcEmptyContent
							:name="t('openregister', 'No configuration data')"
							:description="
								t(
									'openregister',
									'This configuration has no data defined.',
								)
							">
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
							:description="
								t(
									'openregister',
									'No logs are available for this configuration.',
								)
							">
							<template #icon>
								<PostOutline :size="64" />
							</template>
						</NcEmptyContent>
					</div>
				</div>
			</ViewDialogTabs>
		</div>

		<template #actions>
			<NcActionButton closeAfterClick @click="editConfiguration">
				<template #icon>
					<Pencil :size="20" />
				</template>
				Edit Configuration
			</NcActionButton>
			<NcActionButton closeAfterClick @click="exportConfiguration">
				<template #icon>
					<Download :size="20" />
				</template>
				Export Configuration
			</NcActionButton>
			<NcActionButton closeAfterClick @click="deleteConfiguration">
				<template #icon>
					<TrashCanOutline :size="20" />
				</template>
				Delete Configuration
			</NcActionButton>
			<NcButton variant="primary" @click="closeModal">
				<template #icon>
					<Cancel :size="20" />
				</template>
				Close
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcActionButton, NcButton, NcDialog, NcEmptyContent } from '@nextcloud/vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'
import Download from 'vue-material-design-icons/Download.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import PostOutline from 'vue-material-design-icons/PostOutline.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import TimestampProperties from '../shared/TimestampProperties.vue'
import ViewDialogTabs from '../shared/ViewDialogTabs.vue'

export default {
	name: 'ViewConfiguration',
	components: {
		TimestampProperties,
		ViewDialogTabs,
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
		/**
		 * @spec openspec/specs/entity-management-modals/spec.md
		 */
		closeModal() {
			navigationStore.setModal(false)
		},

		/**
		 * @spec exclude Modal navigation plumbing — opens the edit-configuration modal.
		 */
		editConfiguration() {
			navigationStore.setModal('editConfiguration')
		},

		/**
		 * @spec exclude Modal navigation plumbing — opens the export-configuration modal.
		 */
		exportConfiguration() {
			navigationStore.setModal('exportConfiguration')
		},

		/**
		 * @spec exclude Modal navigation plumbing — opens the delete-configuration dialog.
		 */
		deleteConfiguration() {
			navigationStore.setModal(false)
			navigationStore.setDialog('deleteConfiguration')
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../styles/modals.scss' as *;

@include view-dialog-details('configuration', 'Configuration');

@include view-dialog-tab-panels;

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
	overflow-wrap: break-word;
	font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
	font-size: 0.875rem;
	line-height: 1.4;
	color: var(--color-main-text);
}

@include empty-tab-content-rules;
</style>
