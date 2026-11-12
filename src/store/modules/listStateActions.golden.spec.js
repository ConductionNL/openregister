/**
 * Golden record of setPagination and setFilters on the admin list stores:
 * the pagination and filters each call leaves, with and without a page size.
 *
 * @spec exclude Golden record of client UI-state setters; no backend contract.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { createPinia, setActivePinia } from 'pinia'
import { useApplicationStore } from './application.js'
import { useConfigurationStore } from './configuration.js'
import { useOrganisationStore } from './organisation.js'
import { useRegisterStore } from './register.js'
import { useSchemaStore } from './schema.js'

// The schema store imports @nextcloud/axios, which jest cannot parse
// unmocked (ESM); these tests make no requests.
jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), put: jest.fn(), post: jest.fn(), delete: jest.fn() },
}))
jest.mock('@nextcloud/router', () => ({
	__esModule: true,
	generateUrl: jest.fn((path) => `/index.php${path}`),
}))

const STORES = [
	['application', useApplicationStore],
	['configuration', useConfigurationStore],
	['organisation', useOrganisationStore],
	['register', useRegisterStore],
	['schema', useSchemaStore],
]

describe('list store state setters golden', () => {
	for (const [name, useStore] of STORES) {
		it(`${name} sets pagination and filters`, () => {
			setActivePinia(createPinia())
			const store = useStore()
			const steps = [['initial', store.pagination, store.filters]]
			store.setPagination(3)
			steps.push(['page only', store.pagination, store.filters])
			store.setPagination(2, 50)
			steps.push(['page and size', store.pagination, store.filters])
			store.setPagination(undefined, undefined)
			steps.push(['nothing', store.pagination, store.filters])
			store.setPagination(1, null)
			steps.push(['null size', store.pagination, store.filters])
			store.setFilters({ a: 1, b: 'x' })
			steps.push(['filters', store.pagination, store.filters])
			const before = store.filters
			store.setFilters({ b: undefined, c: [1] })
			steps.push(['merged filters', store.pagination, store.filters])
			steps.push(['new object', store.filters !== before])
			store.setFilters({})
			steps.push(['empty filters', store.pagination, store.filters])
			expect(
				JSON.parse(
					JSON.stringify(steps, (key, value) =>
						value === undefined ? '(undefined)' : value,
					),
				),
			).toMatchSnapshot()
		})
	}
})
