/* eslint-disable no-console */
import { setActivePinia, createPinia } from 'pinia'

import { useOrganisationStore } from './organisation.js'
import { Organisation, mockOrganisation } from '../../entities/index.js'

describe('Organisation Store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('wraps the active organisation in an Organisation entity', () => {
		const store = useOrganisationStore()
		const item = mockOrganisation()[0]

		store.setOrganisationItem(item)

		expect(store.organisationItem).toBeInstanceOf(Organisation)
		expect(store.organisationItem.uuid).toBe(item.uuid)
		expect(store.organisationItem.validate().success).toBe(true)
	})

	it('wraps active organisation', () => {
		const store = useOrganisationStore()
		const testOrg = mockOrganisation()[1]

		store.setActiveOrganisation(testOrg)

		expect(store.activeOrganisation).toBeInstanceOf(Organisation)
		expect(store.activeOrganisation.name).toBe(testOrg.name)
		expect(store.activeOrganisation.uuid).toBe(testOrg.uuid)
	})

	it('wraps every entry of the list in an Organisation', () => {
		const store = useOrganisationStore()
		const items = mockOrganisation()

		store.setOrganisationList(items)

		expect(store.organisationList).toHaveLength(items.length)
		store.organisationList.forEach((item, index) => {
			expect(item).toBeInstanceOf(Organisation)
			expect(item.uuid).toBe(items[index].uuid)
			expect(item.validate().success).toBe(true)
		})
	})

	it('builds userStats from a stats payload', () => {
		const store = useOrganisationStore()
		const stats = {
			total: 3,
			active: mockOrganisation()[0],
			results: mockOrganisation(),
		}

		store.setUserStats(stats)

		expect(store.userStats.total).toBe(3)
		expect(store.userStats.active).toBeInstanceOf(Organisation)
		expect(store.userStats.active.name).toBe(stats.active.name)
		expect(store.userStats.list).toHaveLength(stats.results.length)
		store.userStats.list.forEach((item) => {
			expect(item).toBeInstanceOf(Organisation)
			expect(item.validate().success).toBe(true)
		})
	})

	it('strips server-managed fields when cleaning for save', () => {
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
		expect(cleaned.id).toBeUndefined()
		expect(cleaned.uuid).toBeUndefined()
		expect(cleaned.users).toBeUndefined()
		expect(cleaned.userCount).toBeUndefined()
		expect(cleaned.created).toBeUndefined()
		expect(cleaned.updated).toBeUndefined()
	})
})
