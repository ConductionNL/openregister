<script setup>
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { applicationStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<div
			v-if="applicationStore.loading || !applicationStore.applicationItem"
			class="loadingContainer">
			<NcLoadingIcon :size="64" />
			<p>{{ t('openregister', 'Loading application details...') }}</p>
		</div>

		<div v-else-if="applicationStore.error" class="errorContainer">
			<NcEmptyContent
				:name="t('openregister', 'Error loading application')"
				:description="applicationStore.error">
				<template #icon>
					<AlertCircleOutline :size="64" />
				</template>
				<template #action>
					<NcButton
						variant="primary"
						@click="$router.push({ name: 'applications' })">
						<template #icon>
							<ArrowLeft :size="20" />
						</template>
						{{ t('openregister', 'Back to applications') }}
					</NcButton>
				</template>
			</NcEmptyContent>
		</div>

		<div v-else class="detailsContainer">
			<!-- Header -->
			<div class="detailsHeader">
				<div class="headerTitleSection">
					<NcButton
						variant="tertiary"
						@click="$router.push({ name: 'applications' })">
						<template #icon>
							<ArrowLeft :size="20" />
						</template>
						{{ t('openregister', 'Back') }}
					</NcButton>
					<h1>
						<ApplicationOutline :size="32" />
						{{ applicationStore.applicationItem.name }}
					</h1>
					<span
						v-if="applicationStore.applicationItem.version"
						class="versionBadge">
						v{{ applicationStore.applicationItem.version }}
					</span>
					<span
						v-if="applicationStore.applicationItem.active"
						class="statusBadge active">
						{{ t('openregister', 'Active') }}
					</span>
					<span v-else class="statusBadge inactive">
						{{ t('openregister', 'Inactive') }}
					</span>
				</div>
				<div class="headerActions">
					<NcActions :inline="2">
						<NcActionButton
							@click="navigationStore.setModal('editApplication')">
							<template #icon>
								<Pencil :size="20" />
							</template>
							{{ t('openregister', 'Edit') }}
						</NcActionButton>
						<NcActionButton
							@click="navigationStore.setDialog('deleteApplication')">
							<template #icon>
								<TrashCanOutline :size="20" />
							</template>
							{{ t('openregister', 'Delete') }}
						</NcActionButton>
					</NcActions>
				</div>
			</div>

			<!-- Description -->
			<div
				v-if="applicationStore.applicationItem.description"
				class="detailsSection">
				<p class="description">
					{{ applicationStore.applicationItem.description }}
				</p>
			</div>

			<!-- Details Grid -->
			<div class="detailsGrid">
				<!-- Basic Information -->
				<div class="detailsCard">
					<h3>
						<InformationOutline :size="20" />
						{{ t('openregister', 'Basic Information') }}
					</h3>
					<div class="detailsContent">
						<div class="detailRow">
							<strong>{{ t('openregister', 'ID') }}:</strong>
							<span class="monospace">{{
								applicationStore.applicationItem.id
							}}</span>
						</div>
						<div
							v-if="applicationStore.applicationItem.version"
							class="detailRow">
							<strong>{{ t('openregister', 'Version') }}:</strong>
							<span>{{
								applicationStore.applicationItem.version
							}}</span>
						</div>
						<div class="detailRow">
							<strong>{{ t('openregister', 'Status') }}:</strong>
							<ActiveStatus :active="applicationStore.applicationItem.active" />
						</div>
						<div
							v-if="applicationStore.applicationItem.created"
							class="detailRow">
							<strong>{{ t('openregister', 'Created') }}:</strong>
							<span>{{
								new Date(
									applicationStore.applicationItem.created,
								).toLocaleString()
							}}</span>
						</div>
						<div
							v-if="applicationStore.applicationItem.updated"
							class="detailRow">
							<strong>{{ t('openregister', 'Updated') }}:</strong>
							<span>{{
								new Date(
									applicationStore.applicationItem.updated,
								).toLocaleString()
							}}</span>
						</div>
					</div>
				</div>

				<!-- Organisation -->
				<div
					v-if="applicationStore.applicationItem.organisation"
					class="detailsCard">
					<h3>
						<OfficeBuilding :size="20" />
						{{ t('openregister', 'Organisation') }}
					</h3>
					<div class="detailsContent">
						<div class="detailRow">
							<strong
								>{{ t('openregister', 'Organisation ID') }}:</strong
							>
							<span>{{
								applicationStore.applicationItem.organisation
							}}</span>
						</div>
					</div>
				</div>

				<div
					v-for="card in linkedItemCards"
					:key="card.key"
					class="detailsCard">
					<h3>
						<component :is="card.icon" :size="20" />
						{{ card.title }}
					</h3>
					<div class="detailsContent">
						<div v-if="card.items && card.items.length > 0">
							<p>
								{{ card.items.length }}
								{{ card.unit }}
							</p>
						</div>
						<div v-else>
							<NcNoteCard type="info">
								{{ card.empty }}
							</NcNoteCard>
						</div>
					</div>
				</div>
			</div>
		</div>
	</NcAppContent>
</template>

<script>
import {
	NcActionButton,
	NcActions,
	NcAppContent,
	NcButton,
	NcEmptyContent,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import ApplicationOutline from 'vue-material-design-icons/ApplicationOutline.vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import Database from 'vue-material-design-icons/Database.vue'
import FileOutline from 'vue-material-design-icons/FileOutline.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import OfficeBuilding from 'vue-material-design-icons/OfficeBuilding.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import ActiveStatus from './ActiveStatus.vue'

export default {
	name: 'ApplicationDetails',
	components: {
		ActiveStatus,
		NcAppContent,
		NcButton,
		NcActions,
		NcActionButton,
		NcLoadingIcon,
		NcEmptyContent,
		NcNoteCard,
		ArrowLeft,
		ApplicationOutline,
		Pencil,
		TrashCanOutline,
		InformationOutline,
		AlertCircleOutline,
		OfficeBuilding,
		Cog,
		Database,
		FileOutline,
	},

	computed: {
		/**
		 * The cards counting the configurations, registers and schemas of the
		 * application, for display.
		 *
		 * @spec exclude UI display helper — linked-item card layout.
		 * @return {Array<object>}
		 */
		linkedItemCards() {
			const item = applicationStore.applicationItem
			return [
				{
					key: 'configurations',
					icon: Cog,
					title: this.t('openregister', 'Configurations'),
					unit: this.t('openregister', 'configuration(s)'),
					empty: this.t(
						'openregister',
						'No configurations found for this application.',
					),

					items: item.configurations,
				},
				{
					key: 'registers',
					icon: Database,
					title: this.t('openregister', 'Registers'),
					unit: this.t('openregister', 'register(s)'),
					empty: this.t(
						'openregister',
						'No registers found for this application.',
					),

					items: item.registers,
				},
				{
					key: 'schemas',
					icon: FileOutline,
					title: this.t('openregister', 'Schemas'),
					unit: this.t('openregister', 'schema(s)'),
					empty: this.t(
						'openregister',
						'No schemas found for this application.',
					),

					items: item.schemas,
				},
			]
		},
	},

	/**
	 * @spec exclude detail-view lifecycle fetch of the application by route id on mount
	 */
	async mounted() {
		// Load application details if we have an ID in the route
		const applicationId = this.$route.params.id
		if (applicationId) {
			await applicationStore.getApplication(applicationId)
		}
	},
}
</script>

<style scoped lang="scss">
@use '../../styles/views.scss' as *;

.loadingContainer,
.errorContainer {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 60px 20px;
	gap: 20px;
}

.detailsContainer {
	padding: 20px;
	max-width: 1400px;
	margin: 0 auto;
}

.detailsHeader {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	margin-bottom: 30px;
	padding-bottom: 20px;
	border-bottom: 1px solid var(--color-border);
}

.headerTitleSection {
	display: flex;
	align-items: center;
	gap: 16px;
}

.headerTitleSection h1 {
	display: flex;
	align-items: center;
	gap: 12px;
	margin: 0;
	font-size: 28px;
	font-weight: 600;
}

.versionBadge {
	padding: 4px 12px;
	background-color: var(--color-primary-element);
	color: var(--color-primary-text);
	border-radius: 16px;
	font-size: 12px;
	font-weight: 600;
}

.statusBadge {
	padding: 4px 12px;
	border-radius: 16px;
	font-size: 12px;
	font-weight: 600;
}

.statusBadge.active {
	background-color: var(--color-success);
	color: white;
}

.statusBadge.inactive {
	background-color: var(--color-text-lighter);
	color: white;
}

.detailsSection {
	margin-bottom: 30px;
}

.description {
	font-size: 16px;
	line-height: 1.6;
	color: var(--color-text-lighter);
	margin: 0;
}

.detailsGrid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
	gap: 20px;
}

.detailsCard {
	background-color: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 8px;
	padding: 20px;
}

.detailsCard h3 {
	display: flex;
	align-items: center;
	gap: 8px;
	margin: 0 0 16px 0;
	font-size: 18px;
	font-weight: 600;
	color: var(--color-main-text);
}

.detailsContent {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.detailRow {
	display: flex;
	gap: 8px;
}

.detailRow strong {
	min-width: 120px;
	color: var(--color-text-light);
}

.monospace {
	font-family: monospace;
	font-size: 14px;
	background-color: var(--color-background-dark);
	padding: 2px 6px;
	border-radius: 4px;
}

@include status-active;

.status-inactive {
	color: var(--color-text-lighter);
}
</style>
