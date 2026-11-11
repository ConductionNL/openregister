/**
 * The app-wide store registry: every exported store is the one its module
 * defines, bound to the app's single pinia instance.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import pinia from '../pinia.js'
import { useApplicationStore } from './modules/application.js'
import { useNavigationStore } from './modules/navigation.js'
import { useObjectStore } from './modules/object.js'
import { useRegisterStore } from './modules/register.js'
import { useSchemaStore } from './modules/schema.js'
import { useWebhookStore } from './modules/webhook.js'
import * as stores from './store.js'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), post: jest.fn(), put: jest.fn(), patch: jest.fn(), delete: jest.fn() },
}))
jest.mock('@nextcloud/router', () => ({
	__esModule: true,
	generateUrl: jest.fn((path) => `/index.php${path}`),
	generateOcsUrl: jest.fn((path) => `/ocs${path}`),
}))

describe('store registry', () => {
	it('exports the nineteen app stores', () => {
		expect(Object.keys(stores).sort()).toEqual([
			'applicationStore', 'auditTrailStore', 'avgStore', 'configurationStore',
			'dashboardStore', 'deletedStore', 'endpointStore', 'navigationStore',
			'objectStore', 'organisationStore', 'qualityStore', 'registerStore',
			'reportsStore', 'schemaStore', 'searchStore', 'searchTrailStore',
			'sourceStore', 'viewsStore', 'webhookStore',
		])
	})

	it('binds each store to the app pinia, so the same instance is shared', () => {
		expect(stores.navigationStore).toBe(useNavigationStore(pinia))
		expect(stores.registerStore).toBe(useRegisterStore(pinia))
		expect(stores.schemaStore).toBe(useSchemaStore(pinia))
		expect(stores.objectStore).toBe(useObjectStore(pinia))
		expect(stores.applicationStore).toBe(useApplicationStore(pinia))
		expect(stores.webhookStore).toBe(useWebhookStore(pinia))
	})

	it('a write through the registry is visible to every user of the store', () => {
		stores.registerStore.setViewMode('table')
		expect(useRegisterStore(pinia).viewMode).toBe('table')
		expect(stores.navigationStore.$id).toBe('ui')
		expect(stores.organisationStore.$id).toBe('organisation')
		expect(stores.searchStore.$id).toBe('search')
	})
})
