/* eslint-disable no-console */
import { setActivePinia, createPinia } from 'pinia'

import { useConversationStore } from './conversation.js'

// Mock the entity imports
jest.mock('../../entities/conversation/index.ts', () => {
	class Conversation {

		constructor(data) {
			Object.assign(this, data)
		}

	}
	return { Conversation }
})

jest.mock('../../entities/message/index.ts', () => {
	class Message {

		constructor(data) {
			Object.assign(this, data)
		}

	}
	return { Message }
})

describe('Conversation Store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		jest.clearAllMocks()
	})

	it('has correct initial state', () => {
		const store = useConversationStore()

		expect(store.item).toBeNull()
		expect(store.list).toEqual([])
		expect(store.activeConversationMessages).toEqual([])
		expect(store.archivedConversations).toEqual([])
		expect(store.messagesLoading).toBe(false)
		expect(store.sidebarCollapsed).toBe(false)
		expect(store.showArchive).toBe(false)
	})

	it('has loading feature', () => {
		const store = useConversationStore()

		expect(store.loading).toBe(false)
		expect(store.error).toBeNull()
	})

	it('sets item correctly', () => {
		const store = useConversationStore()
		const data = { uuid: 'conv-1', title: 'Test Conversation' }

		store.setItem(data)

		expect(store.item).toBeDefined()
		expect(store.item.title).toBe('Test Conversation')
	})

	it('handles null item', () => {
		const store = useConversationStore()

		store.setItem({ uuid: 'conv-1' })
		store.setItem(null)

		expect(store.item).toBeNull()
	})

	it('sets active messages', () => {
		const store = useConversationStore()
		const messages = [
			{ id: 1, role: 'user', content: 'Hello' },
			{ id: 2, role: 'assistant', content: 'Hi there' },
		]

		store.setActiveMessages(messages)

		expect(store.activeConversationMessages).toHaveLength(2)
		expect(store.activeConversationMessages[0].content).toBe('Hello')
	})

	it('adds a message', () => {
		const store = useConversationStore()

		store.setActiveMessages([])
		store.addMessage({ id: 1, role: 'user', content: 'Hello' })

		expect(store.activeConversationMessages).toHaveLength(1)
		expect(store.activeConversationMessages[0].content).toBe('Hello')
	})

	it('toggles sidebar', () => {
		const store = useConversationStore()

		expect(store.sidebarCollapsed).toBe(false)
		store.toggleSidebar()
		expect(store.sidebarCollapsed).toBe(true)
		store.toggleSidebar()
		expect(store.sidebarCollapsed).toBe(false)
	})

	it('clears active conversation', () => {
		const store = useConversationStore()

		store.setItem({ uuid: 'conv-1', title: 'Test' })
		store.setActiveMessages([{ id: 1, content: 'Hello' }])

		store.clearActiveConversation()

		expect(store.item).toBeNull()
		expect(store.activeConversationMessages).toEqual([])
	})

	it('has convenience getters', () => {
		const store = useConversationStore()
		const data = { uuid: 'conv-1', title: 'Test' }

		store.setItem(data)
		store.setList([data])

		expect(store.getActiveConversation).toBeDefined()
		expect(store.getActiveConversation.title).toBe('Test')
		expect(store.getConversationList).toHaveLength(1)
	})

	describe('refreshList', () => {
		it('fetches conversations with pagination', async () => {
			const store = useConversationStore()

			global.fetch = jest.fn().mockResolvedValue({
				ok: true,
				json: () => Promise.resolve({
					results: [{ uuid: 'conv-1', title: 'Conv 1' }],
					total: 1,
				}),
			})

			await store.refreshList()

			expect(store.list).toHaveLength(1)
			expect(store.loading).toBe(false)
		})

		it('sets error on failure', async () => {
			const store = useConversationStore()

			global.fetch = jest.fn().mockResolvedValue({
				ok: false,
				status: 500,
			})

			await expect(store.refreshList()).rejects.toThrow()
			expect(store.error).toBeTruthy()
			expect(store.loading).toBe(false)
		})
	})

	describe('save (create conversation)', () => {
		it('creates a new conversation', async () => {
			const store = useConversationStore()
			store.refreshList = jest.fn().mockResolvedValue({})

			global.fetch = jest.fn().mockResolvedValue({
				ok: true,
				json: () => Promise.resolve({ uuid: 'new-conv', title: 'New' }),
			})

			const data = await store.save('agent-uuid', 'New Conversation')

			expect(global.fetch).toHaveBeenCalledTimes(1)
			const [, options] = global.fetch.mock.calls[0]
			expect(options.method).toBe('POST')
			const body = JSON.parse(options.body)
			expect(body.agentUuid).toBe('agent-uuid')
			expect(body.title).toBe('New Conversation')
			expect(data.uuid).toBe('new-conv')
		})
	})

	describe('deleteOne', () => {
		it('soft deletes a conversation by uuid', async () => {
			const store = useConversationStore()
			store.refreshList = jest.fn().mockResolvedValue({})
			store.refreshArchivedConversations = jest.fn().mockResolvedValue({})

			global.fetch = jest.fn().mockResolvedValue({
				ok: true,
				json: () => Promise.resolve({}),
			})

			await store.deleteOne('conv-uuid')

			expect(global.fetch).toHaveBeenCalledTimes(1)
			const [url, options] = global.fetch.mock.calls[0]
			expect(url).toContain('/conv-uuid')
			expect(options.method).toBe('DELETE')
		})

		it('accepts object with uuid', async () => {
			const store = useConversationStore()
			store.refreshList = jest.fn().mockResolvedValue({})
			store.refreshArchivedConversations = jest.fn().mockResolvedValue({})

			global.fetch = jest.fn().mockResolvedValue({
				ok: true,
				json: () => Promise.resolve({}),
			})

			await store.deleteOne({ uuid: 'conv-uuid' })

			const [url] = global.fetch.mock.calls[0]
			expect(url).toContain('/conv-uuid')
		})
	})
})
