/* eslint-disable no-console */
import { setActivePinia, createPinia } from 'pinia'

import { useApplicationStore } from './application.js'
import { Application, mockApplication } from '../../entities/index.js'

describe('Application Store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('sets item correctly', () => {
		const store = useApplicationStore()
		const mockData = mockApplication()

		store.setItem(mockData)

		expect(store.item).toBeInstanceOf(Application)
		expect(store.item.validate().success).toBe(true)
	})

	it('sets list correctly', () => {
		const store = useApplicationStore()
		const mockData = [mockApplication()]

		store.setList(mockData)

		expect(store.list).toHaveLength(1)
		expect(store.list[0]).toBeInstanceOf(Application)
	})

	it('handles null item correctly', () => {
		const store = useApplicationStore()

		store.setItem(null)

		expect(store.item).toBeNull()
	})

	it('has correct initial state', () => {
		const store = useApplicationStore()

		expect(store.item).toBeNull()
		expect(store.list).toEqual([])
		expect(store.filters).toEqual({})
		expect(store.pagination).toEqual({ page: 1, limit: 20 })
		expect(store.nextcloudGroups).toEqual([])
	})

	it('has loading and viewMode features', () => {
		const store = useApplicationStore()

		expect(store.loading).toBe(false)
		expect(store.error).toBeNull()
		expect(store.viewMode).toBe('cards')
	})

	it('cleanForSave strips configured fields including usage and owner', () => {
		const store = useApplicationStore()
		const item = {
			id: 1,
			uuid: 'test-uuid',
			name: 'Test App',
			usage: { calls: 100 },
			owner: 'admin',
			created: '2024-01-01',
			updated: '2024-01-02',
		}

		const cleaned = store.cleanForSave(item)

		expect(cleaned.name).toBe('Test App')
		expect(cleaned.id).toBeUndefined()
		expect(cleaned.uuid).toBeUndefined()
		expect(cleaned.usage).toBeUndefined()
		expect(cleaned.owner).toBeUndefined()
		expect(cleaned.created).toBeUndefined()
		expect(cleaned.updated).toBeUndefined()
	})

	it('cleanForSave handles boolean coercion for active field', () => {
		const store = useApplicationStore()
		const item = { name: 'Test', active: '' }

		const cleaned = store.cleanForSave(item)

		expect(cleaned.active).toBe(true)
	})

	it('cleanForSave converts truthy active to boolean', () => {
		const store = useApplicationStore()
		const item = { name: 'Test', active: 'yes' }

		const cleaned = store.cleanForSave(item)

		expect(cleaned.active).toBe(true)
	})

	describe('loadNextcloudGroups', () => {
		it('loads groups from OCS API', async () => {
			const store = useApplicationStore()

			global.fetch = jest.fn().mockResolvedValue({
				ok: true,
				json: () => Promise.resolve({
					ocs: { data: { groups: ['admin', 'users', 'editors'] } },
				}),
			})

			await store.loadNextcloudGroups()

			expect(store.nextcloudGroups).toHaveLength(3)
			expect(store.nextcloudGroups[0]).toEqual({ id: 'admin', name: 'admin', userCount: 0 })
		})

		it('handles failed response gracefully', async () => {
			const store = useApplicationStore()

			global.fetch = jest.fn().mockResolvedValue({
				ok: false,
				statusText: 'Forbidden',
			})

			await store.loadNextcloudGroups()

			expect(store.nextcloudGroups).toEqual([])
		})

		it('handles network error gracefully', async () => {
			const store = useApplicationStore()

			global.fetch = jest.fn().mockRejectedValue(new Error('Network error'))

			await store.loadNextcloudGroups()

			expect(store.nextcloudGroups).toEqual([])
		})
	})
})
