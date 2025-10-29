<script setup>
import { organisationStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog
		name="Join Organisation"
		size="normal"
		:can-close="true"
		@update:open="handleDialogClose">
		<NcNoteCard v-if="success" type="success">
			<p>Successfully joined organisation: {{ joinedOrganisationName }}</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<div v-if="!success">
			<div class="search-section">
				<NcTextField
					:disabled="loading"
					label="Search Organisations"
					:value.sync="searchQuery"
					placeholder="Enter organisation name to search"
					@update:value="handleSearchInput" />

				<div class="search-help">
					<NcNoteCard type="info">
						<p>Search for organisations by name to join them. Contact the organisation owner if you need an invitation.</p>
					</NcNoteCard>
				</div>
			</div>

			<!-- Search Results -->
			<div v-if="searchResults.length > 0" class="search-results">
				<h3>{{ t('openregister', 'Available Organisations') }}</h3>
				<div class="organisation-list">
					<div v-for="organisation in searchResults"
						:key="organisation.uuid"
						class="organisation-item"
						:class="{ 'already-member': isAlreadyMember(organisation) }">
						<div class="organisation-info">
							<div class="organisation-header">
								<h4>{{ organisation.name }}</h4>
								<span v-if="organisation.isDefault" class="defaultBadge">Default</span>
							</div>
							<p v-if="organisation.description" class="organisation-description">
								{{ organisation.description }}
							</p>
							<div class="organisation-meta">
								<span class="member-count">{{ organisation.userCount || 0 }} members</span>
								<span v-if="organisation.created" class="created-date">
									Created {{ formatDate(organisation.created) }}
								</span>
							</div>
						</div>
						<div class="organisation-actions">
							<NcButton v-if="isAlreadyMember(organisation)"
								type="secondary"
								disabled>
								Already Member
							</NcButton>
							<NcButton v-else
								:disabled="joiningUuid === organisation.uuid"
								type="primary"
								@click="joinOrganisation(organisation)">
								<template #icon>
									<NcLoadingIcon v-if="joiningUuid === organisation.uuid" :size="20" />
									<AccountPlus v-else :size="20" />
								</template>
								Join
							</NcButton>
						</div>
					</div>
				</div>
			</div>

			<!-- No Results -->
			<div v-else-if="searchQuery.trim() && !searchLoading && hasSearched" class="no-results">
				<NcEmptyContent name="No organisations found"
					:description="`No organisations found matching '${searchQuery}'`">
					<template #icon>
						<Magnify :size="64" />
					</template>
				</NcEmptyContent>
			</div>

			<!-- Search Loading -->
			<div v-if="searchLoading" class="search-loading">
				<NcLoadingIcon :size="32" />
				<span>Searching organisations...</span>
			</div>
		</div>

		<template #actions>
			<NcButton @click="closeModal">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ success ? 'Close' : 'Cancel' }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcDialog,
	NcTextField,
	NcLoadingIcon,
	NcNoteCard,
	NcEmptyContent,
} from '@nextcloud/vue'

import AccountPlus from 'vue-material-design-icons/AccountPlus.vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'

export default {
	name: 'JoinOrganisation',
	components: {
		NcDialog,
		NcTextField,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcEmptyContent,
		// Icons
		AccountPlus,
		Cancel,
		Magnify,
	},
	data() {
		return {
			searchQuery: '',
			searchResults: [],
			searchLoading: false,
			hasSearched: false,
			success: false,
			loading: false,
			error: false,
			joiningUuid: null, // Track which organisation is being joined
			joinedOrganisationName: '',
			searchTimeout: null,
			closeModalTimeout: null,
		}
	},
	methods: {
		handleSearchInput(value) {
			this.searchQuery = value

			// Clear previous timeout
			if (this.searchTimeout) {
				clearTimeout(this.searchTimeout)
			}

			// Debounce search
			this.searchTimeout = setTimeout(() => {
				if (value.trim().length >= 2) {
					this.searchOrganisations()
				} else {
					this.searchResults = []
					this.hasSearched = false
				}
			}, 500)
		},
		async searchOrganisations() {
			if (!this.searchQuery.trim()) {
				return
			}

			this.searchLoading = true
			this.error = null

			try {
				const results = await organisationStore.searchOrganisations(this.searchQuery)
				this.searchResults = results
				this.hasSearched = true
			} catch (error) {
				console.error('Error searching organisations:', error)
				this.error = 'Failed to search organisations: ' + error.message
				this.searchResults = []
			} finally {
				this.searchLoading = false
			}
		},
		isAlreadyMember(organisation) {
			// Check if user is already a member of this organisation
			return organisationStore.userStats.list.some(userOrg =>
				userOrg.uuid === organisation.uuid,
			)
		},
		async joinOrganisation(organisation) {
			this.joiningUuid = organisation.uuid
			this.error = null

			try {
				await organisationStore.joinOrganisation(organisation.uuid)

				this.success = true
				this.joinedOrganisationName = organisation.name

				// Clear search results and show success
				this.searchResults = []
				this.searchQuery = ''

				this.closeModalTimeout = setTimeout(this.closeModal, 3000)

			} catch (error) {
				console.error('Error joining organisation:', error)
				this.error = error.message || 'Failed to join organisation'
			} finally {
				this.joiningUuid = null
			}
		},
		formatDate(dateString) {
			return new Date(dateString).toLocaleDateString({
				day: '2-digit',
				month: '2-digit',
				year: 'numeric',
			})
		},
		closeModal() {
			this.success = false
			this.error = null
			this.searchQuery = ''
			this.searchResults = []
			this.hasSearched = false
			this.joiningUuid = null
			this.joinedOrganisationName = ''
			navigationStore.setModal(false)
			clearTimeout(this.closeModalTimeout)
		},
		handleDialogClose() {
			this.closeModal()
		},
	},
}
</script>

<style scoped>
/* JoinOrganisation-specific styles */
.search-section {
	margin-bottom: 24px;
}

.search-help {
	margin-top: 16px;
}

.search-results {
	margin-top: 24px;
}

.search-results h3 {
	margin-bottom: 16px;
	color: var(--color-text-dark);
	font-size: 16px;
	font-weight: 600;
}

.organisation-list {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-height: 400px;
	overflow-y: auto;
}

.organisation-item {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	padding: 16px;
	background: var(--color-background-dark);
	border: 1px solid var(--color-border);
	border-radius: 8px;
	transition: background-color 0.2s ease;
}

.organisation-item:hover {
	background: var(--color-background-hover);
}

.organisation-item.already-member {
	opacity: 0.7;
}

.organisation-info {
	flex: 1;
	margin-right: 16px;
}

.organisation-header {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 8px;
}

.organisation-header h4 {
	margin: 0;
	color: var(--color-text-dark);
	font-size: 16px;
	font-weight: 600;
}

.defaultBadge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 11px;
	font-weight: 600;
	text-transform: uppercase;
	background: var(--color-warning);
	color: var(--color-primary-text);
}

.organisation-description {
	color: var(--color-text-lighter);
	font-size: 14px;
	margin-bottom: 8px;
	line-height: 1.4;
}

.organisation-meta {
	display: flex;
	gap: 16px;
	font-size: 12px;
	color: var(--color-text-lighter);
}

.member-count {
	font-weight: 500;
}

.organisation-actions {
	flex-shrink: 0;
}

.search-loading {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 8px;
	padding: 32px;
	color: var(--color-text-lighter);
}

.no-results {
	padding: 32px 0;
}

@media screen and (max-width: 768px) {
	.organisation-item {
		flex-direction: column;
		align-items: stretch;
		gap: 16px;
	}

	.organisation-info {
		margin-right: 0;
	}

	.organisation-actions {
		align-self: flex-start;
	}
}
</style>
