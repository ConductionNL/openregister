/* eslint-disable no-console */
import { setActivePinia, createPinia } from 'pinia'

import { useOrganisationStore } from './organisation.js'
import { Organisation, mockOrganisation } from '../../entities/index.js'

describe('Organisation Store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('sets organisation item correctly', () => {
		const store = useOrganisationStore()

		store.setOrganisationItem(mockOrganisation()[0])

		expect(store.organisationItem).toBeInstanceOf(Organisation)
		expect(store.organisationItem).toEqual(mockOrganisation()[0])

		expect(store.organisationItem.validate().success).toBe(true)
	})

	it('sets active organisation correctly', () => {
		const store = useOrganisationStore()
		const testOrg = mockOrganisation()[1]

		store.setActiveOrganisation(testOrg)

		expect(store.activeOrganisation).toBeInstanceOf(Organisation)
		expect(store.activeOrganisation.name).toBe(testOrg.name)
		expect(store.activeOrganisation.uuid).toBe(testOrg.uuid)
	})

	it('sets organisation list correctly', () => {
		const store = useOrganisationStore()
		const mockData = mockOrganisation()

		store.setOrganisationList(mockData)

		expect(store.organisationList).toHaveLength(mockData.length)

		store.organisationList.forEach((item, index) => {
			expect(item).toBeInstanceOf(Organisation)
			expect(item).toEqual(mockData[index])
			expect(item.validate().success).toBe(true)
		})
	})

	it('sets user stats correctly', () => {
		const store = useOrganisationStore()
		const mockStats = {
			total: 3,
			active: mockOrganisation()[0],
			list: mockOrganisation(),
		}

		store.setUserStats(mockStats)

		expect(store.userStats.total).toBe(3)
		expect(store.userStats.active).toBeInstanceOf(Organisation)
		expect(store.userStats.active.name).toBe(mockStats.active.name)
		expect(store.userStats.list).toHaveLength(3)

		store.userStats.list.forEach((item) => {
			expect(item).toBeInstanceOf(Organisation)
			expect(item.validate().success).toBe(true)
		})
	})

	it('cleans organisation data for save correctly', () => {
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

		const cleaned = store.cleanOrganisationForSave(testOrg)

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

	// ... other tests ...
})
