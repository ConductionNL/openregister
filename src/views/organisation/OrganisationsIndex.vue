<script setup>
import { organisationStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<div class="viewContainer">
			<!-- Header -->
			<div class="viewHeader">
				<h1 class="viewHeaderTitleIndented">
					Organisaties
				</h1>
				<p>Beheer uw organisaties en wissel tussen hen</p>
			</div>

			<!-- Active Organisation Status -->
			<div v-if="organisationStore.userStats.active" class="activeOrgBanner">
				<div class="activeOrgInfo">
					<span class="activeOrgLabel">Actieve Organisatie:</span>
					<span class="activeOrgName">{{ organisationStore.userStats.active.name }}</span>
					<span v-if="organisationStore.userStats.active.isDefault" class="defaultBadge">
						Standaard
					</span>
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

			<!-- Actions Bar -->
			<div class="viewActionsBar">
				<div class="viewInfo">
					<span class="viewTotalCount">
						Toont {{ paginatedOrganisations.length }} van {{ organisationStore.userStats.total }} organisaties
					</span>
					<span v-if="selectedOrganisations.length > 0" class="viewIndicator">
						({{ selectedOrganisations.length }} geselecteerd)
					</span>
				</div>
				<div class="viewActions">
					<div class="viewModeSwitchContainer">
						<NcCheckboxRadioSwitch
							v-model="organisationStore.viewMode"
							v-tooltip="'See organisations as cards'"
							:button-variant="true"
							value="cards"
							name="view_mode_radio"
							type="radio"
							button-variant-grouped="horizontal">
							Kaarten
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch
							v-model="organisationStore.viewMode"
							v-tooltip="'See organisations as a table'"
							:button-variant="true"
							value="table"
							name="view_mode_radio"
							type="radio"
							button-variant-grouped="horizontal">
							Tabel
						</NcCheckboxRadioSwitch>
					</div>

					<NcActions
						:force-name="true"
						:inline="3"
						menu-name="Actions">
						<NcActionButton
							:primary="true"
							close-after-click
							@click="createOrganisation">
							<template #icon>
								<Plus :size="20" />
							</template>
							Organisatie Aanmaken
						</NcActionButton>
						<NcActionButton
							close-after-click
							@click="navigationStore.setModal('joinOrganisation')">
							<template #icon>
								<AccountPlus :size="20" />
							</template>
							Add User to Organisation
						</NcActionButton>
						<NcActionButton
							close-after-click
							@click="organisationStore.refreshOrganisationList()">
							<template #icon>
								<Refresh :size="20" />
							</template>
							Vernieuwen
						</NcActionButton>
					</NcActions>
				</div>
			</div>

			<!-- Empty State -->
			<NcEmptyContent v-if="!organisationStore.userStats.total"
				:name="'Geen organisaties gevonden'"
				:description="'U bent nog geen lid van organisaties.'">
				<template #icon>
					<OfficeBuilding :size="64" />
				</template>
			</NcEmptyContent>

			<!-- Content -->
			<div v-else>
				<template v-if="organisationStore.viewMode === 'cards'">
					<div class="cardGrid">
						<div v-for="organisation in paginatedOrganisations"
							:key="organisation.uuid"
							class="card"
							:class="{ 'active-org-card': isActiveOrganisation(organisation) }">
							<div class="cardHeader">
								<h2>
									<OfficeBuilding :size="20" />
									{{ organisation.name }}
									<span v-if="organisation.isDefault" class="defaultBadge">Standaard</span>
									<span v-if="isActiveOrganisation(organisation)" class="activeBadge">Actief</span>
								</h2>
								<NcActions :primary="true" menu-name="Actions">
									<template #icon>
										<DotsHorizontal :size="20" />
									</template>
									<NcActionButton close-after-click
										@click="viewOrganisation(organisation)">
										<template #icon>
											<Eye :size="20" />
										</template>
										Bekijken
									</NcActionButton>
									<NcActionButton v-if="canEditOrganisation(organisation)"
										close-after-click
										@click="editOrganisation(organisation)">
										<template #icon>
											<Pencil :size="20" />
										</template>
										Bewerken
									</NcActionButton>
									<NcActionButton v-if="organisation.website"
										close-after-click
										@click="goToOrganisation(organisation)">
										<template #icon>
											<OpenInNew :size="20" />
										</template>
										Ga naar organisatie
									</NcActionButton>
									<NcActionButton
										close-after-click
										@click="openJoinModal(organisation)">
										<template #icon>
											<AccountMultiplePlus :size="20" />
										</template>
										Add User
									</NcActionButton>
									<NcActionButton v-if="canDeleteOrganisation(organisation)"
										close-after-click
										@click="organisationStore.setOrganisationItem(organisation); navigationStore.setModal('deleteOrganisation')">
										<template #icon>
											<TrashCanOutline :size="20" />
										</template>
										Verwijderen
									</NcActionButton>
								</NcActions>
							</div>

							<div class="organisationInfo">
								<p v-if="organisation.description" class="description">
									{{ organisation.description }}
								</p>
								<div class="organisationStats">
									<div class="stat">
										<span class="statLabel">Leden:</span>
										<span class="statValue">{{ organisation.users?.length || 0 }}</span>
									</div>
									<div class="stat">
										<span class="statLabel">Eigenaar:</span>
										<span class="statValue">{{ organisation.owner || 'System' }}</span>
									</div>
									<div v-if="organisation.created" class="stat">
										<span class="statLabel">Aangemaakt:</span>
										<span class="statValue">{{ formatDate(organisation.created) }}</span>
									</div>
								</div>
							</div>
						</div>
					</div>
				</template>
				<template v-else>
					<div class="viewTableContainer">
						<table class="viewTable">
							<thead>
								<tr>
									<th class="tableColumnCheckbox">
										<NcCheckboxRadioSwitch
											:checked="allSelected"
											:indeterminate="someSelected"
											@update:checked="toggleSelectAll" />
									</th>
									<th>Naam</th>
									<th>Leden</th>
									<th>Eigenaar</th>
									<th>Status</th>
									<th>Aangemaakt</th>
									<th>Bijgewerkt</th>
									<th class="tableColumnActions">
										Acties
									</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="organisation in paginatedOrganisations"
									:key="organisation.uuid"
									class="viewTableRow"
									:class="{
										viewTableRowSelected: selectedOrganisations.includes(organisation.uuid),
										'active-org-row': isActiveOrganisation(organisation)
									}">
									<td class="tableColumnCheckbox">
										<NcCheckboxRadioSwitch
											:checked="selectedOrganisations.includes(organisation.uuid)"
											@update:checked="(checked) => toggleOrganisationSelection(organisation.uuid, checked)" />
									</td>
									<td class="tableColumnTitle">
										<div class="titleContent">
											<strong>{{ organisation.name }}</strong>
											<div class="badges">
												<span v-if="organisation.isDefault" class="defaultBadge">Standaard</span>
												<span v-if="isActiveOrganisation(organisation)" class="activeBadge">Actief</span>
											</div>
											<span v-if="organisation.description" class="textDescription textEllipsis">{{ organisation.description }}</span>
										</div>
									</td>
									<td>{{ organisation.users?.length || 0 }}</td>
									<td>{{ organisation.owner || 'System' }}</td>
									<td>
										<span v-if="isActiveOrganisation(organisation)" class="statusActive">Actief</span>
										<span v-else class="statusInactive">Inactief</span>
									</td>
									<td>{{ organisation.created ? formatDate(organisation.created) : '-' }}</td>
									<td>{{ organisation.updated ? formatDate(organisation.updated) : '-' }}</td>
									<td class="tableColumnActions">
										<NcActions :primary="false">
											<template #icon>
												<DotsHorizontal :size="20" />
											</template>
											<NcActionButton close-after-click
												@click="viewOrganisation(organisation)">
												<template #icon>
													<Eye :size="20" />
												</template>
												Bekijken
											</NcActionButton>
											<NcActionButton v-if="canEditOrganisation(organisation)"
												close-after-click
												@click="editOrganisation(organisation)">
												<template #icon>
													<Pencil :size="20" />
												</template>
												Bewerken
											</NcActionButton>
											<NcActionButton v-if="organisation.website"
												close-after-click
												@click="goToOrganisation(organisation)">
												<template #icon>
													<OpenInNew :size="20" />
												</template>
												Ga naar organisatie
											</NcActionButton>
											<NcActionButton
												close-after-click
												@click="openJoinModal(organisation)">
												<template #icon>
													<AccountMultiplePlus :size="20" />
												</template>
												Add User
											</NcActionButton>
											<NcActionButton v-if="canDeleteOrganisation(organisation)"
												close-after-click
												@click="organisationStore.setOrganisationItem(organisation); navigationStore.setModal('deleteOrganisation')">
												<template #icon>
													<TrashCanOutline :size="20" />
												</template>
												Verwijderen
											</NcActionButton>
										</NcActions>
									</td>
								</tr>
							</tbody>
						</table>
					</div>
				</template>
			</div>

			<!-- Pagination -->
			<PaginationComponent
				v-if="organisationStore.userStats.total > 0"
				:current-page="organisationStore.pagination.page || 1"
				:total-pages="Math.ceil(organisationStore.userStats.total / (organisationStore.pagination.limit || 20))"
				:total-items="organisationStore.userStats.total"
				:current-page-size="organisationStore.pagination.limit || 20"
				:min-items-to-show="10"
				@page-changed="onPageChanged"
				@page-size-changed="onPageSizeChanged" />
		</div>

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
							<span v-if="org.isDefault" class="defaultBadge">Default</span>
							<span v-if="isActiveOrganisation(org)" class="activeBadge">Huidig</span>
						</div>
						<span v-if="org.description" class="organisationOptionDescription">{{ org.description }}</span>
					</div>
				</div>
			</div>
		</NcModal>

		<!-- Organisation Management Modal -->
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcEmptyContent, NcActions, NcActionButton, NcCheckboxRadioSwitch, NcButton, NcModal } from '@nextcloud/vue'
import OfficeBuilding from 'vue-material-design-icons/OfficeBuilding.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import AccountPlus from 'vue-material-design-icons/AccountPlus.vue'
import AccountMultiplePlus from 'vue-material-design-icons/AccountMultiplePlus.vue'
import SwapHorizontal from 'vue-material-design-icons/SwapHorizontal.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'

import PaginationComponent from '../../components/PaginationComponent.vue'

export default {
	name: 'OrganisationsIndex',
	components: {
		NcCheckboxRadioSwitch,
		NcAppContent,
		NcEmptyContent,
		NcActions,
		NcActionButton,
		NcButton,
		NcModal,
		OfficeBuilding,
		DotsHorizontal,
		Pencil,
		TrashCanOutline,
		Refresh,
		AccountPlus,
		AccountMultiplePlus,
		SwapHorizontal,
		Plus,
		Eye,
		OpenInNew,
		PaginationComponent,
	},
	data() {
		return {
			selectedOrganisations: [],
			showOrganisationSwitcher: false,
		}
	},
	computed: {
		allSelected() {
			return organisationStore.userStats.list.length > 0 && organisationStore.userStats.list.every(org => this.selectedOrganisations.includes(org.uuid))
		},
		someSelected() {
			return this.selectedOrganisations.length > 0 && !this.allSelected
		},
		paginatedOrganisations() {
			const start = ((organisationStore.pagination.page || 1) - 1) * (organisationStore.pagination.limit || 20)
			const end = start + (organisationStore.pagination.limit || 20)
			return organisationStore.userStats.list.slice(start, end)
		},
	},
	async mounted() {
		try {
			// Load Nextcloud groups into store first (needed for edit modal)
			await organisationStore.loadNextcloudGroups()
			// Then load organisations
			await organisationStore.refreshOrganisationList()
			await organisationStore.getActiveOrganisation()
		} catch (error) {
			console.error('Error loading organisation data:', error)
		}
	},
	methods: {
		isActiveOrganisation(organisation) {
			return organisationStore.userStats.active
				   && organisationStore.userStats.active.uuid === organisation.uuid
		},
		getCurrentUser() {
			// Get current user from global OC object (Nextcloud's way)
			return window.OC?.getCurrentUser?.()?.uid || 'unknown'
		},
		canEditOrganisation(organisation) {
			// Only the owner can edit the organisation (or system for default org)
			return organisation.owner === 'system'
				   || organisation.owner === this.getCurrentUser()
		},
		canLeaveOrganisation(organisation) {
			// Can't leave if it's your only organisation or if you're the owner
			return organisationStore.userStats.total > 1
				   && !organisation.isDefault
				   && organisation.owner !== this.getCurrentUser()
		},
		canDeleteOrganisation(organisation) {
			// Only owners can delete, and can't delete default organisation
			return !organisation.isDefault
				   && organisation.owner === this.getCurrentUser()
		},
		async setActiveOrganisation(uuid) {
			try {
				await organisationStore.setActiveOrganisationById(uuid)
				this.showSuccessMessage('Active organisation changed successfully')
			} catch (error) {
				this.showErrorMessage('Failed to change active organisation: ' + error.message)
			}
		},
		async switchToOrganisation(organisation) {
			try {
				await this.setActiveOrganisation(organisation.uuid)
				this.showOrganisationSwitcher = false
			} catch (error) {
				this.showErrorMessage('Failed to switch organisation: ' + error.message)
			}
		},
		async leaveOrganisation(organisation) {
			if (!confirm(`Are you sure you want to leave '${organisation.name}'?`)) {
				return
			}

			try {
				await organisationStore.leaveOrganisation(organisation.uuid)
				this.showSuccessMessage('Left organisation successfully')
			} catch (error) {
				this.showErrorMessage('Failed to leave organisation: ' + error.message)
			}
		},
		toggleSelectAll(checked) {
			if (checked) {
				this.selectedOrganisations = organisationStore.userStats.list.map(org => org.uuid)
			} else {
				this.selectedOrganisations = []
			}
		},
		toggleOrganisationSelection(orgUuid, checked) {
			if (checked) {
				this.selectedOrganisations.push(orgUuid)
			} else {
				this.selectedOrganisations = this.selectedOrganisations.filter(uuid => uuid !== orgUuid)
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
		// Organisation Modal Methods
		createOrganisation() {
			organisationStore.setOrganisationItem(null)
			navigationStore.setModal('editOrganisation')
		},
		editOrganisation(organisation) {
			organisationStore.setOrganisationItem(organisation)
			navigationStore.setModal('editOrganisation')
		},
		openJoinModal(organisation) {
			// Set the transfer data with the organisation UUID
			navigationStore.setTransferData({
				organisationUuid: organisation.uuid,
			})
			// Open the join organisation modal
			navigationStore.setModal('joinOrganisation')
		},
		openManageRolesModal(organisation) {
			// Set the organisation item in store
			organisationStore.setOrganisationItem(organisation)
			// Open the manage organisation roles modal
			navigationStore.setModal('manageOrganisationRoles')
		},
		// Organisation Action Methods
		viewOrganisation(organisation) {
			const publicationUrl = `https://www.softwarecatalogus.nl/publicatie/${organisation.id}`
			window.open(publicationUrl, '_blank')
		},
		goToOrganisation(organisation) {
			if (organisation.website) {
				let websiteUrl = organisation.website
				// Add https:// if no protocol is specified
				if (!websiteUrl.startsWith('http://') && !websiteUrl.startsWith('https://')) {
					websiteUrl = 'https://' + websiteUrl
				}
				window.open(websiteUrl, '_blank')
			}
		},
		showSuccessMessage(message) {
			// Implementation would depend on your notification system
			// TODO: Integrate with Nextcloud notification system
		},
		showErrorMessage(message) {
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
	padding: 16px;
	margin-bottom: 20px;
	display: flex;
	align-items: center;
	justify-content: space-between;
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

.defaultBadge, .activeBadge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 11px;
	font-weight: 600;
	text-transform: uppercase;
}

.defaultBadge {
	background: var(--color-warning);
	color: var(--color-primary-text);
}

.activeBadge {
	background: var(--color-success);
	color: white;
}

/* Cards styling */
.active-org-card {
	border: 2px solid var(--color-success) !important;
	background: var(--color-success-light) !important;
}

.organisationInfo {
	padding: 16px 0;
}

.description {
	color: var(--color-text-lighter);
	margin-bottom: 12px;
	font-style: italic;
}

.organisationStats {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.stat {
	display: flex;
	justify-content: space-between;
}

.statLabel {
	color: var(--color-text-lighter);
	font-size: 12px;
}

.statValue {
	font-weight: 600;
	font-size: 12px;
}

/* Table styling */
.active-org-row {
	background: var(--color-success-light) !important;
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
