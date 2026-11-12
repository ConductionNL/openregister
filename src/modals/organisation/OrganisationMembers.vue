<template>
	<div class="users-section">
		<div class="users-header">
			<NcButton
				v-if="organisation.uuid"
				variant="primary"
				:disabled="state.loading"
				@click="$emit('add')">
				<template #icon>
					<AccountPlus :size="20" />
				</template>
				{{ t('openregister', 'Add User') }}
			</NcButton>
		</div>

		<div v-if="state.loadingUsers" class="loading-users">
			<NcLoadingIcon :size="20" />
			<span>{{ t('openregister', 'Loading users...') }}</span>
		</div>

		<div v-else-if="members.length > 0" class="users-list">
			<h3>
				{{
					t('openregister', 'Members ({count})', {
						count: members.length,
					})
				}}
			</h3>
			<div class="user-items">
				<div v-for="userId in members" :key="userId" class="user-item">
					<div class="user-info">
						<AccountCircle :size="20" class="user-icon" />
						<span class="user-id">{{ userId }}</span>
						<span
							v-if="userId === organisation.owner"
							class="owner-badge"
							>{{ t('openregister', 'Owner') }}</span
						>
					</div>
					<NcButton
						v-if="userId !== organisation.owner"
						variant="tertiary"
						:disabled="state.loading || state.removingUser === userId"
						@click="$emit('remove', userId)">
						<template #icon>
							<NcLoadingIcon
								v-if="state.removingUser === userId"
								:size="16" />
							<AccountMinus v-else :size="16" />
						</template>
						{{ t('openregister', 'Remove') }}
					</NcButton>
				</div>
			</div>
		</div>

		<div v-else class="no-users">
			<p>
				{{ t('openregister', 'No users in this organisation.') }}
			</p>
		</div>

		<NcNoteCard v-if="!organisation.uuid" type="warning">
			<p>
				{{
					t('openregister', 'Save the organisation first to manage users.')
				}}
			</p>
		</NcNoteCard>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import AccountCircle from 'vue-material-design-icons/AccountCircle.vue'
import AccountMinus from 'vue-material-design-icons/AccountMinus.vue'
import AccountPlus from 'vue-material-design-icons/AccountPlus.vue'

/**
 * The Users tab of the organisation modal: the member list with its owner
 * badge and remove buttons.
 *
 * @spec exclude Presentational member list of the organisation modal.
 */
export default {
	name: 'OrganisationMembers',
	components: {
		AccountCircle,
		AccountMinus,
		AccountPlus,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
	},

	props: {
		organisation: { type: Object, required: true },
		members: { type: Array, required: true },
		state: { type: Object, required: true },
	},

	emits: ['add', 'remove'],

	methods: { t },
}
</script>

<style scoped>
.users-section {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 16px 0;
}

.users-header {
	display: flex;
	justify-content: flex-end;
	margin-bottom: 16px;
}

.loading-users {
	display: flex;
	align-items: center;
	gap: 8px;
	color: var(--color-text-lighter);
	padding: 16px;
}

.users-list {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.users-list h3 {
	margin: 0;
	font-size: 16px;
	font-weight: 500;
	color: var(--color-main-text);
}

.user-items {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.user-item {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 8px 12px;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius);
}

.user-info {
	display: flex;
	align-items: center;
	gap: 8px;
}

.user-icon {
	color: var(--color-text-lighter);
}

.user-id {
	font-weight: 500;
	color: var(--color-main-text);
}

.owner-badge {
	display: inline-flex;
	align-items: center;
	padding: 2px 8px;
	background-color: var(--color-warning);
	color: var(--color-main-background);
	border-radius: 12px;
	font-size: 11px;
	font-weight: 600;
	text-transform: uppercase;
}

.no-users {
	padding: 16px;
	text-align: center;
	color: var(--color-text-lighter);
	font-style: italic;
}

.no-users p {
	margin: 0;
}
</style>
