/**
 * Conversation Store Module
 *
 * @category Store
 * @package  openregister
 * @author   Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license  EUPL-1.2
 * @version  1.0.0
 * @link     https://www.openregister.nl
 */

/* eslint-disable no-console */
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
		error: null as string | null,
		sidebarCollapsed: false,
		showArchive: false,
		
		// Pagination
		pagination: {
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
		 */
		toggleSidebar() {
			this.sidebarCollapsed = !this.sidebarCollapsed
			console.log('Sidebar collapsed:', this.sidebarCollapsed)
		},
		
		/**
		 * Toggle archive view
		 */
		toggleArchive() {
			this.showArchive = !this.showArchive
			console.log('Show archive:', this.showArchive)
			if (this.showArchive) {
				this.refreshArchivedConversations()
			}
		},
		
		/**
		 * Set active conversation
		 *
		 * @param {TConversation|null} conversation - The conversation to set as active
		 */
		setActiveConversation(conversation: TConversation | null) {
			this.activeConversation = conversation ? new Conversation(conversation) : null
			console.log('Active conversation set:', conversation?.uuid || 'null')
		},
		
		/**
		 * Set messages for active conversation
		 *
		 * @param {TMessage[]} messages - Array of messages
		 */
		setActiveMessages(messages: TMessage[]) {
			this.activeConversationMessages = messages.map((msg) => new Message(msg))
			console.log('Active conversation messages set:', messages.length, 'messages')
		},
		
		/**
		 * Add a message to the active conversation
		 *
		 * @param {TMessage} message - Message to add
		 */
		addMessage(message: TMessage) {
			const newMessage = new Message(message)
			this.activeConversationMessages.push(newMessage)
			console.log('Message added to active conversation')
		},
		
		/**
		 * Set conversation list
		 *
		 * @param {TConversation[]} conversations - Array of conversations
		 */
		setConversationList(conversations: TConversation[]) {
			this.conversationList = conversations.map((conv) => new Conversation(conv))
			console.log('Conversation list set:', conversations.length, 'conversations')
		},
		
		/**
		 * Refresh the conversation list from API
		 *
		 * @param {boolean} soft - If true, don't show loading state
		 * @returns {Promise} Promise with response and data
		 */
		async refreshConversationList(soft = false) {
			console.log('ConversationStore: Starting refreshConversationList (soft=' + soft + ')')
			
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
				
				console.log('ConversationStore: refreshConversationList completed, got', data.results?.length || 0, 'conversations')
				
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
		 * @returns {Promise} Promise with response and data
		 */
		async refreshArchivedConversations() {
			console.log('ConversationStore: Starting refreshArchivedConversations')
			
			this.loading = true
			this.error = null
			
			try {
				const endpoint = '/index.php/apps/openregister/api/conversations/archive'
				
				const response = await fetch(endpoint, {
					method: 'GET',
				})
				
				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}
				
				const data = await response.json()
				
				this.archivedConversations = (data.results || []).map((conv: TConversation) => new Conversation(conv))
				
				console.log('ConversationStore: refreshArchivedConversations completed, got', data.results?.length || 0, 'conversations')
				
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
		 * @returns {Promise} Promise with conversation data
		 */
		async loadConversation(uuid: string) {
			console.log('ConversationStore: Loading conversation', uuid)
			
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
				this.setActiveMessages(data.messages || [])
				
				console.log('ConversationStore: Conversation loaded successfully')
				
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
		 * Create a new conversation
		 *
		 * @param {string} agentUuid - Agent UUID
		 * @param {string} title - Optional conversation title
		 * @returns {Promise} Promise with new conversation data
		 */
		async createConversation(agentUuid: string, title?: string) {
			console.log('ConversationStore: Creating conversation with agent', agentUuid)
			
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
				
				console.log('ConversationStore: Conversation created successfully')
				
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
		 * @param {Partial<TConversation>} updates - Fields to update
		 * @returns {Promise} Promise with updated conversation data
		 */
		async updateConversation(uuid: string, updates: Partial<TConversation>) {
			console.log('ConversationStore: Updating conversation', uuid)
			
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
				
				console.log('ConversationStore: Conversation updated successfully')
				
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
		 * @returns {Promise} Promise with response
		 */
		async archiveConversation(uuid: string) {
			console.log('ConversationStore: Archiving conversation', uuid)
			
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
				
				await this.refreshConversationList(true)
				
				console.log('ConversationStore: Conversation archived successfully')
				
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
		 * @returns {Promise} Promise with response
		 */
		async deleteConversation(uuid: string) {
			return this.archiveConversation(uuid)
		},
		
		/**
		 * Restore a soft-deleted conversation
		 *
		 * @param {string} uuid - Conversation UUID
		 * @returns {Promise} Promise with restored conversation data
		 */
		async restoreConversation(uuid: string) {
			console.log('ConversationStore: Restoring conversation', uuid)
			
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
				
				console.log('ConversationStore: Conversation restored successfully')
				
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
		 * @returns {Promise} Promise with response
		 */
		async deleteConversationPermanent(uuid: string) {
			console.log('ConversationStore: Permanently deleting conversation', uuid)
			
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
				
				console.log('ConversationStore: Conversation permanently deleted')
				
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
		 * @returns {Promise} Promise with response data
		 */
		async sendMessage(content: string, conversationUuid?: string, agentUuid?: string) {
			console.log('ConversationStore: Sending message')
			
			this.loading = true
			this.error = null
			
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
					// Add messages to active conversation
					if (data.userMessage) {
						this.addMessage(data.userMessage)
					}
					if (data.assistantMessage) {
						this.addMessage(data.assistantMessage)
					}
				}
				
				// Soft refresh the conversation list to update metadata
				await this.refreshConversationList(true)
				
				console.log('ConversationStore: Message sent successfully')
				
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
		 */
		clearActiveConversation() {
			this.setActiveConversation(null)
			this.setActiveMessages([])
			console.log('Active conversation cleared')
		},
		
		/**
		 * Set pagination
		 *
		 * @param {number} page - Page number
		 * @param {number} limit - Items per page
		 */
		setPagination(page: number, limit = 50) {
			this.pagination = { ...this.pagination, page, limit }
			console.info('Pagination set to', { page, limit })
		},
	},
})

