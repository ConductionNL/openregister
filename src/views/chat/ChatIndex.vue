<template>
	<NcAppContent>
		<!-- Main Chat Area -->
		<div class="chat-container">
			<!-- Chat Header -->
			<div class="chat-header">
				<div class="header-content">
					<div class="header-title-row">
						<h1>
							<Robot :size="32" />
							{{ activeConversation?.title || t('openregister', 'AI Assistant') }}
						</h1>
						<NcButton
							v-if="activeConversation"
							type="tertiary"
							:aria-label="t('openregister', 'Rename conversation')"
							@click="renameConversation">
							<template #icon>
								<Pencil :size="20" />
							</template>
						</NcButton>
					</div>
					<p v-if="currentAgent" class="subtitle">
						{{ t('openregister', 'Agent:') }} <strong>{{ currentAgent.name }}</strong>
						<span v-if="currentAgent.model"> • {{ currentAgent.model }}</span>
					</p>
					<p v-else class="subtitle">
						{{ t('openregister', 'Ask questions about your data using natural language') }}
					</p>
				</div>
			</div>

			<!-- Empty State - No Conversation Selected -->
			<div v-if="!activeConversation" class="empty-state">
				<div class="empty-icon">
					<MessageText :size="64" />
				</div>
				<h2>{{ t('openregister', 'Start a conversation') }}</h2>
				<p>{{ t('openregister', 'Select an AI agent to begin chatting with your data.') }}</p>

				<!-- Inline Agent Selector -->
				<div class="agent-selector-container">
					<AgentSelector
						:agents="availableAgents"
						:selected-agent="selectedAgent"
						:loading="false"
						:error="availableAgents.length === 0 ? t('openregister', 'No agents available') : null"
						:inline="true"
						:starting="startingConversation"
						@select-agent="selectAgent"
						@confirm="startConversationWithAgent" />
				</div>
			</div>

			<!-- Chat Messages -->
			<div v-else ref="messagesContainer" class="chat-messages">
				<!-- Messages loading indicator -->
				<div v-if="messagesLoading && activeMessages.length === 0" class="messages-loading">
					<div class="typing-indicator">
						<span />
						<span />
						<span />
					</div>
					<p>{{ t('openregister', 'Loading conversation...') }}</p>
				</div>

				<div
					v-for="(message, index) in activeMessages"
					:key="index"
					:class="['message', message.role]">
					<div class="message-avatar">
						<AccountCircle v-if="message.role === 'user'" :size="32" />
						<Robot v-else :size="32" />
					</div>
					<div class="message-content">
						<div class="message-header">
							<span class="message-sender">{{ message.role === 'user' ? t('openregister', 'You') : t('openregister', 'AI Assistant') }}</span>
							<span class="message-time">{{ formatTime(message.created) }}</span>
						</div>
						<!-- TODO: Fix this eslint rule -->
						<!-- eslint-disable-next-line vue/no-v-html -->
						<div class="message-text" v-html="formatMessage(message.content)" />

						<!-- Sources (for AI messages) -->
						<div v-if="message.sources && message.sources.length > 0" class="message-sources">
							<div class="sources-header">
								<FileDocumentOutline :size="16" />
								<span>{{ t('openregister', 'Sources:') }}</span>
							</div>
							<div class="sources-list">
								<div
									v-for="(source, sIndex) in message.sources"
									:key="sIndex"
									class="source-item"
									@click="viewSource(source)">
									<div class="source-icon">
										<FileDocument v-if="source.type === 'file'" :size="16" />
										<CubeOutline v-else :size="16" />
									</div>
									<div class="source-info">
										<span class="source-name">{{ source.name }}</span>
										<span v-if="source.relevance" class="source-similarity">{{ Math.round(source.relevance * 100) }}% match</span>
									</div>
								</div>
							</div>
						</div>

						<!-- Feedback -->
						<div v-if="message.role === 'assistant'" class="message-feedback">
							<div class="feedback-buttons">
								<button
									:class="['feedback-btn', 'feedback-positive', { active: message.feedback === 'positive' }]"
									:title="t('openregister', 'Helpful')"
									@click="sendFeedback(message, 'positive')">
									<ThumbUp :size="16" />
								</button>
								<button
									:class="['feedback-btn', 'feedback-negative', { active: message.feedback === 'negative' }]"
									:title="t('openregister', 'Not helpful')"
									@click="sendFeedback(message, 'negative')">
									<ThumbDown :size="16" />
								</button>
							</div>

							<!-- Feedback comment input -->
							<div v-if="message.feedback && message.showFeedbackInput" class="feedback-comment">
								<textarea
									v-model="message.feedbackComment"
									:placeholder="t('openregister', 'Your feedback has been recorded. Optionally, you can provide additional details here...')"
									class="feedback-input"
									rows="3"
									@keydown.enter.ctrl="saveFeedbackComment(message)" />
								<NcButton
									type="primary"
									:disabled="!message.feedbackComment || message.feedbackComment.trim() === ''"
									@click="saveFeedbackComment(message)">
									<template #icon>
										<Send :size="20" />
									</template>
									{{ t('openregister', 'Send additional feedback') }}
								</NcButton>
							</div>
						</div>
					</div>
				</div>
			</div>

			<!-- Chat Input (only show if conversation selected) -->
			<div v-if="activeConversation" class="chat-input-container">
				<div class="chat-input-wrapper">
					<textarea
						ref="messageInput"
						v-model="currentMessage"
						:placeholder="t('openregister', 'Ask a question...')"
						:disabled="loading"
						class="chat-input"
						rows="1"
						@keydown.enter.exact.prevent="handleSendMessage"
						@input="autoResize" />
					<NcButton
						type="primary"
						:disabled="!currentMessage.trim() || loading"
						@click="handleSendMessage">
						<template #icon>
							<NcLoadingIcon v-if="loading" :size="20" />
							<Send v-else :size="20" />
						</template>
					</NcButton>
				</div>
				<div class="input-hint">
					<InformationOutline :size="14" />
					<span>{{ t('openregister', 'Press Enter to send, Shift+Enter for new line') }}</span>
				</div>
			</div>
		</div>

		<!-- Agent Selector Dialog -->
		<NcDialog
			v-if="showAgentSelectorDialog"
			:name="t('openregister', 'Select AI Agent')"
			@closing="showAgentSelectorDialog = false">
			<AgentSelector
				:agents="availableAgents"
				:selected-agent="selectedAgent"
				:loading="false"
				:error="availableAgents.length === 0 ? t('openregister', 'No agents available') : null"
				:starting="startingConversation"
				@select-agent="selectAgent"
				@confirm="startConversationWithAgent"
				@cancel="showAgentSelectorDialog = false" />
		</NcDialog>

		<!-- Rename Conversation Dialog -->
		<NcDialog
			v-if="showRenameDialog"
			:name="t('openregister', 'Rename Conversation')"
			@closing="showRenameDialog = false">
			<div class="rename-dialog">
				<input
					v-model="newConversationTitle"
					type="text"
					:placeholder="t('openregister', 'Conversation title')"
					class="rename-input">
			</div>
			<template #actions>
				<NcButton @click="showRenameDialog = false">
					{{ t('openregister', 'Cancel') }}
				</NcButton>
				<NcButton
					type="primary"
					:disabled="!newConversationTitle.trim()"
					@click="saveConversationTitle">
					{{ t('openregister', 'Save') }}
				</NcButton>
			</template>
		</NcDialog>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcButton, NcDialog, NcLoadingIcon } from '@nextcloud/vue'
import AgentSelector from '../../components/AgentSelector.vue'
import Robot from 'vue-material-design-icons/Robot.vue'
import MessageText from 'vue-material-design-icons/MessageText.vue'
import Send from 'vue-material-design-icons/Send.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import AccountCircle from 'vue-material-design-icons/AccountCircle.vue'
import FileDocument from 'vue-material-design-icons/FileDocument.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import CubeOutline from 'vue-material-design-icons/CubeOutline.vue'
import ThumbUp from 'vue-material-design-icons/ThumbUp.vue'
import ThumbDown from 'vue-material-design-icons/ThumbDown.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { marked } from 'marked'
import { agentStore, conversationStore } from '../../store/store.js'

export default {
	name: 'ChatIndex',

	components: {
		NcAppContent,
		NcButton,
		NcDialog,
		NcLoadingIcon,
		AgentSelector,
		Robot,
		MessageText,
		Send,
		Pencil,
		AccountCircle,
		FileDocument,
		FileDocumentOutline,
		CubeOutline,
		ThumbUp,
		ThumbDown,
		InformationOutline,
	},

	data() {
		return {
			// Message state
			currentMessage: '',
			loading: false,

			// Agent selection
			showAgentSelectorDialog: false,
			selectedAgent: null,
			availableAgents: [], // Loaded from agentStore during app warmup
			currentAgent: null,
			startingConversation: false,

			// Rename dialog
			showRenameDialog: false,
			newConversationTitle: '',
		}
	},

	computed: {
		conversationList() {
			return conversationStore.conversationList || []
		},

		archivedConversations() {
			return conversationStore.archivedConversations || []
		},

		activeConversation() {
			return conversationStore.activeConversation || null
		},

		activeMessages() {
			return conversationStore.activeConversationMessages || []
		},

		conversationLoading() {
			return conversationStore.loading || false
		},

		messagesLoading() {
			return conversationStore.messagesLoading || false
		},
	},

	mounted() {
		// Conversations are already loaded during app warmup
		// Agents are already loaded during app warmup
		this.loadAgentsFromStore()

		// Just ensure we have the latest conversation list (should already be loaded)
		if (!conversationStore.conversationList || conversationStore.conversationList.length === 0) {
			console.info('Conversations not preloaded, loading now...')
			conversationStore.refreshConversationList()
		}
	},

	methods: {

		async showAgentSelector() {
			this.showAgentSelectorDialog = true
			this.selectedAgent = null
			// Agents should already be loaded from warmup
			this.loadAgentsFromStore()
		},

		/**
		 * Load agents from the already-initialized agent store
		 * Agents are preloaded during app warmup, so we just use them from the store
		 *
		 * @return {void}
		 */
		loadAgentsFromStore() {
			// Agents are already loaded during app warmup (AppInitializationService)
			// Just use them from the store
			console.info('[ChatIndex] loadAgentsFromStore called')
			console.info('[ChatIndex] agentStore:', agentStore)
			console.info('[ChatIndex] agentStore.agentList:', agentStore.agentList)

			this.availableAgents = agentStore.agentList || []

			console.info('[ChatIndex] availableAgents set to:', this.availableAgents)

			if (this.availableAgents.length === 0) {
				console.warn('[ChatIndex] ⚠ No agents available in store')
				console.warn('[ChatIndex] Trying to refresh agent list...')
				// If no agents, try to refresh the list
				agentStore.refreshAgentList().then(() => {
					console.info('[ChatIndex] After refresh, agentList:', agentStore.agentList)
					this.availableAgents = agentStore.agentList || []
					console.info('[ChatIndex] availableAgents now:', this.availableAgents)
				}).catch(err => {
					console.error('[ChatIndex] Failed to refresh agents:', err)
				})
			} else {
				console.info('[ChatIndex] ✓ Using preloaded agents from store:', this.availableAgents.length)
			}
		},

		selectAgent(agent) {
			this.selectedAgent = agent
		},

		async startConversationWithAgent() {
			if (!this.selectedAgent) {
				showError(this.t('openregister', 'Please select an agent to continue'))
				return
			}

			this.startingConversation = true

			try {
				const conversation = await conversationStore.createConversation(this.selectedAgent.uuid)
				this.currentAgent = this.selectedAgent
				// Keep selected agent for next time, but clear for UX
				const agentCopy = this.selectedAgent
				this.selectedAgent = null

				// Close dialog after successful creation
				this.showAgentSelectorDialog = false

				// If we just created a conversation, show success
				if (conversation) {
					showSuccess(this.t('openregister', 'Conversation started with {agent}', { agent: agentCopy.name }))
				}
			} catch (error) {
				console.error('Failed to create conversation:', error)
				showError(this.t('openregister', 'Failed to create conversation'))
			} finally {
				this.startingConversation = false
			}
		},

		async selectConversation(conversation) {
			try {
				await conversationStore.loadConversation(conversation.uuid)

				// Load agent details
				if (conversation.agentId) {
					const response = await axios.get(generateUrl(`/apps/openregister/api/agents/${conversation.agentId}`))
					this.currentAgent = response.data
				}

				this.scrollToBottom()
			} catch (error) {
				showError(this.t('openregister', 'Failed to load conversation'))
			}
		},

		async deleteConversation(conversation) {
			const message = this.showArchive
				? this.t('openregister', 'Permanently delete this conversation?')
				: this.t('openregister', 'Delete this conversation?')

			if (!confirm(message)) {
				return
			}

			try {
				if (this.showArchive) {
					await conversationStore.deleteConversationPermanent(conversation.uuid)
				} else {
					await conversationStore.deleteConversation(conversation.uuid)
				}
				showSuccess(this.t('openregister', 'Conversation deleted'))
			} catch (error) {
				showError(this.t('openregister', 'Failed to delete conversation'))
			}
		},

		async restoreConversation(conversation) {
			try {
				await conversationStore.restoreConversation(conversation.uuid)
				showSuccess(this.t('openregister', 'Conversation restored'))
			} catch (error) {
				showError(this.t('openregister', 'Failed to restore conversation'))
			}
		},

		renameConversation() {
			this.newConversationTitle = this.activeConversation.title || ''
			this.showRenameDialog = true
		},

		async saveConversationTitle() {
			if (!this.newConversationTitle.trim()) {
				return
			}

			try {
				await conversationStore.updateConversation(this.activeConversation.uuid, {
					title: this.newConversationTitle.trim(),
				})
				this.showRenameDialog = false
				showSuccess(this.t('openregister', 'Conversation renamed'))
			} catch (error) {
				showError(this.t('openregister', 'Failed to rename conversation'))
			}
		},

		async handleSendMessage() {
			if (!this.currentMessage.trim() || this.loading || !this.activeConversation) {
				return
			}

			const userMessage = this.currentMessage.trim()
			this.currentMessage = ''

			this.scrollToBottom()
			this.loading = true

			try {
				await conversationStore.sendMessage(
					userMessage,
					this.activeConversation.uuid,
					this.currentAgent?.uuid,
				)

				this.scrollToBottom()
			} catch (error) {
				showError(this.t('openregister', 'Failed to get response: {error}', { error: error.response?.data?.error || error.message }))
			} finally {
				this.loading = false
			}
		},

		async sendFeedback(message, feedback) {
			const isSameFeedback = message.feedback === feedback
			message.feedback = isSameFeedback ? null : feedback

			// Show input field for elaboration when feedback is given
			if (message.feedback) {
				this.$set(message, 'showFeedbackInput', true)
				this.$set(message, 'feedbackComment', message.feedbackComment || '')
			} else {
				this.$set(message, 'showFeedbackInput', false)
			}

			// If feedback is removed (null), just update UI without API call
			if (message.feedback === null) {
				return
			}

			try {
				const endpoint = generateUrl(`/apps/openregister/api/conversations/${this.activeConversation.uuid}/messages/${message.id}/feedback`)
				const response = await axios.post(endpoint, {
					type: message.feedback,
				})

				// Show brief success notification
				showSuccess(this.t('openregister', 'Feedback recorded'))

				// Store the feedback ID for future updates
				this.$set(message, 'feedbackId', response.data.id)
			} catch (error) {
				console.error('Failed to send feedback:', error)
				showError(this.t('openregister', 'Failed to send feedback'))
				// Revert the feedback state on error
				message.feedback = null
				this.$set(message, 'showFeedbackInput', false)
			}
		},

		async saveFeedbackComment(message) {
			if (!message.feedbackComment || !message.feedbackComment.trim()) {
				return
			}

			try {
				const endpoint = generateUrl(`/apps/openregister/api/conversations/${this.activeConversation.uuid}/messages/${message.id}/feedback`)
				await axios.post(endpoint, {
					type: message.feedback,
					comment: message.feedbackComment.trim(),
				})

				// Hide input after saving
				this.$set(message, 'showFeedbackInput', false)
				showSuccess(this.t('openregister', 'Additional feedback saved. Thank you!'))
			} catch (error) {
				console.error('Failed to save feedback comment:', error)
				showError(this.t('openregister', 'Failed to save additional feedback'))
			}
		},

		viewSource(source) {
			// TODO: Navigate to source object/file
			console.info('View source:', source)
		},

		formatMessage(content) {
			// Convert markdown to HTML using marked library
			return marked.parse(content || '')
		},

		formatTime(timestamp) {
			if (!timestamp) return ''

			const date = new Date(timestamp)
			const now = new Date()
			const diff = now - date

			if (diff < 60000) {
				return this.t('openregister', 'Just now')
			} else if (diff < 3600000) {
				return this.t('openregister', '{minutes} minutes ago', { minutes: Math.floor(diff / 60000) })
			} else if (diff < 86400000) {
				return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
			} else {
				return date.toLocaleDateString()
			}
		},

		autoResize() {
			const textarea = this.$refs.messageInput
			if (textarea) {
				textarea.style.height = 'auto'
				textarea.style.height = Math.min(textarea.scrollHeight, 150) + 'px'
			}
		},

		scrollToBottom() {
			this.$nextTick(() => {
				const container = this.$refs.messagesContainer
				if (container) {
					container.scrollTop = container.scrollHeight
				}
			})
		},

		t(app, text, vars) {
			// Translation function placeholder
			return text.replace(/\{(\w+)\}/g, (match, key) => vars?.[key] || match)
		},
	},
}
</script>

<style scoped lang="scss">
.chat-container {
	flex: 1;
	display: flex;
	flex-direction: column;
	height: 100%;
	overflow: hidden;
}

.chat-header {
	padding: 20px 24px;
	border-bottom: 1px solid var(--color-border);
	background: var(--color-background-hover);

	.header-content {
		.header-title-row {
			display: flex;
			align-items: center;
			gap: 12px;
			margin-bottom: 8px;
		}

		h1 {
			display: flex;
			align-items: center;
			gap: 12px;
			margin: 0;
			font-size: 24px;
			font-weight: 600;
		}

		.subtitle {
			margin: 0;
			color: var(--color-text-maxcontrast);
			font-size: 14px;

			strong {
				color: var(--color-main-text);
				font-weight: 600;
			}
		}
	}
}

.empty-state {
	flex: 1;
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 48px 24px;
	text-align: center;

	.empty-icon {
		margin-bottom: 24px;
		opacity: 0.5;
	}

	h2 {
		margin: 0 0 12px 0;
		font-size: 24px;
		font-weight: 600;
	}

	p {
		max-width: 600px;
		margin: 0 0 24px 0;
		color: var(--color-text-maxcontrast);
		font-size: 16px;
	}

	.agent-selector-container {
		width: 100%;
		max-width: 600px;
		margin-top: 24px;
	}
}

.chat-messages {
	flex: 1;
	overflow-y: auto;
	padding: 24px;
	display: flex;
	flex-direction: column;
	gap: 24px;

	.messages-loading {
		display: flex;
		flex-direction: column;
		align-items: center;
		justify-content: center;
		padding: 48px 24px;
		text-align: center;
		color: var(--color-text-maxcontrast);

		.typing-indicator {
			display: flex;
			gap: 4px;
			margin-bottom: 12px;

			span {
				width: 8px;
				height: 8px;
				background: var(--color-text-maxcontrast);
				border-radius: 50%;
				animation: bounce 1.4s infinite ease-in-out both;

				&:nth-child(1) {
					animation-delay: -0.32s;
				}

				&:nth-child(2) {
					animation-delay: -0.16s;
				}
			}
		}

		p {
			margin: 0;
			font-size: 14px;
		}
	}

	.message {
		display: flex;
		gap: 12px;
		animation: fadeIn 0.3s ease;

		&.user {
			.message-content {
				background: var(--color-primary-element-light);
			}
		}

		&.assistant {
			.message-content {
				background: var(--color-background-hover);
			}
		}

		.message-avatar {
			flex-shrink: 0;
			width: 32px;
			height: 32px;
			border-radius: 50%;
			overflow: hidden;
		}

		.message-content {
			flex: 1;
			padding: 12px 16px;
			border-radius: 8px;
			max-width: 80%;

			.message-header {
				display: flex;
				justify-content: space-between;
				align-items: center;
				margin-bottom: 8px;

				.message-sender {
					font-weight: 600;
					font-size: 13px;
				}

				.message-time {
					font-size: 12px;
					color: var(--color-text-maxcontrast);
				}
			}

			.message-text {
				font-size: 14px;
				line-height: 1.6;
				word-wrap: break-word;

				:deep(p) {
					margin: 0 0 8px 0;

					&:last-child {
						margin: 0;
					}
				}

				:deep(code) {
					padding: 2px 6px;
					background: var(--color-background-dark);
					border-radius: 4px;
					font-size: 13px;
				}

				:deep(pre) {
					padding: 12px;
					background: var(--color-background-dark);
					border-radius: 6px;
					overflow-x: auto;
				}
			}

			.message-sources {
				margin-top: 12px;
				padding-top: 12px;
				border-top: 1px solid var(--color-border);

				.sources-header {
					display: flex;
					align-items: center;
					gap: 6px;
					margin-bottom: 8px;
					font-size: 12px;
					font-weight: 600;
					color: var(--color-text-maxcontrast);
				}

				.sources-list {
					display: flex;
					flex-direction: column;
					gap: 6px;
				}

				.source-item {
					display: flex;
					align-items: center;
					gap: 8px;
					padding: 8px;
					background: var(--color-background-dark);
					border-radius: 6px;
					cursor: pointer;
					transition: background 0.2s ease;

					&:hover {
						background: var(--color-primary-element-light);
					}

					.source-icon {
						flex-shrink: 0;
					}

					.source-info {
						flex: 1;
						display: flex;
						flex-direction: column;
						gap: 2px;

						.source-name {
							font-size: 13px;
							font-weight: 500;
						}

						.source-similarity {
							font-size: 11px;
							color: var(--color-text-maxcontrast);
						}
					}
				}
			}

			.message-feedback {
				margin-top: 8px;

				.feedback-buttons {
					display: flex;
					gap: 8px;
				}

				.feedback-btn {
					padding: 6px;
					background: transparent;
					border: 1px solid var(--color-border);
					border-radius: 6px;
					cursor: pointer;
					transition: all 0.2s ease;
					display: flex;
					align-items: center;
					justify-content: center;

					&:hover {
						background: var(--color-background-dark);
					}

					&.active {
						&.feedback-positive {
							background: var(--color-success);
							border-color: var(--color-success);
							color: white;
						}

						&.feedback-negative {
							background: var(--color-error);
							border-color: var(--color-error);
							color: white;
						}
					}
				}

				.feedback-comment {
					margin-top: 12px;
					display: flex;
					flex-direction: column;
					gap: 8px;
					padding: 12px;
					background: var(--color-background-hover);
					border-radius: 6px;

					.feedback-input {
						width: 100%;
						padding: 10px 12px;
						border: 1px solid var(--color-border);
						border-radius: 6px;
						font-family: inherit;
						font-size: 14px;
						line-height: 1.5;
						resize: vertical;
						min-height: 80px;
						background: var(--color-main-background);

						&:focus {
							outline: none;
							border-color: var(--color-primary-element);
							box-shadow: 0 0 0 2px var(--color-primary-element-light);
						}

						&::placeholder {
							color: var(--color-text-maxcontrast);
							font-size: 13px;
						}
					}
				}
			}
		}

		&.loading {
			.typing-indicator {
				display: flex;
				gap: 4px;
				padding: 8px 0;

				span {
					width: 8px;
					height: 8px;
					background: var(--color-text-maxcontrast);
					border-radius: 50%;
					animation: bounce 1.4s infinite ease-in-out both;

					&:nth-child(1) {
						animation-delay: -0.32s;
					}

					&:nth-child(2) {
						animation-delay: -0.16s;
					}
				}
			}
		}
	}
}

.chat-input-container {
	padding: 16px 24px;
	border-top: 1px solid var(--color-border);
	background: var(--color-main-background);

	.chat-input-wrapper {
		display: flex;
		gap: 8px;
		align-items: flex-end;

		.chat-input {
			flex: 1;
			padding: 12px 16px;
			border: 1px solid var(--color-border);
			border-radius: 8px;
			font-size: 14px;
			line-height: 1.6;
			resize: none;
			background: var(--color-main-background);
			color: var(--color-main-text);
			min-height: 44px;
			max-height: 150px;

			&:focus {
				outline: none;
				border-color: var(--color-primary-element);
			}

			&:disabled {
				opacity: 0.5;
				cursor: not-allowed;
			}
		}
	}

	.input-hint {
		display: flex;
		align-items: center;
		gap: 6px;
		margin-top: 8px;
		font-size: 12px;
		color: var(--color-text-maxcontrast);
	}
}

.rename-dialog {
	padding: 20px;

	.rename-input {
		width: 100%;
		padding: 10px 12px;
		border: 1px solid var(--color-border);
		border-radius: 6px;
		font-size: 14px;
		background: var(--color-main-background);
		color: var(--color-main-text);

		&:focus {
			outline: none;
			border-color: var(--color-primary-element);
		}
	}
}

@keyframes fadeIn {
	from {
		opacity: 0;
		transform: translateY(10px);
	}
	to {
		opacity: 1;
		transform: translateY(0);
	}
}

@keyframes bounce {
	0%, 80%, 100% {
		transform: scale(0);
	}
	40% {
		transform: scale(1);
	}
}
</style>
