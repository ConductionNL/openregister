<script setup>
import { translate as t } from '@nextcloud/l10n'
import { organisationStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<CnIndexPage
			ref="indexPage"
			:title="t('openregister', 'Organisations')"
			:description="
				t(
					'openregister',
					'Manage your organisations and switch between them',
				)
			"
			:show-title="true"
			:objects="paginatedOrganisations"
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
			show-view-toggle
			:add-label="t('openregister', 'Create Organisation')"
			row-key="uuid"
			:empty-text="emptyContentName"
			:row-class="getRowClass"
			:refreshing="isRefreshing"
			@add="createOrganisation"
			@refresh="handleRefresh"
			@page-changed="onPageChanged"
			@page-size-changed="onPageSizeChanged"
			@view-mode-change="organisationStore.setViewMode($event)"
			@select="onSelect">
			<!-- Active Organisation Status -->
			<template #below-header>
				<div
					v-if="organisationStore.userStats.active"
					class="activeOrgBanner">
					<div class="activeOrgInfo">
						<span class="activeOrgLabel">{{
							t('openregister', 'Active Organisation:')
						}}</span>
						<span class="activeOrgName">{{
							organisationStore.userStats.active.name
						}}</span>
						<span
							v-if="organisationStore.userStats.active.isDefault"
							class="defaultBadge">
							{{ t('openregister', 'Default') }}
						</span>
					</div>
					<NcButton
						v-if="organisationStore.userStats.total > 1"
						variant="secondary"
						@click="showOrganisationSwitcher = true">
						<template #icon>
							<SwapHorizontal :size="20" />
						</template>
						{{ t('openregister', 'Switch Organisation') }}
					</NcButton>
				</div>
			</template>

			<!-- Custom action items in actions bar -->
			<template #action-items>
				<NcActionButton
					close-after-click
					@click="navigationStore.setModal('joinOrganisation')">
					<template #icon>
						<AccountPlus :size="20" />
					</template>
					{{ t('openregister', 'Add User to Organisation') }}
				</NcActionButton>
			</template>

			<!-- Custom card template -->
			<template #card="{ object }">
				<div
					class="card"
					:class="{ 'active-org-card': isActiveOrganisation(object) }">
					<div class="cardHeader">
						<h2>
							<OfficeBuilding :size="20" />
							{{ object.name }}
							<span v-if="object.isDefault" class="defaultBadge">{{
								t('openregister', 'Default')
							}}</span>
							<span
								v-if="isActiveOrganisation(object)"
								class="activeBadge"
								>{{ t('openregister', 'Active') }}</span
							>
						</h2>
						<NcActions
							:primary="true"
							:menu-name="t('openregister', 'Actions')">
							<template #icon>
								<DotsHorizontal :size="20" />
							</template>
							<NcActionButton
								close-after-click
								@click="viewOrganisation(object)">
								<template #icon>
									<Eye :size="20" />
								</template>
								{{ t('openregister', 'View') }}
							</NcActionButton>
							<NcActionButton
								v-if="!isActiveOrganisation(object)"
								close-after-click
								@click="setActiveOrganisation(object.uuid)">
								<template #icon>
									<Check :size="20" />
								</template>
								{{ t('openregister', 'Set as Active') }}
							</NcActionButton>
							<NcActionButton
								v-if="canEditOrganisation(object)"
								close-after-click
								@click="editOrganisation(object)">
								<template #icon>
									<Pencil :size="20" />
								</template>
								{{ t('openregister', 'Edit') }}
							</NcActionButton>
							<NcActionButton
								v-if="object.website"
								close-after-click
								@click="goToOrganisation(object)">
								<template #icon>
									<OpenInNew :size="20" />
								</template>
								{{ t('openregister', 'Go to organisation') }}
							</NcActionButton>
							<NcActionButton
								close-after-click
								@click="openJoinModal(object)">
								<template #icon>
									<AccountMultiplePlus :size="20" />
								</template>
								{{ t('openregister', 'Add User') }}
							</NcActionButton>
							<NcActionButton
								v-if="canDeleteOrganisation(object)"
								close-after-click
								@click="
									organisationStore.setOrganisationItem(object)
									navigationStore.setModal('deleteOrganisation')
								">
								<template #icon>
									<TrashCanOutline :size="20" />
								</template>
								{{ t('openregister', 'Delete') }}
							</NcActionButton>
						</NcActions>
					</div>

					<div class="organisationInfo">
						<p v-if="object.description" class="description">
							{{ object.description }}
						</p>
						<div class="organisationStats">
							<div class="stat">
								<span class="statLabel">{{
									t('openregister', 'Members:')
								}}</span>
								<span class="statValue">{{
									object.users?.length || 0
								}}</span>
							</div>
							<div class="stat">
								<span class="statLabel">{{
									t('openregister', 'Owner:')
								}}</span>
								<span class="statValue">{{
									object.owner || 'System'
								}}</span>
							</div>
							<div v-if="object.created" class="stat">
								<span class="statLabel">{{
									t('openregister', 'Created:')
								}}</span>
								<span class="statValue">{{
									formatDate(object.created)
								}}</span>
							</div>
						</div>
					</div>
				</div>
			</template>

			<!-- Custom column: name with badges -->
			<template #column-name="{ row }">
				<div class="titleContent">
					<div class="titleWithBadges">
						<strong>{{ row.name }}</strong>
						<span v-if="row.isDefault" class="defaultBadge">{{
							t('openregister', 'Default')
						}}</span>
						<span v-if="isActiveOrganisation(row)" class="activeBadge">{{
							t('openregister', 'Active')
						}}</span>
					</div>
					<span
						v-if="row.description"
						class="textDescription textEllipsis"
						>{{ row.description }}</span
					>
				</div>
			</template>

			<!-- Custom column: member count -->
			<template #column-users="{ row }">
				{{ row.users?.length || 0 }}
			</template>

			<!-- Custom column: owner -->
			<template #column-owner="{ row }">
				{{ row.owner || 'System' }}
			</template>

			<!-- Custom column: active/inactive status -->
			<template #column-status="{ row }">
				<span v-if="isActiveOrganisation(row)" class="statusActive">{{
					t('openregister', 'Active')
				}}</span>
				<span v-else class="statusInactive">{{
					t('openregister', 'Inactive')
				}}</span>
			</template>

			<!-- Custom column: created date -->
			<template #column-created="{ row }">
				{{ row.created ? formatDate(row.created) : '-' }}
			</template>

			<!-- Custom column: updated date -->
			<template #column-updated="{ row }">
				{{ row.updated ? formatDate(row.updated) : '-' }}
			</template>

			<!-- Custom row actions for table view -->
			<template #row-actions="{ row }">
				<NcActions :primary="false">
					<template #icon>
						<DotsHorizontal :size="20" />
					</template>
					<NcActionButton close-after-click @click="viewOrganisation(row)">
						<template #icon>
							<Eye :size="20" />
						</template>
						{{ t('openregister', 'View') }}
					</NcActionButton>
					<NcActionButton
						v-if="!isActiveOrganisation(row)"
						close-after-click
						@click="setActiveOrganisation(row.uuid)">
						<template #icon>
							<Check :size="20" />
						</template>
						{{ t('openregister', 'Set as Active') }}
					</NcActionButton>
					<NcActionButton
						v-if="canEditOrganisation(row)"
						close-after-click
						@click="editOrganisation(row)">
						<template #icon>
							<Pencil :size="20" />
						</template>
						{{ t('openregister', 'Edit') }}
					</NcActionButton>
					<NcActionButton
						v-if="row.website"
						close-after-click
						@click="goToOrganisation(row)">
						<template #icon>
							<OpenInNew :size="20" />
						</template>
						{{ t('openregister', 'Go to organisation') }}
					</NcActionButton>
					<NcActionButton close-after-click @click="openJoinModal(row)">
						<template #icon>
							<AccountMultiplePlus :size="20" />
						</template>
						{{ t('openregister', 'Add User') }}
					</NcActionButton>
					<NcActionButton
						v-if="canDeleteOrganisation(row)"
						close-after-click
						@click="
							organisationStore.setOrganisationItem(row)
							navigationStore.setModal('deleteOrganisation')
						">
						<template #icon>
							<TrashCanOutline :size="20" />
						</template>
						{{ t('openregister', 'Delete') }}
					</NcActionButton>
				</NcActions>
			</template>
		</CnIndexPage>

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
import { NcAppContent, NcActions, NcActionButton, NcButton } from '@nextcloud/vue'
import { CnIndexPage } from '@conduction/nextcloud-vue'
import OfficeBuilding from 'vue-material-design-icons/OfficeBuilding.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import AccountPlus from 'vue-material-design-icons/AccountPlus.vue'
import AccountMultiplePlus from 'vue-material-design-icons/AccountMultiplePlus.vue'
import SwapHorizontal from 'vue-material-design-icons/SwapHorizontal.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'

import SwitchOrganisationModal from '../../modals/organisation/SwitchOrganisationModal.vue'
import { reloadAppData } from '../../services/AppInitializationService.js'
import Check from 'vue-material-design-icons/Check.vue'
import { showError, showSuccess } from '@nextcloud/dialogs'

export default {
	name: 'OrganisationsIndex',
	components: {
		NcAppContent,
		CnIndexPage,
		NcActions,
		NcActionButton,
		NcButton,
		OfficeBuilding,
		DotsHorizontal,
		Pencil,
		TrashCanOutline,
		AccountPlus,
		AccountMultiplePlus,
		SwapHorizontal,
		Eye,
		OpenInNew,
		Check,
		SwitchOrganisationModal,
	},
	data() {
		return {
			selectedOrganisations: [],
			showOrganisationSwitcher: false,
			isRefreshing: false,
		}
	},
	computed: {
		/**
		 * Column definitions for the organisations table.
		 *
		 * @spec exclude UI plumbing — static table column list for display.
		 * @return {Array<object>}
		 */
		tableColumns() {
			return [
				{ key: 'name', label: t('openregister', 'Name'), sortable: true },
				{ key: 'users', label: t('openregister', 'Members') },
				{ key: 'owner', label: t('openregister', 'Owner') },
				{ key: 'status', label: t('openregister', 'Status') },
				{
					key: 'created',
					label: t('openregister', 'Created'),
					sortable: true,
				},
				{
					key: 'updated',
					label: t('openregister', 'Updated'),
					sortable: true,
				},
			]
		},
		/**
		 * Pagination state derived from the organisation store, for display.
		 *
		 * @spec exclude UI plumbing — derived pagination view state; admin list contract owned by admin-list-views.
		 * @return {object}
		 */
		paginationData() {
			const page = organisationStore.pagination.page || 1
			const limit = organisationStore.pagination.limit || 20
			const total = organisationStore.userStats.total || 0
			const pages = Math.ceil(total / limit)
			return { page, pages, total, limit }
		},
		/**
		 * Current page slice of the organisation list. The full list is loaded
		 * client-side, so CnIndexPage (prop mode) does not slice — we slice here
		 * so paging works.
		 *
		 * @spec exclude UI plumbing — client-side pagination computed; admin list contract owned by admin-list-views.
		 * @return {Array}
		 */
		paginatedOrganisations() {
			const { page, limit } = this.paginationData
			const start = (page - 1) * limit
			return organisationStore.userStats.list.slice(start, start + limit)
		},
		/**
		 * Empty-state title shown when the organisation list is empty.
		 *
		 * @spec exclude UI plumbing — derived empty-state copy, no observable contract.
		 * @return {string}
		 */
		emptyContentName() {
			if (!organisationStore.userStats.total) {
				return t('openregister', 'No organisations found')
			}
			return t('openregister', 'Loading organisations...')
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
		 * Reload the organisation list from the store.
		 *
		 * @spec exclude UI plumbing — refresh button delegates to the store.
		 * @return {Promise<void>}
		 */
		async handleRefresh() {
			this.isRefreshing = true
			try {
				await organisationStore.refreshOrganisationList()
			} finally {
				this.isRefreshing = false
			}
		},
		/**
		 * Compute the CSS row class marking the active organisation.
		 *
		 * @spec exclude UI plumbing — derived row-styling helper.
		 * @param {object} organisation - organisation row
		 * @return {string}
		 */
		getRowClass(organisation) {
			return this.isActiveOrganisation(organisation)
				? 'viewTableRow--active'
				: ''
		},
		/**
		 * Whether an organisation is the active one.
		 *
		 * @spec exclude UI plumbing — display predicate; tenant contract owned by tenant-lifecycle.
		 * @param {object} organisation - organisation row
		 * @return {boolean}
		 */
		isActiveOrganisation(organisation) {
			return (
				organisationStore.userStats.active
				&& organisationStore.userStats.active.uuid === organisation.uuid
			)
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
			return (
				organisation.owner === 'system'
				|| organisation.owner === this.getCurrentUser()
			)
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
			return (
				organisationStore.userStats.total > 1
				&& !organisation.isDefault
				&& organisation.owner !== this.getCurrentUser()
			)
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
			return (
				!organisation.isDefault
				&& organisation.owner === this.getCurrentUser()
			)
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
				showSuccess(
					t('openregister', 'Active organisation changed successfully'),
				)

				// Reload all hot-loaded data for the new organisation context
				await reloadAppData()
			} catch (error) {
				showError(
					t(
						'openregister',
						'Failed to change active organisation: {error}',
						{ error: error.message },
					),
				)
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
				showError(
					t('openregister', 'Failed to switch organisation: {error}', {
						error: error.message,
					}),
				)
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
			if (
				!confirm(
					t('openregister', "Are you sure you want to leave '{name}'?", {
						name: organisation.name,
					}),
				)
			) {
				return
			}

			try {
				await organisationStore.leaveOrganisation(organisation.uuid)
				showSuccess(t('openregister', 'Left organisation successfully'))
			} catch (error) {
				showError(
					t('openregister', 'Failed to leave organisation: {error}', {
						error: error.message,
					}),
				)
			}
		},
		/**
		 * Track the selected organisation uuids for bulk actions.
		 *
		 * @spec exclude UI plumbing — row-selection state mutation; admin list contract owned by admin-list-views.
		 * @param {Array} ids - selected organisation uuids
		 * @return {void}
		 */
		onSelect(ids) {
			this.selectedOrganisations = ids
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
			return (
				new Date(dateString).toLocaleDateString({
					day: '2-digit',
					month: '2-digit',
					year: 'numeric',
				})
				+ ', '
				+ new Date(dateString).toLocaleTimeString({
					hour: '2-digit',
					minute: '2-digit',
				})
			)
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
				if (
					!websiteUrl.startsWith('http://')
					&& !websiteUrl.startsWith('https://')
				) {
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
/* Table row accent for the active organisation. Drawn with an inset box-shadow,
   never border-left: a border adds layout width and shifts the row's cell
   content sideways, while box-shadow paints inside the box. Matches
   .cn-table-row--selected.

   Skipped on a selected row so the library's .cn-table-row--selected accent
   wins — scoping adds a [data-v-*] attribute, which would otherwise outweigh
   the library's single-class rule. */
:deep(.viewTableRow--active:not(.cn-table-row--selected)) {
	box-shadow: inset 3px 0 0 0 var(--color-success);
}

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

.defaultBadge,
.activeBadge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 11px;
	font-weight: 600;
	text-transform: uppercase;
	white-space: nowrap;
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

/* Title with badges layout */
.titleWithBadges {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
	margin-bottom: 4px;
}

.statusActive {
	color: var(--color-success);
	font-weight: 600;
}

.statusInactive {
	color: var(--color-text-lighter);
}
</style>
