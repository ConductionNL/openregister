/* eslint-disable no-console */
import { setActivePinia, createPinia } from 'pinia'

import { useOrganisationStore } from './organisation.js'
import { Organisation, mockOrganisation } from '../../entities/index.js'

describe('Organisation Store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('sets item correctly', () => {
		const store = useOrganisationStore()
		const mockData = mockOrganisation()

		store.setItem(mockData[0])

		expect(store.item).toBeInstanceOf(Organisation)
		expect(store.item).toEqual(mockData[0])
		expect(store.item.validate().success).toBe(true)
	})

	it('sets active organisation correctly', () => {
		const store = useOrganisationStore()
		const testOrg = mockOrganisation()[1]

		store.setActiveOrganisation(testOrg)

		expect(store.activeOrganisation).toBeInstanceOf(Organisation)
		expect(store.activeOrganisation.name).toBe(testOrg.name)
		expect(store.activeOrganisation.uuid).toBe(testOrg.uuid)
	})

	it('sets list correctly', () => {
		const store = useOrganisationStore()
		const mockData = mockOrganisation()

		store.setList(mockData)

		expect(store.list).toHaveLength(mockData.length)

		store.list.forEach((item, index) => {
			expect(item).toBeInstanceOf(Organisation)
			expect(item).toEqual(mockData[index])
			expect(item.validate().success).toBe(true)
		})
	})

	it('sets user stats correctly', () => {
		const store = useOrganisationStore()
		const mockData = mockOrganisation()
		const mockStats = {
			total: 3,
			active: mockData[0],
			results: mockData,
		}

		store.setUserStats(mockStats)

		expect(store.userStats.total).toBe(3)
		expect(store.userStats.active).toBeInstanceOf(Organisation)
		expect(store.userStats.active.name).toBe(mockData[0].name)
		expect(store.userStats.list).toHaveLength(mockData.length)

		store.userStats.list.forEach((item) => {
			expect(item).toBeInstanceOf(Organisation)
			expect(item.validate().success).toBe(true)
		})
	})

	it('cleanForSave strips configured fields', () => {
		const store = useOrganisationStore()
		const testOrg = {
			id: 1,
			uuid: 'test-uuid',
			name: 'Test Org',
			description: 'Test Description',
			users: ['user1', 'user2'],
			userCount: 2,
			created: '2023-01-01',
			updated: '2023-01-02',
		}

		const cleaned = store.cleanForSave(testOrg)

		expect(cleaned.name).toBe('Test Org')
		expect(cleaned.description).toBe('Test Description')

		// These fields should be removed
		expect(cleaned.id).toBeUndefined()
		expect(cleaned.uuid).toBeUndefined()
		expect(cleaned.users).toBeUndefined()
		expect(cleaned.userCount).toBeUndefined()
		expect(cleaned.created).toBeUndefined()
		expect(cleaned.updated).toBeUndefined()
	})

	it('cleanForSave handles boolean coercion for active field', () => {
		const store = useOrganisationStore()
		const testOrg = { name: 'Test', active: '' }

		const cleaned = store.cleanForSave(testOrg)

		expect(cleaned.active).toBe(true)
	})

	it('cleanForSave removes empty slug', () => {
		const store = useOrganisationStore()
		const testOrg = { name: 'Test', slug: '' }

		const cleaned = store.cleanForSave(testOrg)

		expect(cleaned.slug).toBeUndefined()
	})

	it('has correct initial state', () => {
		const store = useOrganisationStore()

		expect(store.item).toBeNull()
		expect(store.list).toEqual([])
		expect(store.activeOrganisation).toBeNull()
		expect(store.userStats).toEqual({ total: 0, active: null, list: [] })
	})

	it('has viewMode feature', () => {
		const store = useOrganisationStore()

		expect(store.viewMode).toBe('cards')
		expect(store.getViewMode).toBe('cards')

		store.setViewMode('table')
		expect(store.viewMode).toBe('table')
	})
})
