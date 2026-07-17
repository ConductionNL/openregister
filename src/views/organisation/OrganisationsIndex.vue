<script setup>
import { translate as t } from '@nextcloud/l10n'
import { organisationStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<div class="viewContainer">
			<!-- Header -->
			<div class="viewHeader">
				<h1 class="viewHeaderTitleIndented">
					{{ t('openregister', 'Organisations') }}
				</h1>
				<p>{{ t('openregister', 'Manage your organisations and switch between them') }}</p>
			</div>

			<!-- Active Organisation Status -->
			<div v-if="organisationStore.userStats.active" class="activeOrgBanner">
				<div class="activeOrgInfo">
					<span class="activeOrgLabel">{{ t('openregister', 'Active Organisation:') }}</span>
					<span class="activeOrgName">{{ organisationStore.userStats.active.name }}</span>
					<span v-if="organisationStore.userStats.active.isDefault" class="defaultBadge">
						{{ t('openregister', 'Default') }}
					</span>
				</div>
				<NcButton v-if="organisationStore.userStats.total > 1"
					type="secondary"
					@click="showOrganisationSwitcher = true">
					<template #icon>
						<SwapHorizontal :size="20" />
					</template>
					{{ t('openregister', 'Switch Organisation') }}
				</NcButton>
			</div>

			<!-- Actions Bar -->
			<div class="viewActionsBar">
				<div class="viewInfo">
					<span class="viewTotalCount">
						{{ t('openregister', 'Showing {shown} of {total} organisations', { shown: paginatedOrganisations.length, total: organisationStore.userStats.total }) }}
					</span>
					<span v-if="selectedOrganisations.length > 0" class="viewIndicator">
						{{ t('openregister', '({count} selected)', { count: selectedOrganisations.length }) }}
					</span>
				</div>
				<div class="viewActions">
					<div class="viewModeSwitchContainer">
						<NcCheckboxRadioSwitch
							v-model="organisationStore.viewMode"
							v-tooltip="t('openregister', 'See organisations as cards')"
							:button-variant="true"
							value="cards"
							name="view_mode_radio"
							type="radio"
							button-variant-grouped="horizontal">
							{{ t('openregister', 'Cards') }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch
							v-model="organisationStore.viewMode"
							v-tooltip="t('openregister', 'See organisations as a table')"
							:button-variant="true"
							value="table"
							name="view_mode_radio"
							type="radio"
							button-variant-grouped="horizontal">
							{{ t('openregister', 'Table') }}
						</NcCheckboxRadioSwitch>
					</div>

					<NcActions
						:force-name="true"
						:inline="3"
						:menu-name="t('openregister', 'Actions')">
						<NcActionButton
							:primary="true"
							close-after-click
							@click="createOrganisation">
							<template #icon>
								<Plus :size="20" />
							</template>
							{{ t('openregister', 'Create Organisation') }}
						</NcActionButton>
						<NcActionButton
							close-after-click
							@click="navigationStore.setModal('joinOrganisation')">
							<template #icon>
								<AccountPlus :size="20" />
							</template>
							{{ t('openregister', 'Add User to Organisation') }}
						</NcActionButton>
						<NcActionButton
							close-after-click
							@click="organisationStore.refreshOrganisationList()">
							<template #icon>
								<Refresh :size="20" />
							</template>
							{{ t('openregister', 'Refresh') }}
						</NcActionButton>
					</NcActions>
				</div>
			</div>

			<!-- Empty State -->
			<NcEmptyContent v-if="!organisationStore.userStats.total"
				:name="t('openregister', 'No organisations found')"
				:description="t('openregister', 'You are not yet a member of any organisations.')">
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
									<span v-if="organisation.isDefault" class="defaultBadge">{{ t('openregister', 'Default') }}</span>
									<span v-if="isActiveOrganisation(organisation)" class="activeBadge">{{ t('openregister', 'Active') }}</span>
								</h2>
								<NcActions :primary="true" :menu-name="t('openregister', 'Actions')">
									<template #icon>
										<DotsHorizontal :size="20" />
									</template>
									<NcActionButton close-after-click
										@click="viewOrganisation(organisation)">
										<template #icon>
											<Eye :size="20" />
										</template>
										{{ t('openregister', 'View') }}
									</NcActionButton>
									<NcActionButton v-if="!isActiveOrganisation(organisation)"
										close-after-click
										@click="setActiveOrganisation(organisation.uuid)">
										<template #icon>
											<Check :size="20" />
										</template>
										{{ t('openregister', 'Set as Active') }}
									</NcActionButton>
									<NcActionButton v-if="canEditOrganisation(organisation)"
										close-after-click
										@click="editOrganisation(organisation)">
										<template #icon>
											<Pencil :size="20" />
										</template>
										{{ t('openregister', 'Edit') }}
									</NcActionButton>
									<NcActionButton v-if="organisation.website"
										close-after-click
										@click="goToOrganisation(organisation)">
										<template #icon>
											<OpenInNew :size="20" />
										</template>
										{{ t('openregister', 'Go to organisation') }}
									</NcActionButton>
									<NcActionButton
										close-after-click
										@click="openJoinModal(organisation)">
										<template #icon>
											<AccountMultiplePlus :size="20" />
										</template>
										{{ t('openregister', 'Add User') }}
									</NcActionButton>
									<NcActionButton v-if="canDeleteOrganisation(organisation)"
										close-after-click
										@click="organisationStore.setOrganisationItem(organisation); navigationStore.setModal('deleteOrganisation')">
										<template #icon>
											<TrashCanOutline :size="20" />
										</template>
										{{ t('openregister', 'Delete') }}
									</NcActionButton>
								</NcActions>
							</div>

							<div class="organisationInfo">
								<p v-if="organisation.description" class="description">
									{{ organisation.description }}
								</p>
								<div class="organisationStats">
									<div class="stat">
										<span class="statLabel">{{ t('openregister', 'Members:') }}</span>
										<span class="statValue">{{ organisation.users?.length || 0 }}</span>
									</div>
									<div class="stat">
										<span class="statLabel">{{ t('openregister', 'Owner:') }}</span>
										<span class="statValue">{{ organisation.owner || 'System' }}</span>
									</div>
									<div v-if="organisation.created" class="stat">
										<span class="statLabel">{{ t('openregister', 'Created:') }}</span>
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
									<th>{{ t('openregister', 'Name') }}</th>
									<th>{{ t('openregister', 'Members') }}</th>
									<th>{{ t('openregister', 'Owner') }}</th>
									<th>{{ t('openregister', 'Status') }}</th>
									<th>{{ t('openregister', 'Created') }}</th>
									<th>{{ t('openregister', 'Updated') }}</th>
									<th class="tableColumnActions">
										{{ t('openregister', 'Actions') }}
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
												<span v-if="organisation.isDefault" class="defaultBadge">{{ t('openregister', 'Default') }}</span>
												<span v-if="isActiveOrganisation(organisation)" class="activeBadge">{{ t('openregister', 'Active') }}</span>
											</div>
											<span v-if="organisation.description" class="textDescription textEllipsis">{{ organisation.description }}</span>
										</div>
									</td>
									<td>{{ organisation.users?.length || 0 }}</td>
									<td>{{ organisation.owner || 'System' }}</td>
									<td>
										<span v-if="isActiveOrganisation(organisation)" class="statusActive">{{ t('openregister', 'Active') }}</span>
										<span v-else class="statusInactive">{{ t('openregister', 'Inactive') }}</span>
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
												{{ t('openregister', 'View') }}
											</NcActionButton>
											<NcActionButton v-if="!isActiveOrganisation(organisation)"
												close-after-click
												@click="setActiveOrganisation(organisation.uuid)">
												<template #icon>
													<Check :size="20" />
												</template>
												{{ t('openregister', 'Set as Active') }}
											</NcActionButton>
											<NcActionButton v-if="canEditOrganisation(organisation)"
												close-after-click
												@click="editOrganisation(organisation)">
												<template #icon>
													<Pencil :size="20" />
												</template>
												{{ t('openregister', 'Edit') }}
											</NcActionButton>
											<NcActionButton v-if="organisation.website"
												close-after-click
												@click="goToOrganisation(organisation)">
												<template #icon>
													<OpenInNew :size="20" />
												</template>
												{{ t('openregister', 'Go to organisation') }}
											</NcActionButton>
											<NcActionButton
												close-after-click
												@click="openJoinModal(organisation)">
												<template #icon>
													<AccountMultiplePlus :size="20" />
												</template>
												{{ t('openregister', 'Add User') }}
											</NcActionButton>
											<NcActionButton v-if="canDeleteOrganisation(organisation)"
												close-after-click
												@click="organisationStore.setOrganisationItem(organisation); navigationStore.setModal('deleteOrganisation')">
												<template #icon>
													<TrashCanOutline :size="20" />
												</template>
												{{ t('openregister', 'Delete') }}
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
		<SwitchOrganisationModal
			:show="showOrganisationSwitcher"
			:organisations="organisationStore.userStats.list"
			:active-organisation-uuid="organisationStore.userStats.active?.uuid"
			@close="showOrganisationSwitcher = false"
			@switch="switchToOrganisation" />

		<!-- Organisation Management Modal -->
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcEmptyContent, NcActions, NcActionButton, NcCheckboxRadioSwitch, NcButton } from '@nextcloud/vue'
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
import SwitchOrganisationModal from '../../modals/organisation/SwitchOrganisationModal.vue'
import { reloadAppData } from '../../services/AppInitializationService.js'
import Check from 'vue-material-design-icons/Check.vue'
import { showError, showSuccess } from '@nextcloud/dialogs'

export default {
	name: 'OrganisationsIndex',
	components: {
		NcCheckboxRadioSwitch,
		NcAppContent,
		NcEmptyContent,
		NcActions,
		NcActionButton,
		NcButton,
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
		Check,
		PaginationComponent,
		SwitchOrganisationModal,
	},
	data() {
		return {
			selectedOrganisations: [],
			showOrganisationSwitcher: false,
		}
	},
	computed: {
		/**
		 * Whether every organisation is selected.
		 *
		 * @spec exclude UI plumbing — header-checkbox state; admin list contract owned by admin-list-views.
		 * @return {boolean}
		 */
		allSelected() {
			return organisationStore.userStats.list.length > 0 && organisationStore.userStats.list.every(org => this.selectedOrganisations.includes(org.uuid))
		},
		/**
		 * Whether a partial selection exists (indeterminate state).
		 *
		 * @spec exclude UI plumbing — header-checkbox indeterminate state; admin list contract owned by admin-list-views.
		 * @return {boolean}
		 */
		someSelected() {
			return this.selectedOrganisations.length > 0 && !this.allSelected
		},
		/**
		 * Current page slice of the organisation list.
		 *
		 * @spec exclude UI plumbing — client-side pagination computed; admin list contract owned by admin-list-views.
		 * @return {Array}
		 */
		paginatedOrganisations() {
			const start = ((organisationStore.pagination.page || 1) - 1) * (organisationStore.pagination.limit || 20)
			const end = start + (organisationStore.pagination.limit || 20)
			return organisationStore.userStats.list.slice(start, end)
		},
	},
	/**
	 * Load Nextcloud groups, organisations, and active org on mount.
	 *
	 * @spec exclude UI plumbing — lifecycle hook delegating to the store; tenant contract owned by tenant-lifecycle.
	 * @return {Promise<void>}
	 */
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
		/**
		 * Whether an organisation is the active one.
		 *
		 * @spec exclude UI plumbing — display predicate; tenant contract owned by tenant-lifecycle.
		 * @param {object} organisation - organisation row
		 * @return {boolean}
		 */
		isActiveOrganisation(organisation) {
			return organisationStore.userStats.active
				   && organisationStore.userStats.active.uuid === organisation.uuid
		},
		/**
		 * Resolve the current Nextcloud user id.
		 *
		 * @spec exclude UI plumbing — global OC accessor for permission display.
		 * @return {string} user id or 'unknown'
		 */
		getCurrentUser() {
			// Get current user from global OC object (Nextcloud's way)
			return window.OC?.getCurrentUser?.()?.uid || 'unknown'
		},
		/**
		 * Whether the current user may edit an organisation.
		 *
		 * @spec exclude UI plumbing — display permission predicate; tenant contract owned by tenant-lifecycle.
		 * @param {object} organisation - organisation row
		 * @return {boolean}
		 */
		canEditOrganisation(organisation) {
			// Only the owner can edit the organisation (or system for default org)
			return organisation.owner === 'system'
				   || organisation.owner === this.getCurrentUser()
		},
		/**
		 * Whether the current user may leave an organisation.
		 *
		 * @spec exclude UI plumbing — display permission predicate; tenant contract owned by tenant-lifecycle.
		 * @param {object} organisation - organisation row
		 * @return {boolean}
		 */
		canLeaveOrganisation(organisation) {
			// Can't leave if it's your only organisation or if you're the owner
			return organisationStore.userStats.total > 1
				   && !organisation.isDefault
				   && organisation.owner !== this.getCurrentUser()
		},
		/**
		 * Whether the current user may delete an organisation.
		 *
		 * @spec exclude UI plumbing — display permission predicate; tenant contract owned by tenant-lifecycle.
		 * @param {object} organisation - organisation row
		 * @return {boolean}
		 */
		canDeleteOrganisation(organisation) {
			// Only owners can delete, and can't delete default organisation
			return !organisation.isDefault
				   && organisation.owner === this.getCurrentUser()
		},
		/**
		 * Set the active organisation and reload app data.
		 *
		 * @spec exclude UI plumbing — store delegation + app reload; tenant switch contract owned by tenant-lifecycle.
		 * @param {string} uuid - organisation uuid
		 * @return {Promise<void>}
		 */
		async setActiveOrganisation(uuid) {
			try {
				await organisationStore.setActiveOrganisationById(uuid)
				showSuccess(t('openregister', 'Active organisation changed successfully'))

				// Reload all hot-loaded data for the new organisation context
				await reloadAppData()
			} catch (error) {
				showError(t('openregister', 'Failed to change active organisation: {error}', { error: error.message }))
			}
		},
		/**
		 * Switch to an organisation from the switcher modal.
		 *
		 * @spec exclude UI plumbing — delegates to setActiveOrganisation + closes modal; tenant switch owned by tenant-lifecycle.
		 * @param {object} organisation - organisation row
		 * @return {Promise<void>}
		 */
		async switchToOrganisation(organisation) {
			try {
				await this.setActiveOrganisation(organisation.uuid)
				this.showOrganisationSwitcher = false
			} catch (error) {
				showError(t('openregister', 'Failed to switch organisation: {error}', { error: error.message }))
			}
		},
		/**
		 * Leave an organisation after confirmation.
		 *
		 * @spec exclude UI plumbing — confirm + store delegation + toast; membership contract owned by tenant-lifecycle.
		 * @param {object} organisation - organisation row
		 * @return {Promise<void>}
		 */
		async leaveOrganisation(organisation) {
			if (!confirm(t('openregister', 'Are you sure you want to leave \'{name}\'?', { name: organisation.name }))) {
				return
			}

			try {
				await organisationStore.leaveOrganisation(organisation.uuid)
				showSuccess(t('openregister', 'Left organisation successfully'))
			} catch (error) {
				showError(t('openregister', 'Failed to leave organisation: {error}', { error: error.message }))
			}
		},
		/**
		 * Toggle selection state for every organisation.
		 *
		 * @spec exclude UI plumbing — bulk row-selection state; admin list contract owned by admin-list-views.
		 * @param {boolean} checked - true selects all, false clears
		 * @return {void}
		 */
		toggleSelectAll(checked) {
			if (checked) {
				this.selectedOrganisations = organisationStore.userStats.list.map(org => org.uuid)
			} else {
				this.selectedOrganisations = []
			}
		},
		/**
		 * Toggle selection for a single organisation row.
		 *
		 * @spec exclude UI plumbing — row-selection state mutation; admin list contract owned by admin-list-views.
		 * @param {string} orgUuid - organisation uuid
		 * @param {boolean} checked - selected state
		 * @return {void}
		 */
		toggleOrganisationSelection(orgUuid, checked) {
			if (checked) {
				this.selectedOrganisations.push(orgUuid)
			} else {
				this.selectedOrganisations = this.selectedOrganisations.filter(uuid => uuid !== orgUuid)
			}
		},
		/**
		 * Handle a page change from the paginator.
		 *
		 * @spec exclude UI plumbing — pagination state delegation; admin list contract owned by admin-list-views.
		 * @param {number} page - new page number
		 * @return {void}
		 */
		onPageChanged(page) {
			organisationStore.setPagination(page, organisationStore.pagination.limit)
		},
		/**
		 * Handle a page-size change from the paginator.
		 *
		 * @spec exclude UI plumbing — pagination state delegation; admin list contract owned by admin-list-views.
		 * @param {number} pageSize - new page size
		 * @return {void}
		 */
		onPageSizeChanged(pageSize) {
			organisationStore.setPagination(1, pageSize)
		},
		/**
		 * Format a date for display.
		 *
		 * @spec exclude UI plumbing — pure display formatter, no observable contract.
		 * @param {string} dateString - ISO date string
		 * @return {string} localized date/time
		 */
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
		/**
		 * Open the create-organisation modal.
		 *
		 * @spec exclude UI plumbing — store-set + modal dispatch; tenant CRUD owned by tenant-lifecycle.
		 * @return {void}
		 */
		createOrganisation() {
			organisationStore.setOrganisationItem(null)
			navigationStore.setModal('editOrganisation')
		},
		/**
		 * Open the edit-organisation modal for a row.
		 *
		 * @spec exclude UI plumbing — store-set + modal dispatch; tenant CRUD owned by tenant-lifecycle.
		 * @param {object} organisation - organisation row
		 * @return {void}
		 */
		editOrganisation(organisation) {
			organisationStore.setOrganisationItem(organisation)
			navigationStore.setModal('editOrganisation')
		},
		/**
		 * Open the join-organisation modal with transfer data.
		 *
		 * @spec exclude UI plumbing — transfer-data set + modal dispatch; membership contract owned by tenant-lifecycle.
		 * @param {object} organisation - organisation row
		 * @return {void}
		 */
		openJoinModal(organisation) {
			// Set the transfer data with the organisation UUID
			navigationStore.setTransferData({
				organisationUuid: organisation.uuid,
			})
			// Open the join organisation modal
			navigationStore.setModal('joinOrganisation')
		},
		/**
		 * Open the manage-roles modal for a row.
		 *
		 * @spec exclude UI plumbing — store-set + modal dispatch; role contract owned by tenant-lifecycle.
		 * @param {object} organisation - organisation row
		 * @return {void}
		 */
		openManageRolesModal(organisation) {
			// Set the organisation item in store
			organisationStore.setOrganisationItem(organisation)
			// Open the manage organisation roles modal
			navigationStore.setModal('manageOrganisationRoles')
		},
		// Organisation Action Methods
		/**
		 * Open the organisation's public catalogue page.
		 *
		 * @spec exclude UI plumbing — external window.open navigation, no observable contract.
		 * @param {object} organisation - organisation row
		 * @return {void}
		 */
		viewOrganisation(organisation) {
			const publicationUrl = `https://www.softwarecatalogus.nl/publicatie/${organisation.id}`
			window.open(publicationUrl, '_blank')
		},
		/**
		 * Open the organisation's website in a new tab.
		 *
		 * @spec exclude UI plumbing — external window.open navigation, no observable contract.
		 * @param {object} organisation - organisation row
		 * @return {void}
		 */
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
		// Notification methods removed - use showSuccess/showError directly from @nextcloud/dialogs
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
</style>
