/**
 * Conversation Store Module
 *
 * @package
 * @author   Conduction Development Team
 * @copyright 2024 Conduction B.V.
 * @license  EUPL-1.2
 * @version  1.0.0
 */

import { defineStore } from 'pinia'
import { Conversation, TConversation } from '../../entities/conversation/index'
import { Message, TMessage } from '../../entities/message/index'

export const useConversationStore = defineStore('conversation', {
	state: () => ({
		// Current active conversation
		activeConversation: null as Conversation | null,
		activeConversationMessages: [] as Message[],

		// List of conversations
		conversationList: [] as Conversation[],
		archivedConversations: [] as Conversation[],

		// UI state
		loading: false,
		messagesLoading: false,
		error: null as string | null,
		sidebarCollapsed: false,
		showArchive: false,

		// Pagination
		pagination: {
			page: 1,
			limit: 50,
			total: 0,
		},
		messagePagination: {
			page: 1,
			limit: 50,
			total: 0,
		},
	}),

	getters: {
		getActiveConversation: (state) => state.activeConversation,
		getActiveMessages: (state) => state.activeConversationMessages,
		getConversationList: (state) => state.conversationList,
		getArchivedConversations: (state) => state.archivedConversations,
		isLoading: (state) => state.loading,
		getError: (state) => state.error,
		isSidebarCollapsed: (state) => state.sidebarCollapsed,
		isShowingArchive: (state) => state.showArchive,
	},

	actions: {
		/**
		 * Toggle sidebar collapsed state
		 *
		 * @spec exclude Pure client UI-state toggle — chat sidebar collapse. No backend contract.
		 */
		toggleSidebar() {
			this.sidebarCollapsed = !this.sidebarCollapsed
		},

		/**
		 * Toggle archive view
		 *
		 * @spec exclude Pure client UI-state toggle — archive view switch (lazily refreshes archived list). No backend contract.
		 */
		toggleArchive() {
			this.showArchive = !this.showArchive
			if (this.showArchive) {
				this.refreshArchivedConversations()
			}
		},

		/**
		 * Set active conversation
		 *
		 * @param {object | null} conversation - The conversation to set as active
		 *
		 * @spec exclude Client state mutator — wraps the active conversation in an entity. No backend contract.
		 */
		setActiveConversation(conversation: TConversation | null) {
			this.activeConversation = conversation ? new Conversation(conversation) : null
		},

		/**
		 * Set messages for active conversation
		 *
		 * @param {Array} messages - Array of messages
		 *
		 * @spec exclude Client state mutator — maps the active conversation's messages to entities. No backend contract.
		 */
		setActiveMessages(messages: TMessage[]) {
			this.activeConversationMessages = messages.map((msg) => new Message(msg))
		},

		/**
		 * Add a message to the active conversation
		 *
		 * @param {object} message - Message to add
		 *
		 * @spec exclude Client state mutator — appends a message entity to the active conversation. No backend contract.
		 */
		addMessage(message: TMessage) {
			const newMessage = new Message(message)
			this.activeConversationMessages.push(newMessage)
		},

		/**
		 * Set conversation list
		 *
		 * @param {Array} conversations - Array of conversations
		 *
		 * @spec exclude Client state mutator — maps the conversation list to entities. No backend contract.
		 */
		setConversationList(conversations: TConversation[]) {
			this.conversationList = conversations.map((conv) => new Conversation(conv))
		},

		/**
		 * Refresh the conversation list from API
		 *
		 * @param {boolean} soft - If true, don't show loading state
		 * @return {Promise} Promise with response and data
		 *
		 * @spec exclude Thin API passthrough — GET /api/conversations list; observable contract owned by chat-ai.
		 */
		async refreshConversationList(soft = false) {
			if (!soft) {
				this.loading = true
			}
			this.error = null

			try {
				const { page, limit } = this.pagination
				const offset = (page - 1) * limit
				const endpoint = `/index.php/apps/openregister/api/conversations?limit=${limit}&offset=${offset}`

				const response = await fetch(endpoint, {
					method: 'GET',
				})

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				const data = await response.json()

				this.setConversationList(data.results || [])
				this.pagination.total = data.total || 0

				return { response, data }
			} catch (error: any) {
				console.error('Error fetching conversations:', error)
				this.error = error.message
				throw error
			} finally {
				if (!soft) {
					this.loading = false
				}
			}
		},

		/**
		 * Refresh archived conversations
		 *
		 * @return {Promise} Promise with response and data
		 *
		 * @spec exclude Thin API passthrough — GET /api/conversations?_deleted=true; observable contract owned by chat-ai.
		 */
		async refreshArchivedConversations() {
			this.loading = true
			this.error = null

			try {
				const { page, limit } = this.pagination
				const offset = (page - 1) * limit
				// Use _deleted=true query parameter to get archived conversations
				const endpoint = `/index.php/apps/openregister/api/conversations?_deleted=true&limit=${limit}&offset=${offset}`

				const response = await fetch(endpoint, {
					method: 'GET',
				})

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				const data = await response.json()

				this.archivedConversations = (data.results || []).map((conv: TConversation) => new Conversation(conv))

				return { response, data }
			} catch (error: any) {
				console.error('Error fetching archived conversations:', error)
				this.error = error.message
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Load a conversation by UUID
		 *
		 * @param {string} uuid - Conversation UUID
		 * @return {Promise} Promise with conversation data
		 *
		 * @spec exclude Thin API passthrough — GET /api/conversations/{uuid}; observable contract owned by chat-ai.
		 */
		async loadConversation(uuid: string) {
			this.loading = true
			this.error = null

			try {
				const endpoint = `/index.php/apps/openregister/api/conversations/${uuid}`

				const response = await fetch(endpoint, {
					method: 'GET',
				})

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				const data = await response.json()

				this.setActiveConversation(data)
				// Messages are now loaded separately
				this.setActiveMessages([])

				// Load messages separately
				await this.loadMessages(uuid)

				return data
			} catch (error: any) {
				console.error('Error loading conversation:', error)
				this.error = error.message
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Load messages for the active conversation
		 *
		 * @param {string} uuid - Conversation UUID
		 * @param {number} limit - Number of messages to load (default: 50)
		 * @param {number} offset - Offset for pagination (default: 0)
		 * @return {Promise} Promise with messages data
		 *
		 * @spec exclude Thin API passthrough — GET /api/conversations/{uuid}/messages; observable contract owned by chat-ai.
		 */
		async loadMessages(uuid: string, limit = 50, offset = 0) {
			this.messagesLoading = true
			this.error = null

			try {
				const endpoint = `/index.php/apps/openregister/api/conversations/${uuid}/messages?limit=${limit}&offset=${offset}`

				const response = await fetch(endpoint, {
					method: 'GET',
				})

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				const data = await response.json()

				// If offset is 0, replace messages; otherwise prepend (for loading older messages)
				if (offset === 0) {
					this.setActiveMessages(data.results || [])
				} else {
					// Prepend older messages
					this.activeConversationMessages = [
						...(data.results || []).map((msg: TMessage) => new Message(msg)),
						...this.activeConversationMessages,
					]
				}

				// Update pagination
				this.messagePagination.total = data.total || 0
				this.messagePagination.limit = data.limit || 50

				return data
			} catch (error: any) {
				console.error('Error loading messages:', error)
				this.error = error.message
				throw error
			} finally {
				this.messagesLoading = false
			}
		},

		/**
		 * Create a new conversation
		 *
		 * @param {string} agentUuid - Agent UUID
		 * @param {string} title - Optional conversation title
		 * @return {Promise} Promise with new conversation data
		 *
		 * @spec exclude Thin API passthrough — POST /api/conversations; observable contract owned by chat-ai.
		 */
		async createConversation(agentUuid: string, title?: string) {
			this.loading = true
			this.error = null

			try {
				const endpoint = '/index.php/apps/openregister/api/conversations'

				const response = await fetch(endpoint, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify({
						agentUuid,
						title,
					}),
				})

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				const data = await response.json()

				this.setActiveConversation(data)
				this.setActiveMessages([])
				await this.refreshConversationList(true)

				return data
			} catch (error: any) {
				console.error('Error creating conversation:', error)
				this.error = error.message
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Update a conversation
		 *
		 * @param {string} uuid - Conversation UUID
		 * @param {object} updates - Fields to update
		 * @return {Promise} Promise with updated conversation data
		 *
		 * @spec exclude Thin API passthrough — PATCH /api/conversations/{uuid}; observable contract owned by chat-ai.
		 */
		async updateConversation(uuid: string, updates: Partial<TConversation>) {
			this.loading = true
			this.error = null

			try {
				const endpoint = `/index.php/apps/openregister/api/conversations/${uuid}`

				const response = await fetch(endpoint, {
					method: 'PATCH',
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify(updates),
				})

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				const data = await response.json()

				// Update active conversation if it's the one being updated
				if (this.activeConversation?.uuid === uuid) {
					this.setActiveConversation(data)
				}

				await this.refreshConversationList(true)

				return data
			} catch (error: any) {
				console.error('Error updating conversation:', error)
				this.error = error.message
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Archive a conversation (soft delete)
		 *
		 * @param {string} uuid - Conversation UUID
		 * @return {Promise} Promise with response
		 *
		 * @spec exclude Thin API passthrough — DELETE /api/conversations/{uuid} (soft-archive); observable contract owned by chat-ai.
		 */
		async archiveConversation(uuid: string) {
			this.loading = true
			this.error = null

			try {
				const endpoint = `/index.php/apps/openregister/api/conversations/${uuid}`

				const response = await fetch(endpoint, {
					method: 'DELETE',
				})

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				// Clear active conversation if it's the one being archived
				if (this.activeConversation?.uuid === uuid) {
					this.setActiveConversation(null)
					this.setActiveMessages([])
				}

				// Refresh both active and archived lists
				await this.refreshConversationList(true)
				await this.refreshArchivedConversations()

				return response
			} catch (error: any) {
				console.error('Error archiving conversation:', error)
				this.error = error.message
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Delete a conversation (soft delete) - alias for archiveConversation
		 *
		 * @param {string} uuid - Conversation UUID
		 * @return {Promise} Promise with response
		 *
		 * @spec exclude Client-side alias of archiveConversation; delegates to a chat-ai passthrough. No standalone backend contract.
		 */
		async deleteConversation(uuid: string) {
			return this.archiveConversation(uuid)
		},

		/**
		 * Restore a soft-deleted conversation
		 *
		 * @param {string} uuid - Conversation UUID
		 * @return {Promise} Promise with restored conversation data
		 *
		 * @spec exclude Thin API passthrough — POST /api/conversations/{uuid}/restore; observable contract owned by chat-ai.
		 */
		async restoreConversation(uuid: string) {
			this.loading = true
			this.error = null

			try {
				const endpoint = `/index.php/apps/openregister/api/conversations/${uuid}/restore`

				const response = await fetch(endpoint, {
					method: 'POST',
				})

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				const data = await response.json()

				await this.refreshConversationList(true)
				await this.refreshArchivedConversations()

				return data
			} catch (error: any) {
				console.error('Error restoring conversation:', error)
				this.error = error.message
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Permanently delete a conversation
		 *
		 * @param {string} uuid - Conversation UUID
		 * @return {Promise} Promise with response
		 *
		 * @spec exclude Thin API passthrough — DELETE /api/conversations/{uuid}/permanent; observable contract owned by chat-ai.
		 */
		async deleteConversationPermanent(uuid: string) {
			this.loading = true
			this.error = null

			try {
				const endpoint = `/index.php/apps/openregister/api/conversations/${uuid}/permanent`

				const response = await fetch(endpoint, {
					method: 'DELETE',
				})

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				await this.refreshArchivedConversations()

				return response
			} catch (error: any) {
				console.error('Error permanently deleting conversation:', error)
				this.error = error.message
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Send a message in the active conversation
		 *
		 * @param {string} content - Message content
		 * @param {string} conversationUuid - Optional conversation UUID (creates new if not provided)
		 * @param {string} agentUuid - Optional agent UUID (required if creating new conversation)
		 * @param {Array<string>} selectedViews - Array of selected view UUIDs for RAG context
		 * @param {Array<string>} selectedTools - Array of selected tool UUIDs for agent capabilities
		 * @param {object} ragSettings - RAG (Retrieval Augmented Generation) configuration settings
		 * @param {boolean} ragSettings.includeObjects - Whether to include objects in RAG context
		 * @param {boolean} ragSettings.includeFiles - Whether to include files in RAG context
		 * @param {number} ragSettings.numSourcesFiles - Number of file sources to retrieve
		 * @param {number} ragSettings.numSourcesObjects - Number of object sources to retrieve
		 * @return {Promise} Promise with response data
		 */
		async sendMessage(
			content: string,
			conversationUuid?: string,
			agentUuid?: string,
			selectedViews?: string[],
			selectedTools?: string[],
			ragSettings?: {
				includeObjects?: boolean,
				includeFiles?: boolean,
				numSourcesFiles?: number,
				numSourcesObjects?: number
			}
		) {
			this.loading = true
			this.error = null

			// Optimistically add user message to UI
			if (conversationUuid || this.activeConversation) {
				const userMessage: TMessage = {
					id: Date.now(), // Temporary ID
					uuid: 'temp-' + Date.now(),
					conversationId: this.activeConversation?.id || 0,
					role: 'user',
					content,
					sources: null,
					created: new Date().toISOString(),
				}
				this.addMessage(userMessage)
			}

			try {
				const endpoint = '/index.php/apps/openregister/api/chat/send'

				const payload: any = {
					message: content,
				}

				// If we have a conversation, just send that (it already has the agent attached)
				if (conversationUuid) {
					payload.conversation = conversationUuid
				} else if (agentUuid) {
					// Only send agentUuid if we don't have a conversation yet
					payload.agentUuid = agentUuid
				}

				// Include selected views and tools if provided
				if (selectedViews && selectedViews.length > 0) {
					payload.views = selectedViews
				}
				if (selectedTools && selectedTools.length > 0) {
					payload.tools = selectedTools
				}

				// Include RAG settings if provided
				if (ragSettings) {
					if (ragSettings.includeObjects !== undefined) {
						payload.includeObjects = ragSettings.includeObjects
					}
					if (ragSettings.includeFiles !== undefined) {
						payload.includeFiles = ragSettings.includeFiles
					}
					if (ragSettings.numSourcesFiles !== undefined) {
						payload.numSourcesFiles = ragSettings.numSourcesFiles
					}
					if (ragSettings.numSourcesObjects !== undefined) {
						payload.numSourcesObjects = ragSettings.numSourcesObjects
					}
				}

				const response = await fetch(endpoint, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify(payload),
				})

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				const data = await response.json()

				// Update active conversation with new conversation UUID if it was created
				if (data.conversation && !this.activeConversation) {
					await this.loadConversation(data.conversation)
				} else {
				// The API returns only the assistant message
				// Add the assistant message to active conversation
					if (data.message) {
						this.addMessage(data.message)
					}

					// Update conversation title if it was generated
					if (data.title && this.activeConversation) {
					// Use Object.assign to ensure reactivity
						Object.assign(this.activeConversation, { title: data.title })

						// Also update in the conversation list
						const conversationInList = this.conversationList.find(c => c.uuid === this.activeConversation.uuid)
						if (conversationInList) {
							conversationInList.title = data.title
						}
					}
				}

				// Soft refresh the conversation list to update metadata (but keep title changes)
				await this.refreshConversationList(true)

				return data
			} catch (error: any) {
				console.error('Error sending message:', error)
				this.error = error.message
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Clear active conversation
		 *
		 * @spec exclude Pure client UI-state mutator — resets active conversation/messages. No backend contract.
		 */
		clearActiveConversation() {
			this.setActiveConversation(null)
			this.setActiveMessages([])
		},

		/**
		 * Set pagination
		 *
		 * @param {number} page - Page number
		 * @param {number} limit - Items per page
		 *
		 * @spec exclude Pure client UI-state setter — conversation list pagination cursor. No backend contract.
		 */
		setPagination(page: number, limit = 50) {
			this.pagination = { ...this.pagination, page, limit }
		},
	},
})
