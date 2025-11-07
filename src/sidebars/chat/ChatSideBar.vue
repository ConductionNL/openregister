<script setup>
import { navigationStore, conversationStore } from '../../store/store.js'
</script>

<template>
	<div>
		<NcAppSidebar
			ref="sidebar"
			v-model="activeTab"
			name="Conversations"
			subtitle="Manage your chat conversations"
			subname="AI Assistant"
			:open="navigationStore.sidebarState.chat"
			@update:open="(e) => navigationStore.setSidebarState('chat', e)">
			<!-- Active Conversations Tab -->
			<NcAppSidebarTab
				id="conversations-tab"
				:name="t('openregister', 'Active')"
				:order="1">
				<template #icon>
					<MessageText :size="20" />
				</template>

				<div class="conversationsSection">
					<!-- New Conversation Button -->
					<div class="newConversationSection">
						<NcButton
							type="primary"
							wide
							@click="handleNewConversation">
							<template #icon>
								<Plus :size="20" />
							</template>
							{{ t('openregister', 'New Conversation') }}
						</NcButton>
					</div>

					<!-- Loading State -->
					<div v-if="conversationStore.loading" class="conversationsLoading">
						<NcLoadingIcon :size="32" />
						<p>{{ t('openregister', 'Loading conversations...') }}</p>
					</div>

					<!-- Empty State -->
					<div v-else-if="!conversationStore.conversationList || conversationStore.conversationList.length === 0" class="noConversations">
						<NcNoteCard type="info">
							{{ t('openregister', 'No conversations yet. Create a new one to get started!') }}
						</NcNoteCard>
					</div>

					<!-- Conversation List -->
					<div v-else class="conversationsTable">
						<div
							v-for="conversation in conversationStore.conversationList"
							:key="conversation.uuid"
							class="conversationRow"
							:class="{ 'conversationRow--active': isActive(conversation) }">
							<div class="conversationRowHeader" @click="handleSelectConversation(conversation)">
								<div class="conversationRowTitle">
									<strong>{{ conversation.title || t('openregister', 'New Conversation') }}</strong>
									<span v-if="conversation.messageCount" class="conversationBadge">
										{{ conversation.messageCount }} {{ t('openregister', 'messages') }}
									</span>
								</div>
								<div class="conversationRowMeta">
									<span class="conversationDate">{{ formatDate(conversation.updated) }}</span>
								</div>
							</div>
						<div class="conversationRowActions">
							<NcButton
								type="tertiary"
								:aria-label="t('openregister', 'Archive conversation')"
								@click.stop="handleArchiveConversation(conversation)">
								<template #icon>
									<Archive :size="20" />
								</template>
							</NcButton>
						</div>
						</div>
					</div>
				</div>
			</NcAppSidebarTab>

			<!-- Archive Tab -->
			<NcAppSidebarTab
				id="archive-tab"
				:name="t('openregister', 'Archive')"
				:order="2">
				<template #icon>
					<Archive :size="20" />
				</template>

				<div class="conversationsSection">
					<p class="archiveDescription">
						{{ t('openregister', 'Archived conversations are hidden from your active list') }}
					</p>

					<!-- Loading State -->
					<div v-if="conversationStore.loading" class="conversationsLoading">
						<NcLoadingIcon :size="32" />
						<p>{{ t('openregister', 'Loading archived conversations...') }}</p>
					</div>

					<!-- Empty State -->
					<div v-else-if="!conversationStore.archivedConversations || conversationStore.archivedConversations.length === 0" class="noConversations">
						<NcNoteCard type="info">
							{{ t('openregister', 'No archived conversations') }}
						</NcNoteCard>
					</div>

					<!-- Archived Conversation List -->
					<div v-else class="conversationsTable">
						<div
							v-for="conversation in conversationStore.archivedConversations"
							:key="conversation.uuid"
							class="conversationRow">
							<div class="conversationRowHeader" @click="handleSelectConversation(conversation)">
								<div class="conversationRowTitle">
									<strong>{{ conversation.title || t('openregister', 'New Conversation') }}</strong>
									<span v-if="conversation.messageCount" class="conversationBadge">
										{{ conversation.messageCount }} {{ t('openregister', 'messages') }}
									</span>
								</div>
								<div class="conversationRowMeta">
									<span class="conversationDate">{{ formatDate(conversation.updated) }}</span>
								</div>
							</div>
							<div class="conversationRowActions">
								<!-- Restore Button -->
								<NcButton
									type="secondary"
									:aria-label="t('openregister', 'Restore conversation')"
									@click.stop="handleRestoreConversation(conversation)">
									<template #icon>
										<Restore :size="20" />
									</template>
								</NcButton>
								<!-- Delete Permanently Button -->
								<NcButton
									type="error"
									:aria-label="t('openregister', 'Delete permanently')"
									@click.stop="handleDeleteConversation(conversation)">
									<template #icon>
										<Delete :size="20" />
									</template>
								</NcButton>
							</div>
						</div>
					</div>
				</div>
			</NcAppSidebarTab>
		</NcAppSidebar>
	</div>
</template>

<script>
import { NcAppSidebar, NcAppSidebarTab, NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import MessageText from 'vue-material-design-icons/MessageText.vue'
import Archive from 'vue-material-design-icons/Archive.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Restore from 'vue-material-design-icons/Restore.vue'
import { translate as t } from '@nextcloud/l10n'
import { showError, showSuccess } from '@nextcloud/dialogs'

export default {
	name: 'ChatSideBar',
	components: {
		NcAppSidebar,
		NcAppSidebarTab,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		MessageText,
		Archive,
		Plus,
		Delete,
		Restore,
	},
	data() {
		return {
			activeTab: 'conversations-tab',
		}
	},
	methods: {
		t,
		isActive(conversation) {
			return conversationStore.activeConversation?.uuid === conversation.uuid
		},
		formatDate(dateString) {
			if (!dateString) return ''
			
			const date = new Date(dateString)
			const now = new Date()
			const diff = now - date

			if (diff < 86400000) { // Less than 24 hours
				return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
			} else if (diff < 604800000) { // Less than 7 days
				return date.toLocaleDateString([], { weekday: 'short' })
			} else {
				return date.toLocaleDateString([], { month: 'short', day: 'numeric' })
			}
		},
		handleNewConversation() {
			// Clear active conversation to show the agent selector
			conversationStore.setActiveConversation(null)
			conversationStore.setActiveMessages([])
		},
		async handleSelectConversation(conversation) {
			try {
				await conversationStore.loadConversation(conversation.uuid)
			} catch (error) {
				console.error('Failed to load conversation:', error)
				showError(this.t('openregister', 'Failed to load conversation'))
			}
		},
		async handleArchiveConversation(conversation) {
			try {
				await conversationStore.archiveConversation(conversation.uuid)
				showSuccess(this.t('openregister', 'Conversation archived'))
			} catch (error) {
				console.error('Failed to archive conversation:', error)
				showError(this.t('openregister', 'Failed to archive conversation'))
			}
		},
		async handleDeleteConversation(conversation) {
			try {
				await conversationStore.deleteConversation(conversation.uuid)
				showSuccess(this.t('openregister', 'Conversation deleted'))
			} catch (error) {
				console.error('Failed to delete conversation:', error)
				showError(this.t('openregister', 'Failed to delete conversation'))
			}
		},
		async handleRestoreConversation(conversation) {
			try {
				await conversationStore.restoreConversation(conversation.uuid)
				showSuccess(this.t('openregister', 'Conversation restored'))
			} catch (error) {
				console.error('Failed to restore conversation:', error)
				showError(this.t('openregister', 'Failed to restore conversation'))
			}
		},
	},
}
</script>

<style lang="scss" scoped>
.conversationsSection {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 16px;
	min-height: calc(100vh - 200px);
}

.newConversationSection {
	padding-bottom: 16px;
	border-bottom: 1px solid var(--color-border);
}

.archiveDescription {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin: 0;
	padding: 12px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
}

.conversationsLoading {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 32px;
	gap: 16px;

	p {
		color: var(--color-text-maxcontrast);
		margin: 0;
	}
}

.noConversations {
	padding: 16px 0;
}

.conversationsTable {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-top: 8px;
}

.conversationRow {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 12px;
	display: flex;
	flex-direction: column;
	gap: 8px;
	transition: background-color 0.2s ease;

	&:hover {
		background-color: var(--color-background-hover);
	}

	&--active {
		border-color: var(--color-primary-element);
		background-color: var(--color-primary-element-light);
	}
}

.conversationRowHeader {
	display: flex;
	flex-direction: column;
	gap: 4px;
	cursor: pointer;
	flex: 1;
}

.conversationRowTitle {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;

	strong {
		font-size: 1em;
		color: var(--color-main-text);
	}
}

.conversationBadge {
	font-size: 0.75em;
	padding: 2px 6px;
	border-radius: 3px;
	font-weight: 600;
	background-color: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.conversationRowMeta {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.conversationDate {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.conversationRowActions {
	display: flex;
	gap: 4px;
	justify-content: flex-end;
}
</style>

