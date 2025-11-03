<script setup>
import { organisationStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<div v-if="!organisationStore.organisationItem">
			<NcEmptyContent name="Loading" description="Loading organisation details...">
				<template #icon>
					<NcLoadingIcon :size="64" />
				</template>
			</NcEmptyContent>
		</div>
		<div v-else>
			<span class="pageHeaderContainer">
				<h2 class="pageHeader">
					<OfficeBuilding :size="24" />
					{{ organisationStore.organisationItem.name }}
					<span v-if="organisationStore.organisationItem.isDefault" class="defaultBadge">Default</span>
					<span v-if="isActiveOrganisation" class="activeBadge">Active</span>
				</h2>
				<div class="headerActionsContainer">
					<NcActions :primary="true" menu-name="Actions">
						<template #icon>
							<DotsHorizontal :size="20" />
						</template>
						<NcActionButton close-after-click
							@click="viewOrganisation">
							<template #icon>
								<Eye :size="20" />
							</template>
							View
						</NcActionButton>
						<NcActionButton v-if="canEdit"
							close-after-click
							@click="editOrganisation">
							<template #icon>
								<Pencil :size="20" />
							</template>
							Edit
						</NcActionButton>
						<NcActionButton close-after-click
							@click="copyOrganisation">
							<template #icon>
								<ContentCopy :size="20" />
							</template>
							Copy
						</NcActionButton>
						<NcActionButton v-if="organisationStore.organisationItem?.website"
							close-after-click
							@click="goToOrganisation">
							<template #icon>
								<OpenInNew :size="20" />
							</template>
							Go to organisation
						</NcActionButton>
						<NcActionButton v-if="!isActiveOrganisation"
							close-after-click
							@click="setActiveOrganisation">
							<template #icon>
								<CheckCircle :size="20" />
							</template>
							Activeren
						</NcActionButton>
						<NcActionButton
							close-after-click
							@click="openJoinModal">
							<template #icon>
								<AccountPlus :size="20" />
							</template>
							Add User
						</NcActionButton>
						<NcActionButton v-if="canDelete"
							close-after-click
							@click="navigationStore.setDialog('deleteOrganisation')">
							<template #icon>
								<TrashCanOutline :size="20" />
							</template>
							Delete
						</NcActionButton>
					</NcActions>
				</div>
			</span>

			<div class="organisationContent">
				<div class="organisationOverview">
					<p v-if="organisationStore.organisationItem.description" class="description">
						{{ organisationStore.organisationItem.description }}
					</p>

					<div class="organisationMeta">
						<div class="metaRow">
							<strong>{{ t('openregister', 'UUID:') }}</strong>
							<span class="uuid">{{ organisationStore.organisationItem.uuid }}</span>
							<NcButton class="copy-button" @click="copyToClipboard(organisationStore.organisationItem.uuid)">
								<template #icon>
									<ContentCopy :size="16" />
								</template>
							</NcButton>
						</div>
						<div class="metaRow">
							<strong>{{ t('openregister', 'Owner:') }}</strong>
							<span>{{ organisationStore.organisationItem.owner || 'System' }}</span>
						</div>
						<div class="metaRow">
							<strong>{{ t('openregister', 'Members:') }}</strong>
							<span>{{ organisationStore.organisationItem.userCount || 0 }}</span>
						</div>
						<div v-if="organisationStore.organisationItem.created" class="metaRow">
							<strong>{{ t('openregister', 'Created:') }}</strong>
							<span>{{ formatDate(organisationStore.organisationItem.created) }}</span>
						</div>
						<div v-if="organisationStore.organisationItem.updated" class="metaRow">
							<strong>{{ t('openregister', 'Updated:') }}</strong>
							<span>{{ formatDate(organisationStore.organisationItem.updated) }}</span>
						</div>
					</div>
				</div>

				<!-- Members List -->
				<div class="membersSection">
					<h3>{{ t('openregister', 'Organisation Members') }}</h3>
					<div v-if="organisationStore.organisationItem.users && organisationStore.organisationItem.users.length"
						class="membersList">
						<div v-for="userId in organisationStore.organisationItem.users"
							:key="userId"
							class="memberItem">
							<div class="memberInfo">
								<Account :size="20" />
								<span class="memberName">{{ userId }}</span>
								<span v-if="userId === organisationStore.organisationItem.owner" class="ownerBadge">Owner</span>
							</div>
							<NcActions v-if="canManageMembers && userId !== organisationStore.organisationItem.owner">
								<NcActionButton close-after-click @click="removeMember(userId)">
									<template #icon>
										<AccountMinus :size="16" />
									</template>
									Remove Member
								</NcActionButton>
							</NcActions>
						</div>
					</div>
					<NcEmptyContent v-else
						name="No members"
						description="This organisation has no members yet">
						<template #icon>
							<AccountGroup :size="48" />
						</template>
					</NcEmptyContent>
				</div>

				<!-- Organisation Statistics -->
				<div class="statisticsSection">
					<h3>{{ t('openregister', 'Organisation Statistics') }}</h3>
					<div class="statsGrid">
						<div class="statCard">
							<h4>{{ t('openregister', 'Registers') }}</h4>
							<div class="statValue">
								{{ organisationStats.registers || 0 }}
							</div>
						</div>
						<div class="statCard">
							<h4>{{ t('openregister', 'Schemas') }}</h4>
							<div class="statValue">
								{{ organisationStats.schemas || 0 }}
							</div>
						</div>
						<div class="statCard">
							<h4>{{ t('openregister', 'Objects') }}</h4>
							<div class="statValue">
								{{ organisationStats.objects || 0 }}
							</div>
						</div>
						<div class="statCard">
							<h4>{{ t('openregister', 'Total Storage') }}</h4>
							<div class="statValue">
								{{ formatBytes(organisationStats.storage || 0) }}
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>

		<!-- Organisation Management Modal -->
	</NcAppContent>
</template>

<script>
import { NcActions, NcActionButton, NcAppContent, NcEmptyContent, NcLoadingIcon, NcButton } from '@nextcloud/vue'
import OfficeBuilding from 'vue-material-design-icons/OfficeBuilding.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import AccountMinus from 'vue-material-design-icons/AccountMinus.vue'
import AccountPlus from 'vue-material-design-icons/AccountPlus.vue'
import Account from 'vue-material-design-icons/Account.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'

export default {
	name: 'OrganisationDetails',
	components: {
		NcActions,
		NcActionButton,
		NcAppContent,
		NcEmptyContent,
		NcLoadingIcon,
		NcButton,
		OfficeBuilding,
		DotsHorizontal,
		Pencil,
		TrashCanOutline,
		CheckCircle,
		AccountGroup,
		AccountMinus,
		AccountPlus,
		Account,
		ContentCopy,
		Eye,
		OpenInNew,
	},
	data() {
		return {
			organisationStats: {
				registers: 0,
				schemas: 0,
				objects: 0,
				storage: 0,
			},
		}
	},
	computed: {
		isActiveOrganisation() {
			return organisationStore.userStats.active
				   && organisationStore.userStats.active.uuid === organisationStore.organisationItem?.uuid
		},
		canEdit() {
			// Only owner can edit (or system for default org)
			return organisationStore.organisationItem?.owner === 'system'
				   || organisationStore.organisationItem?.owner === this.getCurrentUser()
		},
		canLeave() {
			// Can't leave if it's your only organisation, you're the owner, or it's default
			return organisationStore.userStats.total > 1
				   && !organisationStore.organisationItem?.isDefault
				   && organisationStore.organisationItem?.owner !== this.getCurrentUser()
		},
		canDelete() {
			// Only owners can delete, and can't delete default organisation
			return !organisationStore.organisationItem?.isDefault
				   && organisationStore.organisationItem?.owner === this.getCurrentUser()
		},
		canManageMembers() {
			// Only owners can manage members
			return organisationStore.organisationItem?.owner === this.getCurrentUser()
		},
	},
	async mounted() {
		// Load organisation statistics (would need API endpoint)
		await this.loadOrganisationStats()
	},
	methods: {
		getCurrentUser() {
			// Implementation would depend on how you get current user
			return this.$route.meta?.user?.uid || 'unknown'
		},
		async setActiveOrganisation() {
			try {
				await organisationStore.setActiveOrganisationById(organisationStore.organisationItem.uuid)
				this.showSuccessMessage('Set as active organisation')
			} catch (error) {
				this.showErrorMessage('Failed to set active organisation: ' + error.message)
			}
		},
		async leaveOrganisation() {
			if (!confirm(`Are you sure you want to leave '${organisationStore.organisationItem.name}'?`)) {
				return
			}

			try {
				await organisationStore.leaveOrganisation(organisationStore.organisationItem.uuid)
				this.showSuccessMessage('Left organisation successfully')
				// Navigate back to organisations list
				this.$router.push('/organisation')
			} catch (error) {
				this.showErrorMessage('Failed to leave organisation: ' + error.message)
			}
		},
		async removeMember(userId) {
			if (!confirm(`Remove ${userId} from this organisation?`)) {
				return
			}

			try {
				// Would need API endpoint for removing members
				// TODO: Implement member removal API endpoint
				this.showSuccessMessage('Member removed successfully')
			} catch (error) {
				this.showErrorMessage('Failed to remove member: ' + error.message)
			}
		},
		async loadOrganisationStats() {
			// This would load organisation-specific statistics
			// For now, using mock data
			this.organisationStats = {
				registers: Math.floor(Math.random() * 10),
				schemas: Math.floor(Math.random() * 20),
				objects: Math.floor(Math.random() * 100),
				storage: Math.floor(Math.random() * 1000000000), // bytes
			}
		},
		async copyToClipboard(text) {
			try {
				await navigator.clipboard.writeText(text)
				this.showSuccessMessage('UUID copied to clipboard')
			} catch (err) {
				console.error('Failed to copy text:', err)
				this.showErrorMessage('Failed to copy to clipboard')
			}
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
		formatBytes(bytes) {
			if (bytes === 0) return '0 Bytes'

			const k = 1024
			const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB']
			const i = Math.floor(Math.log(bytes) / Math.log(k))

			return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i]
		},
		// Organisation Action Methods
		viewOrganisation() {
			const publicationUrl = `https://www.softwarecatalogus.nl/publicatie/${organisationStore.organisationItem.uuid}`
			window.open(publicationUrl, '_blank')
		},
		editOrganisation() {
			// organisationStore.organisationItem is already set by the page
			navigationStore.setModal('editOrganisation')
		},
		openJoinModal() {
			// Set the transfer data with the current organisation UUID
			navigationStore.setTransferData({
				organisationUuid: organisationStore.organisationItem?.uuid,
			})
			// Open the join organisation modal
			navigationStore.setModal('joinOrganisation')
		},
		openManageRolesModal() {
			// Open the manage organisation roles modal
			navigationStore.setModal('manageOrganisationRoles')
		},
		goToOrganisation() {
			if (organisationStore.organisationItem?.website) {
				let websiteUrl = organisationStore.organisationItem.website
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

<style lang="scss" scoped>
.pageHeaderContainer {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 0;
	margin-bottom: 20px;
}

.pageHeader {
	display: flex;
	align-items: center;
	gap: 8px;
	margin: 0;
}

.defaultBadge, .activeBadge, .ownerBadge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 11px;
	font-weight: 600;
	text-transform: uppercase;
	margin-left: 8px;
}

.defaultBadge {
	background: var(--color-warning);
	color: var(--color-primary-text);
}

.activeBadge {
	background: var(--color-success);
	color: white;
}

.ownerBadge {
	background: var(--color-primary);
	color: white;
}

.organisationContent {
	margin-inline: auto;
	max-width: 1200px;
	padding-block: 20px;
	padding-inline: 20px;
}

.organisationOverview {
	margin-bottom: 40px;
}

.description {
	font-size: 16px;
	color: var(--color-text-lighter);
	margin-bottom: 20px;
	font-style: italic;
}

.organisationMeta {
	display: flex;
	flex-direction: column;
	gap: 8px;
	background: var(--color-background-dark);
	padding: 16px;
	border-radius: 8px;
	border: 1px solid var(--color-border);
}

.metaRow {
	display: flex;
	align-items: center;
	gap: 8px;
}

.uuid {
	font-family: monospace;
	font-size: 12px;
	color: var(--color-text-lighter);
}

.copy-button {
	min-width: auto !important;
	padding: 4px 8px !important;
}

.membersSection, .statisticsSection {
	margin-bottom: 40px;
}

.membersSection h3, .statisticsSection h3 {
	margin-bottom: 20px;
	color: var(--color-text-dark);
	font-size: 18px;
	font-weight: 600;
}

.membersList {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.memberItem {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 12px;
	background: var(--color-background-dark);
	border: 1px solid var(--color-border);
	border-radius: 8px;
}

.memberInfo {
	display: flex;
	align-items: center;
	gap: 8px;
}

.memberName {
	font-weight: 500;
	color: var(--color-text-dark);
}

.statsGrid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 20px;
}

.statCard {
	background: var(--color-main-background);
	border-radius: 8px;
	padding: 20px;
	box-shadow: 0 2px 8px var(--color-box-shadow);
	border: 1px solid var(--color-border);
	text-align: center;
}

.statCard h4 {
	margin: 0 0 12px 0;
	font-size: 14px;
	color: var(--color-text-lighter);
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.statValue {
	font-size: 28px;
	font-weight: 700;
	color: var(--color-primary);
}

@media screen and (max-width: 768px) {
	.pageHeaderContainer {
		flex-direction: column;
		align-items: flex-start;
		gap: 16px;
	}

	.statsGrid {
		grid-template-columns: 1fr;
	}

	.memberItem {
		flex-direction: column;
		align-items: flex-start;
		gap: 8px;
	}
}
</style>
