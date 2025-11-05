<template>
	<div :class="['conversation-sidebar', { collapsed }]">
		<!-- Sidebar Header -->
		<div class="sidebar-header">
			<h3 v-if="!collapsed">
				{{ t('openregister', 'Conversations') }}
			</h3>
			<div class="header-actions">
				<NcButton
					v-if="!collapsed"
					type="tertiary"
					:aria-label="t('openregister', 'New conversation')"
					@click="$emit('new-conversation')">
					<template #icon>
						<Plus :size="20" />
					</template>
				</NcButton>
				<NcButton
					type="tertiary"
					:aria-label="collapsed ? t('openregister', 'Expand sidebar') : t('openregister', 'Collapse sidebar')"
					@click="$emit('toggle-sidebar')">
					<template #icon>
						<ChevronLeft v-if="!collapsed" :size="20" />
						<ChevronRight v-else :size="20" />
					</template>
				</NcButton>
			</div>
		</div>

		<!-- Collapsed state -->
		<div v-if="collapsed" class="collapsed-content">
			<NcButton
				type="tertiary"
				:aria-label="t('openregister', 'New conversation')"
				@click="$emit('new-conversation')">
				<template #icon>
					<Plus :size="20" />
				</template>
			</NcButton>
		</div>

		<!-- Expanded state -->
		<div v-else class="sidebar-content">
			<!-- View Toggle -->
			<div class="view-toggle">
				<button
					:class="['toggle-btn', { active: !showArchive }]"
					@click="$emit('toggle-archive', false)">
					{{ t('openregister', 'Active') }}
				</button>
				<button
					:class="['toggle-btn', { active: showArchive }]"
					@click="$emit('toggle-archive', true)">
					{{ t('openregister', 'Archive') }}
				</button>
			</div>

			<!-- Loading State -->
			<div v-if="loading" class="loading-state">
				<NcLoadingIcon :size="32" />
			</div>

			<!-- Empty State -->
			<div v-else-if="conversations.length === 0" class="empty-state">
				<MessageOff :size="48" />
				<p>{{ showArchive ? t('openregister', 'No archived conversations') : t('openregister', 'No conversations yet') }}</p>
			</div>

			<!-- Conversation List -->
			<div v-else class="conversation-list">
				<div
					v-for="conversation in conversations"
					:key="conversation.uuid"
					:class="['conversation-item', { active: isActive(conversation) }]"
					@click="$emit('select-conversation', conversation)">
					<div class="conversation-content">
						<div class="conversation-title">
							{{ conversation.title || t('openregister', 'New Conversation') }}
						</div>
						<div class="conversation-meta">
							<span class="conversation-date">{{ formatDate(conversation.updated) }}</span>
							<span v-if="conversation.messageCount" class="conversation-count">
								{{ conversation.messageCount }} {{ t('openregister', 'messages') }}
							</span>
						</div>
					</div>
					<div class="conversation-actions">
						<NcButton
							v-if="showArchive"
							type="tertiary"
							:aria-label="t('openregister', 'Restore')"
							@click.stop="$emit('restore-conversation', conversation)">
							<template #icon>
								<Restore :size="16" />
							</template>
						</NcButton>
						<NcButton
							type="tertiary"
							:aria-label="showArchive ? t('openregister', 'Delete permanently') : t('openregister', 'Delete')"
							@click.stop="$emit('delete-conversation', conversation)">
							<template #icon>
								<Delete :size="16" />
							</template>
						</NcButton>
					</div>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import ChevronLeft from 'vue-material-design-icons/ChevronLeft.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Restore from 'vue-material-design-icons/Restore.vue'
import MessageOff from 'vue-material-design-icons/MessageOff.vue'

export default {
	name: 'ConversationSidebar',

	components: {
		NcButton,
		NcLoadingIcon,
		Plus,
		ChevronLeft,
		ChevronRight,
		Delete,
		Restore,
		MessageOff,
	},

	props: {
		collapsed: {
			type: Boolean,
			default: false,
		},
		conversations: {
			type: Array,
			default: () => [],
		},
		activeConversation: {
			type: Object,
			default: null,
		},
		showArchive: {
			type: Boolean,
			default: false,
		},
		loading: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['toggle-sidebar', 'new-conversation', 'select-conversation', 'delete-conversation', 'restore-conversation', 'toggle-archive'],

	methods: {
		isActive(conversation) {
			return this.activeConversation?.uuid === conversation.uuid
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

		t(app, text) {
			// Translation function placeholder
			return text
		},
	},
}
</script>

<script setup>
</script>

<style scoped lang="scss">
.conversation-sidebar {
	display: flex;
	flex-direction: column;
	width: 300px;
	height: 100%;
	background: var(--color-main-background);
	border-right: 1px solid var(--color-border);
	transition: width 0.2s ease;

	&.collapsed {
		width: 60px;
	}

	.sidebar-header {
		display: flex;
		align-items: center;
		justify-content: space-between;
		padding: 16px;
		border-bottom: 1px solid var(--color-border);

		h3 {
			margin: 0;
			font-size: 16px;
			font-weight: 600;
		}

		.header-actions {
			display: flex;
			gap: 4px;
		}
	}

	.collapsed-content {
		display: flex;
		flex-direction: column;
		align-items: center;
		padding: 16px 8px;
	}

	.sidebar-content {
		display: flex;
		flex-direction: column;
		height: 100%;
		overflow: hidden;

		.view-toggle {
			display: flex;
			gap: 4px;
			padding: 12px 16px;
			background: var(--color-background-hover);

			.toggle-btn {
				flex: 1;
				padding: 8px;
				background: transparent;
				border: 1px solid var(--color-border);
				border-radius: 6px;
				font-size: 13px;
				cursor: pointer;
				transition: all 0.2s ease;

				&:hover {
					background: var(--color-background-dark);
				}

				&.active {
					background: var(--color-primary-element);
					color: var(--color-primary-element-text);
					border-color: var(--color-primary-element);
					font-weight: 600;
				}
			}
		}

		.loading-state {
			display: flex;
			align-items: center;
			justify-content: center;
			padding: 48px 16px;
		}

		.empty-state {
			display: flex;
			flex-direction: column;
			align-items: center;
			justify-content: center;
			padding: 48px 16px;
			text-align: center;
			opacity: 0.5;

			p {
				margin-top: 12px;
				font-size: 13px;
				color: var(--color-text-maxcontrast);
			}
		}

		.conversation-list {
			flex: 1;
			overflow-y: auto;
			padding: 8px;

			.conversation-item {
				display: flex;
				align-items: center;
				justify-content: space-between;
				padding: 12px;
				margin-bottom: 4px;
				background: var(--color-background-hover);
				border: 1px solid transparent;
				border-radius: 8px;
				cursor: pointer;
				transition: all 0.2s ease;

				&:hover {
					background: var(--color-background-dark);
					border-color: var(--color-border);

					.conversation-actions {
						opacity: 1;
					}
				}

				&.active {
					background: var(--color-primary-element-light);
					border-color: var(--color-primary-element);
				}

				.conversation-content {
					flex: 1;
					min-width: 0;

					.conversation-title {
						font-size: 14px;
						font-weight: 500;
						margin-bottom: 4px;
						overflow: hidden;
						text-overflow: ellipsis;
						white-space: nowrap;
					}

					.conversation-meta {
						display: flex;
						gap: 8px;
						font-size: 11px;
						color: var(--color-text-maxcontrast);

						.conversation-count {
							&::before {
								content: '•';
								margin-right: 8px;
							}
						}
					}
				}

				.conversation-actions {
					display: flex;
					gap: 4px;
					opacity: 0;
					transition: opacity 0.2s ease;
				}

				&.active .conversation-actions {
					opacity: 1;
				}
			}
		}
	}
}
</style>

