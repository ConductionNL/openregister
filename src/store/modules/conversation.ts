/* eslint-disable no-console */
// @ts-expect-error — createCrudStore is JS-only; full TS types will be added to the package later
import { createCrudStore } from '@conduction/nextcloud-vue'
import { Conversation } from '../../entities/conversation/index'
import { Message } from '../../entities/message/index'

export const useConversationStore = createCrudStore('conversation', {
	endpoint: 'conversations',
	entity: Conversation,
	features: { loading: true },
	extend: {
		state: () => ({
			activeConversationMessages: [],
			archivedConversations: [],
			messagesLoading: false,
			sidebarCollapsed: false,
			showArchive: false,
			messagePagination: { page: 1, limit: 50, total: 0 },
		}),
		getters: {
			getActiveConversation: (state) => state.item,
			getActiveMessages: (state) => state.activeConversationMessages,
			getConversationList: (state) => state.list,
			getArchivedConversations: (state) => state.archivedConversations,
			isSidebarCollapsed: (state) => state.sidebarCollapsed,
			isShowingArchive: (state) => state.showArchive,
		},
		actions: {
			toggleSidebar() {
				this.sidebarCollapsed = !this.sidebarCollapsed
			},
			toggleArchive() {
				this.showArchive = !this.showArchive
				if (this.showArchive) this.refreshArchivedConversations()
			},
			setActiveMessages(messages) {
				this.activeConversationMessages = messages.map((msg) => new Message(msg))
			},
			addMessage(message) {
				this.activeConversationMessages.push(new Message(message))
			},
			// Override refreshList — uses pagination params
			async refreshList(soft = false) {
				if (!soft) this.loading = true
				this.error = null
				try {
					const { page, limit } = this.pagination
					const offset = (page - 1) * limit
					const endpoint = `${this._options.baseApiUrl}?limit=${limit}&offset=${offset}`
					const response = await fetch(endpoint, { method: 'GET' })
					if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`)
					const data = await response.json()
					this.setList(data.results || [])
					this.pagination.total = data.total || 0
					return { response, data }
				} catch (error) {
					this.error = error.message
					throw error
				} finally {
					if (!soft) this.loading = false
				}
			},
			async refreshArchivedConversations() {
				this.loading = true
				this.error = null
				try {
					const { page, limit } = this.pagination
					const offset = (page - 1) * limit
					const endpoint = `${this._options.baseApiUrl}?_deleted=true&limit=${limit}&offset=${offset}`
					const response = await fetch(endpoint, { method: 'GET' })
					if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`)
					const data = await response.json()
					this.archivedConversations = (data.results || []).map((conv) => new Conversation(conv))
					return { response, data }
				} catch (error) {
					this.error = error.message
					throw error
				} finally {
					this.loading = false
				}
			},
			// Override getOne — also loads messages
			async getOne(uuid) {
				this.loading = true
				this.error = null
				try {
					const response = await fetch(`${this._options.baseApiUrl}/${uuid}`, { method: 'GET' })
					if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`)
					const data = await response.json()
					this.setItem(data)
					this.setActiveMessages([])
					await this.loadMessages(uuid)
					return data
				} catch (error) {
					this.error = error.message
					throw error
				} finally {
					this.loading = false
				}
			},
			async loadMessages(uuid, limit = 50, offset = 0) {
				this.messagesLoading = true
				this.error = null
				try {
					const endpoint = `${this._options.baseApiUrl}/${uuid}/messages?limit=${limit}&offset=${offset}`
					const response = await fetch(endpoint, { method: 'GET' })
					if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`)
					const data = await response.json()
					if (offset === 0) {
						this.setActiveMessages(data.results || [])
					} else {
						this.activeConversationMessages = [
							...(data.results || []).map((msg) => new Message(msg)),
							...this.activeConversationMessages,
						]
					}
					this.messagePagination.total = data.total || 0
					this.messagePagination.limit = data.limit || 50
					return data
				} catch (error) {
					this.error = error.message
					throw error
				} finally {
					this.messagesLoading = false
				}
			},
			// Override save — createConversation has different params
			async save(agentUuid, title) {
				this.loading = true
				this.error = null
				try {
					const response = await fetch(this._options.baseApiUrl, {
						method: 'POST',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify({ agentUuid, title }),
					})
					if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`)
					const data = await response.json()
					this.setItem(data)
					this.setActiveMessages([])
					await this.refreshList(true)
					return data
				} catch (error) {
					this.error = error.message
					throw error
				} finally {
					this.loading = false
				}
			},
			async updateConversation(uuid, updates) {
				this.loading = true
				this.error = null
				try {
					const response = await fetch(`${this._options.baseApiUrl}/${uuid}`, {
						method: 'PATCH',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify(updates),
					})
					if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`)
					const data = await response.json()
					if (this.item?.uuid === uuid) this.setItem(data)
					await this.refreshList(true)
					return data
				} catch (error) {
					this.error = error.message
					throw error
				} finally {
					this.loading = false
				}
			},
			// Override deleteOne — soft delete (archive)
			async deleteOne(uuid) {
				this.loading = true
				this.error = null
				try {
					const itemUuid = typeof uuid === 'object' ? uuid.uuid : uuid
					const response = await fetch(`${this._options.baseApiUrl}/${itemUuid}`, { method: 'DELETE' })
					if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`)
					if (this.item?.uuid === itemUuid) {
						this.setItem(null)
						this.setActiveMessages([])
					}
					await this.refreshList(true)
					await this.refreshArchivedConversations()
					return response
				} catch (error) {
					this.error = error.message
					throw error
				} finally {
					this.loading = false
				}
			},
			async restoreConversation(uuid) {
				this.loading = true
				this.error = null
				try {
					const response = await fetch(`${this._options.baseApiUrl}/${uuid}/restore`, { method: 'POST' })
					if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`)
					const data = await response.json()
					await this.refreshList(true)
					await this.refreshArchivedConversations()
					return data
				} catch (error) {
					this.error = error.message
					throw error
				} finally {
					this.loading = false
				}
			},
			async deleteConversationPermanent(uuid) {
				this.loading = true
				this.error = null
				try {
					const response = await fetch(`${this._options.baseApiUrl}/${uuid}/permanent`, { method: 'DELETE' })
					if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`)
					await this.refreshArchivedConversations()
					return response
				} catch (error) {
					this.error = error.message
					throw error
				} finally {
					this.loading = false
				}
			},
			async sendMessage(content, conversationUuid, agentUuid, selectedViews, selectedTools, ragSettings) {
				this.loading = true
				this.error = null
				if (conversationUuid || this.item) {
					this.addMessage({
						id: Date.now(),
						uuid: 'temp-' + Date.now(),
						conversationId: this.item?.id || 0,
						role: 'user',
						content,
						sources: null,
						created: new Date().toISOString(),
					})
				}
				try {
					const payload = { message: content }
					if (conversationUuid) payload.conversation = conversationUuid
					else if (agentUuid) payload.agentUuid = agentUuid
					if (selectedViews?.length > 0) payload.views = selectedViews
					if (selectedTools?.length > 0) payload.tools = selectedTools
					if (ragSettings) {
						if (ragSettings.includeObjects !== undefined) payload.includeObjects = ragSettings.includeObjects
						if (ragSettings.includeFiles !== undefined) payload.includeFiles = ragSettings.includeFiles
						if (ragSettings.numSourcesFiles !== undefined) payload.numSourcesFiles = ragSettings.numSourcesFiles
						if (ragSettings.numSourcesObjects !== undefined) payload.numSourcesObjects = ragSettings.numSourcesObjects
					}
					const response = await fetch('/index.php/apps/openregister/api/chat/send', {
						method: 'POST',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify(payload),
					})
					if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`)
					const data = await response.json()
					if (data.conversation && !this.item) {
						await this.getOne(data.conversation)
					} else {
						if (data.message) this.addMessage(data.message)
						if (data.title && this.item) {
							Object.assign(this.item, { title: data.title })
							const conversationInList = this.list.find(c => c.uuid === this.item.uuid)
							if (conversationInList) conversationInList.title = data.title
						}
					}
					await this.refreshList(true)
					return data
				} catch (error) {
					this.error = error.message
					throw error
				} finally {
					this.loading = false
				}
			},
			clearActiveConversation() {
				this.setItem(null)
				this.setActiveMessages([])
			},
		},
	},
})
