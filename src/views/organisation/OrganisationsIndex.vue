<script setup>
import { translate as t } from '@nextcloud/l10n'
import { organisationStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<CnIndexPage
			ref="indexPage"
			title="Organisaties"
			description="Beheer uw organisaties en wissel tussen hen"
			:show-title="true"
			:objects="organisationStore.userStats.list"
			:columns="tableColumns"
			:pagination="paginationData"
			:view-mode="organisationStore.viewMode"
			:selectable="true"
			:selected-ids="selectedOrganisations"
			:show-edit-action="false"
			:show-copy-action="false"
			:show-delete-action="false"
			:show-mass-import="false"
			:show-mass-export="false"
			:show-mass-copy="false"
			:show-mass-delete="false"
			mass-action-name-field="name"
			show-view-toggle
			add-label="Organisatie Aanmaken"
			row-key="uuid"
			empty-text="Geen organisaties gevonden"
			:row-class="getRowClass"
			:actions="rowActions"
			:refreshing="isRefreshing"
			@delete="handleDelete"
			@add="createOrganisation"
			@refresh="handleRefresh"
			@page-changed="onPageChanged"
			@page-size-changed="onPageSizeChanged"
			@view-mode-change="organisationStore.setViewMode($event)"
			@select="selectedOrganisations = $event">
			<!-- Active Organisation Banner (below title, above actions bar) -->
			<template #below-header>
				<div v-if="organisationStore.userStats.active" class="activeOrgBanner">
					<div class="activeOrgInfo">
						<span class="activeOrgLabel">Actieve Organisatie:</span>
						<span class="activeOrgName">{{ organisationStore.userStats.active.name }}</span>
						<CnStatusBadge
							v-if="organisationStore.userStats.active.isDefault"
							label="Standaard"
							variant="warning"
							:solid="true"
							size="small" />
					</div>
					<NcButton v-if="organisationStore.userStats.total > 1"
						type="secondary"
						@click="showOrganisationSwitcher = true">
						<template #icon>
							<SwapHorizontal :size="20" />
						</template>
						Wissel Organisatie
					</NcButton>
				</div>
			</template>

			<!-- Inline button next to actions menu -->
			<template #header-actions>
				<NcButton
					type="secondary"
					@click="navigationStore.setModal('joinOrganisation')">
					<template #icon>
						<AccountPlus :size="20" />
					</template>
					Add User to Organisation
				</NcButton>
			</template>

			<!-- Custom card template -->
			<template #card="{ object }">
				<OrganisationCard
					:item="object"
					:is-active="isActiveOrganisation(object)"
					:actions="rowActions" />
			</template>

			<!-- Custom column: name with badges -->
			<template #column-name="{ row }">
				<div class="titleContent">
					<strong>{{ row.name }}</strong>
					<div class="badges">
						<CnStatusBadge v-if="row.isDefault"
							label="Standaard"
							variant="warning"
							:solid="true"
							size="small" />
						<CnStatusBadge v-if="isActiveOrganisation(row)"
							label="Actief"
							variant="success"
							:solid="true"
							size="small" />
					</div>
					<span v-if="row.description" class="textDescription textEllipsis">{{ row.description }}</span>
				</div>
			</template>

			<!-- Custom column: members -->
			<template #column-members="{ row }">
				{{ row.users?.length || 0 }}
			</template>

			<!-- Custom column: status -->
			<template #column-status="{ row }">
				<span v-if="isActiveOrganisation(row)" class="statusActive">Actief</span>
				<span v-else class="statusInactive">Inactief</span>
			</template>

			<!-- Custom column: created -->
			<template #column-created="{ row }">
				{{ row.created ? formatDate(row.created) : '-' }}
			</template>

			<!-- Custom column: updated -->
			<template #column-updated="{ row }">
				{{ row.updated ? formatDate(row.updated) : '-' }}
			</template>
		</CnIndexPage>

		<!-- Organisation Switcher Modal -->
		<NcModal v-if="showOrganisationSwitcher"
			:name="'Wissel Actieve Organisatie'"
			@close="showOrganisationSwitcher = false">
			<div class="organisationSwitcher">
				<h3>Selecteer Actieve Organisatie</h3>
				<div class="organisationList">
					<div v-for="org in organisationStore.userStats.list"
						:key="org.uuid"
						class="organisationOption"
						:class="{ active: isActiveOrganisation(org) }"
						@click="switchToOrganisation(org)">
						<div class="organisationOptionContent">
							<span class="organisationOptionName">{{ org.name }}</span>
							<CnStatusBadge v-if="org.isDefault"
								label="Default"
								variant="warning"
								:solid="true"
								size="small" />
							<CnStatusBadge v-if="isActiveOrganisation(org)"
								label="Huidig"
								variant="success"
								:solid="true"
								size="small" />
						</div>
						<span v-if="org.description" class="organisationOptionDescription">{{ org.description }}</span>
					</div>
				</div>
			</div>
		</NcModal>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcButton, NcModal } from '@nextcloud/vue'
import { CnIndexPage, CnStatusBadge } from '@conduction/nextcloud-vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import AccountPlus from 'vue-material-design-icons/AccountPlus.vue'
import AccountMultiplePlus from 'vue-material-design-icons/AccountMultiplePlus.vue'
import SwapHorizontal from 'vue-material-design-icons/SwapHorizontal.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import Check from 'vue-material-design-icons/Check.vue'

import OrganisationCard from '../../components/cards/OrganisationCard.vue'
import { reloadAppData } from '../../services/AppInitializationService.js'

export default {
	name: 'OrganisationsIndex',
	components: {
		NcAppContent,
		CnIndexPage,
		CnStatusBadge,
		NcButton,
		NcModal,
		OrganisationCard,
		AccountPlus,
		SwapHorizontal,
	},
	data() {
		return {
			selectedOrganisations: [],
			showOrganisationSwitcher: false,
			isRefreshing: false,
		}
	},
	computed: {
		tableColumns() {
			return [
				{ key: 'name', label: 'Naam', sortable: true },
				{ key: 'members', label: 'Leden' },
				{ key: 'owner', label: 'Eigenaar' },
				{ key: 'status', label: 'Status' },
				{ key: 'created', label: 'Aangemaakt', sortable: true },
				{ key: 'updated', label: 'Bijgewerkt', sortable: true },
			]
		},
		rowActions() {
			return [
				{
					label: 'Bekijken',
					icon: Eye,
					disabled: true,
					handler: (row) => this.viewOrganisation(row),
				},
				{
					label: 'Instellen als Actief',
					icon: Check,
					disabled: (row) => this.isActiveOrganisation(row),
					handler: (row) => this.setActiveOrganisation(row.uuid),
				},
				{
					label: 'Bewerken',
					icon: Pencil,
					disabled: (row) => !this.canEditOrganisation(row),
					handler: (row) => this.editOrganisation(row),
				},
				{
					label: 'Ga naar organisatie',
					icon: OpenInNew,
					disabled: (row) => !row.website,
					handler: (row) => this.goToOrganisation(row),
				},
				{
					label: 'Add User',
					icon: AccountMultiplePlus,
					handler: (row) => this.openJoinModal(row),
				},
				{
					label: 'Verwijderen',
					icon: TrashCanOutline,
					destructive: true,
					handler: (row) => this.$refs.indexPage.openDeleteDialog(row),
				},
			]
		},
		paginationData() {
			const page = organisationStore.pagination.page || 1
			const limit = organisationStore.pagination.limit || 20
			const total = organisationStore.userStats.total || 0
			const pages = Math.ceil(total / limit)
			return { page, pages, total, limit }
		},
	},
	async mounted() {
		try {
			await organisationStore.loadNextcloudGroups()
			await organisationStore.refreshList()
			await organisationStore.getActiveOrganisation()
		} catch (error) {
			console.error('Error loading organisation data:', error)
		}
	},
	methods: {
		getRowClass(row) {
			return this.isActiveOrganisation(row) ? 'active-org-row' : ''
		},
		isActiveOrganisation(organisation) {
			return organisationStore.userStats.active
				   && organisationStore.userStats.active.uuid === organisation.uuid
		},
		getCurrentUser() {
			return window.OC?.getCurrentUser?.()?.uid || 'unknown'
		},
		canEditOrganisation(organisation) {
			return organisation.owner === 'system'
				   || organisation.owner === this.getCurrentUser()
		},
		async handleDelete(id) {
			const organisation = organisationStore.userStats.list.find(
				(org) => String(org.id) === String(id),
			)
			if (!organisation) {
				this.$refs.indexPage.setSingleDeleteResult({ error: 'Organisation not found' })
				return
			}
			try {
				const { response } = await organisationStore.deleteOne(organisation)
				this.$refs.indexPage.setSingleDeleteResult({ success: response.ok })
			} catch (error) {
				this.$refs.indexPage.setSingleDeleteResult({
					error: error.message || 'An error occurred while deleting the organisation',
				})
			}
		},
		async setActiveOrganisation(uuid) {
			try {
				await organisationStore.setActiveOrganisationById(uuid)
				console.info('[OrganisationsIndex] Reloading application data after organisation switch...')
				await reloadAppData()
			} catch (error) {
				console.error('Failed to change active organisation:', error.message)
			}
		},
		async switchToOrganisation(organisation) {
			try {
				await this.setActiveOrganisation(organisation.uuid)
				this.showOrganisationSwitcher = false
			} catch (error) {
				console.error('Failed to switch organisation:', error.message)
			}
		},
		async handleRefresh() {
			this.isRefreshing = true
			try {
				await organisationStore.refreshList()
			} finally {
				this.isRefreshing = false
			}
		},
		onPageChanged(page) {
			organisationStore.setPagination(page, organisationStore.pagination.limit)
		},
		onPageSizeChanged(pageSize) {
			organisationStore.setPagination(1, pageSize)
		},
		formatDate(dateString) {
			return new Date(dateString).toLocaleDateString({
				day: '2-digit',
				month: '2-digit',
				year: 'numeric',
			}) + ', ' + new Date(dateString).toLocaleTimeString({
				hour: '2-digit',
				minute: '2-digit',
			})
		},
		createOrganisation() {
			organisationStore.setItem(null)
			navigationStore.setModal('editOrganisation')
		},
		editOrganisation(organisation) {
			organisationStore.setItem(organisation)
			navigationStore.setModal('editOrganisation')
		},
		openJoinModal(organisation) {
			navigationStore.setTransferData({
				organisationUuid: organisation.uuid,
			})
			navigationStore.setModal('joinOrganisation')
		},
		viewOrganisation(organisation) {
			const publicationUrl = `https://www.softwarecatalogus.nl/publicatie/${organisation.id}`
			window.open(publicationUrl, '_blank')
		},
		goToOrganisation(organisation) {
			if (organisation.website) {
				let websiteUrl = organisation.website
				if (!websiteUrl.startsWith('http://') && !websiteUrl.startsWith('https://')) {
					websiteUrl = 'https://' + websiteUrl
				}
				window.open(websiteUrl, '_blank')
			}
		},
		showSuccessMessage(_message) {
			// Implementation would depend on your notification system
			// TODO: Integrate with Nextcloud notification system
		},
		showErrorMessage(_message) {
			// Implementation would depend on your notification system
			// TODO: Integrate with Nextcloud notification system
		},
	},
}
</script>

<style scoped lang="scss">
/* Active organisation banner */
.activeOrgBanner {
	background: var(--color-primary-light);
	border: 1px solid var(--color-primary-element-light);
	border-radius: 8px;
	padding: 12px 16px;
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
}

.activeOrgInfo {
	display: flex;
	align-items: center;
	gap: 8px;
}

.activeOrgLabel {
	font-weight: 600;
	color: var(--color-text-dark);
}

.activeOrgName {
	font-weight: 700;
	color: var(--color-primary-text);
}

/* Table styling */
.active-org-row {
	background: var(--color-success-light) !important;
}

.titleContent {
	display: flex;
	flex-direction: column;
}

.badges {
	display: flex;
	gap: 4px;
	margin-top: 4px;
}

.statusActive {
	color: var(--color-success);
	font-weight: 600;
}

.statusInactive {
	color: var(--color-text-lighter);
}

/* Organisation switcher modal */
.organisationSwitcher {
	padding: 20px;
}

.organisationSwitcher h3 {
	margin-bottom: 20px;
	color: var(--color-text-dark);
}

.organisationList {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.organisationOption {
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	cursor: pointer;
	transition: background-color 0.2s ease;
}

.organisationOption:hover {
	background: var(--color-background-hover);
}

.organisationOption.active {
	background: var(--color-success-light);
	border-color: var(--color-success);
}

.organisationOptionContent {
	display: flex;
	align-items: center;
	gap: 8px;
}

.organisationOptionName {
	font-weight: 600;
	color: var(--color-text-dark);
}

.organisationOptionDescription {
	color: var(--color-text-lighter);
	font-size: 12px;
	margin-top: 4px;
	display: block;
}
</style>
