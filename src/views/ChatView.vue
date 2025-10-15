<template>
	<NcAppContent>
		<div class="chat-container">
			<!-- Chat Header -->
			<div class="chat-header">
				<div class="header-content">
					<h1>
						<Robot :size="32" />
						{{ t('openregister', 'AI Assistant') }}
					</h1>
					<p class="subtitle">{{ t('openregister', 'Ask questions about your data using natural language') }}</p>
				</div>
				<div class="header-actions">
					<NcButton
						type="secondary"
						@click="showChatSettings = true">
						<template #icon>
							<Cog :size="20" />
						</template>
						{{ t('openregister', 'Settings') }}
					</NcButton>
					<NcButton
						v-if="messages.length > 0"
						type="secondary"
						@click="clearConversation">
						<template #icon>
							<Delete :size="20" />
						</template>
						{{ t('openregister', 'Clear Chat') }}
					</NcButton>
				</div>
			</div>

		<!-- Configuration Required Message -->
		<div v-if="checkingConfig" class="empty-state">
			<div class="empty-icon">
				<NcLoadingIcon :size="64" />
			</div>
			<h2>{{ t('openregister', 'Checking configuration...') }}</h2>
		</div>

		<div v-else-if="!llmConfigured" class="empty-state config-required">
			<div class="empty-icon">
				<InformationOutline :size="64" />
			</div>
			<h2>{{ t('openregister', 'Chat Provider Not Configured') }}</h2>
			<p>{{ t('openregister', 'To chat with your data and documents, a Large Language Model (LLM) provider must be configured.') }}</p>
			<p class="contact-admin">{{ t('openregister', 'Please contact your administrator to configure a chat provider in the LLM Configuration settings.') }}</p>
			
			<div class="config-hint">
				<p><strong>{{ t('openregister', 'Administrators:') }}</strong></p>
				<ol>
					<li>{{ t('openregister', 'Go to Settings → OpenRegister → SOLR Configuration') }}</li>
					<li>{{ t('openregister', 'Click "Actions" → "LLM Configuration"') }}</li>
					<li>{{ t('openregister', 'Configure a Chat Provider (OpenAI, Ollama, etc.)') }}</li>
				</ol>
			</div>
		</div>

		<!-- Empty State -->
		<div v-else-if="messages.length === 0" class="empty-state">
			<div class="empty-icon">
				<MessageText :size="64" />
			</div>
			<h2>{{ t('openregister', 'Start a conversation') }}</h2>
			<p>{{ t('openregister', 'Ask questions about your objects, files, and data. The AI assistant uses semantic search to find relevant information.') }}</p>
			
			<div class="suggested-prompts">
				<h3>{{ t('openregister', 'Try asking:') }}</h3>
				<div class="prompt-grid">
					<button
						v-for="(prompt, index) in suggestedPrompts"
						:key="index"
						class="prompt-card"
						@click="sendMessage(prompt.text)">
						<div class="prompt-icon">{{ prompt.icon }}</div>
						<div class="prompt-text">{{ prompt.text }}</div>
					</button>
				</div>
			</div>
		</div>

			<!-- Chat Messages -->
			<div v-else class="chat-messages" ref="messagesContainer">
				<div
					v-for="(message, index) in messages"
					:key="index"
					:class="['message', message.role]">
					<div class="message-avatar">
						<AccountCircle v-if="message.role === 'user'" :size="32" />
						<Robot v-else :size="32" />
					</div>
					<div class="message-content">
						<div class="message-header">
							<span class="message-sender">{{ message.role === 'user' ? t('openregister', 'You') : t('openregister', 'AI Assistant') }}</span>
							<span class="message-time">{{ formatTime(message.timestamp) }}</span>
						</div>
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
										<span class="source-similarity">{{ Math.round(source.similarity * 100) }}% match</span>
									</div>
								</div>
							</div>
						</div>

						<!-- Feedback -->
						<div v-if="message.role === 'assistant'" class="message-feedback">
							<button
								:class="['feedback-btn', { active: message.feedback === 'positive' }]"
								@click="sendFeedback(index, 'positive')"
								:title="t('openregister', 'Helpful')">
								<ThumbUp :size="16" />
							</button>
							<button
								:class="['feedback-btn', { active: message.feedback === 'negative' }]"
								@click="sendFeedback(index, 'negative')"
								:title="t('openregister', 'Not helpful')">
								<ThumbDown :size="16" />
							</button>
						</div>
					</div>
				</div>

				<!-- Loading indicator -->
				<div v-if="loading" class="message assistant loading">
					<div class="message-avatar">
						<Robot :size="32" />
					</div>
					<div class="message-content">
						<div class="typing-indicator">
							<span></span>
							<span></span>
							<span></span>
						</div>
					</div>
				</div>
			</div>

		<!-- Chat Input (only show if configured) -->
		<div v-if="llmConfigured && !checkingConfig" class="chat-input-container">
			<div class="chat-input-wrapper">
				<textarea
					ref="messageInput"
					v-model="currentMessage"
					:placeholder="t('openregister', 'Ask a question...')"
					:disabled="loading"
					class="chat-input"
					rows="1"
					@keydown.enter.exact.prevent="handleSendMessage"
					@input="autoResize"
				/>
				<NcButton
					type="primary"
					:disabled="!currentMessage.trim() || loading"
					@click="handleSendMessage">
					<template #icon>
						<Send :size="20" />
					</template>
				</NcButton>
			</div>
			<div class="input-hint">
				<InformationOutline :size="14" />
				<span>{{ t('openregister', 'Press Enter to send, Shift+Enter for new line') }}</span>
			</div>
		</div>
	</div>

		<!-- Chat Settings Dialog -->
		<NcDialog
			v-if="showChatSettings"
			:name="t('openregister', 'Chat Settings')"
			@closing="showChatSettings = false">
			<div class="chat-settings">
				<div class="setting-group">
					<label>{{ t('openregister', 'Search Mode') }}</label>
					<NcSelect
						v-model="settings.searchMode"
						:options="searchModeOptions"
						label="name"
						:placeholder="t('openregister', 'Select search mode')">
					</NcSelect>
					<small>{{ t('openregister', 'How the AI should search for relevant information') }}</small>
				</div>

				<div class="setting-group">
					<label>{{ t('openregister', 'Number of Sources') }}</label>
					<input
						v-model.number="settings.numSources"
						type="number"
						min="1"
						max="10"
						class="input-field">
					<small>{{ t('openregister', 'How many sources to retrieve for context (1-10)') }}</small>
				</div>

				<div class="setting-group">
					<NcCheckboxRadioSwitch
						v-model="settings.includeFiles"
						type="switch">
						{{ t('openregister', 'Search in files') }}
					</NcCheckboxRadioSwitch>
				</div>

				<div class="setting-group">
					<NcCheckboxRadioSwitch
						v-model="settings.includeObjects"
						type="switch">
						{{ t('openregister', 'Search in objects') }}
					</NcCheckboxRadioSwitch>
				</div>
			</div>

			<template #actions>
				<NcButton @click="showChatSettings = false">
					{{ t('openregister', 'Close') }}
				</NcButton>
			</template>
		</NcDialog>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcButton, NcDialog, NcSelect, NcCheckboxRadioSwitch, NcLoadingIcon } from '@nextcloud/vue'
import Robot from 'vue-material-design-icons/Robot.vue'
import MessageText from 'vue-material-design-icons/MessageText.vue'
import Send from 'vue-material-design-icons/Send.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
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

export default {
	name: 'ChatView',

	components: {
		NcAppContent,
		NcButton,
		NcDialog,
		NcSelect,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		Robot,
		MessageText,
		Send,
		Cog,
		Delete,
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
			messages: [],
			currentMessage: '',
			loading: false,
			showChatSettings: false,
			llmConfigured: false,
			checkingConfig: true,
			
			settings: {
				searchMode: { id: 'hybrid', name: 'Hybrid (Recommended)' },
				numSources: 5,
				includeFiles: true,
				includeObjects: true,
			},

			searchModeOptions: [
				{ id: 'hybrid', name: 'Hybrid (Keyword + Semantic)' },
				{ id: 'semantic', name: 'Semantic Only' },
				{ id: 'keyword', name: 'Keyword Only' },
			],

			suggestedPrompts: [
				{
					icon: '🔍',
					text: 'What information do you have about projects?',
				},
				{
					icon: '📊',
					text: 'Show me a summary of the data',
				},
				{
					icon: '📄',
					text: 'What files contain information about budgets?',
				},
				{
					icon: '💡',
					text: 'Help me find records related to Amsterdam',
				},
			],
		}
	},

	mounted() {
		this.checkLLMConfiguration()
		this.loadConversationHistory()
	},

	methods: {
		async checkLLMConfiguration() {
			try {
				const response = await axios.get(generateUrl('/apps/openregister/api/settings'))
				const llmSettings = response.data.llm || {}
				
				// Check if chat provider is configured
				this.llmConfigured = !!(llmSettings.chatProvider && llmSettings.chatProvider.type)
				this.checkingConfig = false
			} catch (error) {
				console.error('Failed to check LLM configuration:', error)
				this.llmConfigured = false
				this.checkingConfig = false
			}
		},

		async loadConversationHistory() {
			// Only load history if LLM is configured
			if (!this.llmConfigured) {
				return
			}

			try {
				const response = await axios.get(generateUrl('/apps/openregister/api/chat/history'))
				this.messages = response.data.messages || []
			} catch (error) {
				console.error('Failed to load chat history:', error)
			}
		},

		async handleSendMessage() {
			if (!this.currentMessage.trim() || this.loading) {
				return
			}

			const userMessage = this.currentMessage.trim()
			this.currentMessage = ''

			// Add user message
			this.messages.push({
				role: 'user',
				content: userMessage,
				timestamp: new Date(),
			})

			this.scrollToBottom()
			this.loading = true

			try {
				// Call chat API
				const response = await axios.post(generateUrl('/apps/openregister/api/chat/send'), {
					message: userMessage,
					searchMode: this.settings.searchMode.id,
					numSources: this.settings.numSources,
					includeFiles: this.settings.includeFiles,
					includeObjects: this.settings.includeObjects,
				})

				// Add AI response
				this.messages.push({
					role: 'assistant',
					content: response.data.response,
					sources: response.data.sources || [],
					timestamp: new Date(),
					feedback: null,
				})

				this.scrollToBottom()
			} catch (error) {
				showError(this.t('openregister', 'Failed to get response: {error}', { error: error.response?.data?.error || error.message }))
				
				// Add error message
				this.messages.push({
					role: 'assistant',
					content: this.t('openregister', 'Sorry, I encountered an error. Please try again or check your LLM configuration in settings.'),
					timestamp: new Date(),
					isError: true,
				})
			} finally {
				this.loading = false
			}
		},

		sendMessage(text) {
			this.currentMessage = text
			this.handleSendMessage()
		},

		async clearConversation() {
			if (!confirm(this.t('openregister', 'Clear all chat history?'))) {
				return
			}

			try {
				await axios.delete(generateUrl('/apps/openregister/api/chat/history'))
				this.messages = []
				showSuccess(this.t('openregister', 'Chat history cleared'))
			} catch (error) {
				showError(this.t('openregister', 'Failed to clear chat history'))
			}
		},

		async sendFeedback(messageIndex, feedback) {
			const message = this.messages[messageIndex]
			message.feedback = message.feedback === feedback ? null : feedback

			try {
				await axios.post(generateUrl('/apps/openregister/api/chat/feedback'), {
					messageId: messageIndex,
					feedback: message.feedback,
				})
			} catch (error) {
				console.error('Failed to send feedback:', error)
			}
		},

		viewSource(source) {
			// TODO: Navigate to source object/file
			console.log('View source:', source)
		},

		formatMessage(content) {
			// Convert markdown to HTML using marked library
			return marked.parse(content)
		},

		formatTime(timestamp) {
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
			textarea.style.height = 'auto'
			textarea.style.height = Math.min(textarea.scrollHeight, 150) + 'px'
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
	display: flex;
	flex-direction: column;
	height: 100%;
	background: var(--color-main-background);
}

.chat-header {
	padding: 20px 24px;
	border-bottom: 1px solid var(--color-border);
	background: var(--color-background-hover);

	.header-content {
		h1 {
			display: flex;
			align-items: center;
			gap: 12px;
			margin: 0 0 8px 0;
			font-size: 24px;
			font-weight: 600;
		}

		.subtitle {
			margin: 0;
			color: var(--color-text-maxcontrast);
			font-size: 14px;
		}
	}

	.header-actions {
		display: flex;
		gap: 8px;
		margin-top: 16px;
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
		margin: 0 0 48px 0;
		color: var(--color-text-maxcontrast);
		font-size: 16px;
	}

	.suggested-prompts {
		width: 100%;
		max-width: 800px;

		h3 {
			margin: 0 0 20px 0;
			font-size: 16px;
			font-weight: 600;
		}

		.prompt-grid {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
			gap: 12px;
		}

		.prompt-card {
			display: flex;
			align-items: center;
			gap: 12px;
			padding: 16px;
			background: var(--color-background-hover);
			border: 1px solid var(--color-border);
			border-radius: 8px;
			cursor: pointer;
			transition: all 0.2s ease;
			text-align: left;

			&:hover {
				background: var(--color-background-dark);
				border-color: var(--color-primary-element);
			}

			.prompt-icon {
				font-size: 24px;
			}

			.prompt-text {
				flex: 1;
				font-size: 14px;
				color: var(--color-main-text);
			}
		}
	}
}

.chat-messages {
	flex: 1;
	overflow-y: auto;
	padding: 24px;
	display: flex;
	flex-direction: column;
	gap: 24px;

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
				display: flex;
				gap: 8px;
				margin-top: 8px;

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
						background: var(--color-primary-element-light);
						border-color: var(--color-primary-element);
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

.chat-settings {
	padding: 20px;

	.setting-group {
		margin-bottom: 24px;

		label {
			display: block;
			margin-bottom: 8px;
			font-weight: 500;
		}

		.input-field {
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

		small {
			display: block;
			margin-top: 6px;
			color: var(--color-text-maxcontrast);
			font-size: 12px;
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

