/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/sidebars-load-with-their-route/specs/frontend-app-bootstrap/spec.md
 */
import { flushPromises, mount } from '@vue/test-utils'
import SideBars from './SideBars.vue'

// Every sidebar is replaced by a marker carrying its name, so the spec sees
// which one the host shows for a route.
jest.mock('./dashboard/DashboardSideBar.vue', () => ({ __esModule: true, default: { name: 'DashboardSideBar', template: '<i class="DashboardSideBar" />' } }))
jest.mock('./deleted/DeletedSideBar.vue', () => ({ __esModule: true, default: { name: 'DeletedSideBar', template: '<i class="DeletedSideBar" />' } }))
jest.mock('./entities/EntitiesSideBar.vue', () => ({ __esModule: true, default: { name: 'EntitiesSideBar', template: '<i class="EntitiesSideBar" />' } }))
jest.mock('./logs/AuditTrailSideBar.vue', () => ({ __esModule: true, default: { name: 'AuditTrailSideBar', template: '<i class="AuditTrailSideBar" />' } }))
jest.mock('./logs/SearchTrailSideBar.vue', () => ({ __esModule: true, default: { name: 'SearchTrailSideBar', template: '<i class="SearchTrailSideBar" />' } }))
jest.mock('./register/RegisterSideBar.vue', () => ({ __esModule: true, default: { name: 'RegisterSideBar', template: '<i class="RegisterSideBar" />' } }))
jest.mock('./register/RegistersSideBar.vue', () => ({ __esModule: true, default: { name: 'RegistersSideBar', template: '<i class="RegistersSideBar" />' } }))
jest.mock('./search/SearchSideBar.vue', () => ({ __esModule: true, default: { name: 'SearchSideBar', template: '<i class="SearchSideBar" />' } }))

/**
 * The sidebars shown for a route path.
 *
 * @param {string} path The route path.
 * @return {Promise<Array<string>>} Their names.
 */
async function shownFor(path) {
	const wrapper = mount(SideBars, { global: { mocks: { $route: { path } } } })
	await flushPromises()
	return wrapper.findAll('i').map((el) => el.classes()[0])
}

describe('SideBars', () => {
	it.each([
		['/', 'DashboardSideBar'],
		['/tables', 'SearchSideBar'],
		['/registers/5', 'RegisterSideBar'],
		['/registers', 'RegistersSideBar'],
		['/deleted', 'DeletedSideBar'],
		['/entities', 'EntitiesSideBar'],
		['/audit-trails', 'AuditTrailSideBar'],
		['/search-trails', 'SearchTrailSideBar'],
	])('shows only its own sidebar on %s', async (path, name) => {
		expect(await shownFor(path)).toEqual([name])
	})

	it('shows no sidebar on a route without one', async () => {
		expect(await shownFor('/schemas')).toEqual([])
	})
})
