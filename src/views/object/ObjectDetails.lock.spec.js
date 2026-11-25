/**
 * ObjectDetails — the lock notice reads the lock where the object API answers
 * it (`@self.locked`), and Unlock releases it through the object's own route.
 *
 * @spec openspec/changes/capability-rows-closing-round/tasks.md
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import ObjectDetails from './ObjectDetails.vue'
import { objectStore } from '../../store/store.js'

jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => `/index.php${path}` }))
jest.mock('@nextcloud/vue', () => mockLibrary())
jest.mock('@conduction/nextcloud-vue', () =>
	mockLibrary({ useIntegrationRegistry: () => ({ integrations: { value: [] } }) }),
)
jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn(), post: jest.fn() } }))
jest.mock('@nextcloud/dialogs', () => ({ __esModule: true, showError: jest.fn() }))
jest.mock('../../components/tabs/AppTab.vue', () => mockModule('AppTab'))
jest.mock('../../components/tabs/AppTabs.vue', () => mockModule('AppTabs'))
jest.mock('../../components/access-links/ObjectAccessLinks.vue', () => mockModule('ObjectAccessLinks'))
jest.mock('../../components/object-relations/ContactsTab.vue', () => mockModule('ContactsTab'))
jest.mock('../../components/object-relations/DeckTab.vue', () => mockModule('DeckTab'))
jest.mock('../../components/object-relations/EmailsTab.vue', () => mockModule('EmailsTab'))
jest.mock('../../components/object-relations/EventsTab.vue', () => mockModule('EventsTab'))
jest.mock('../../components/object-relations/ReferencedByTab.vue', () => mockModule('ReferencedByTab'))
jest.mock('../../components/object-relations/RelationsTab.vue', () => mockModule('RelationsTab'))
jest.mock('../../store/store.js', () => ({
	navigationStore: {},
	objectStore: { setObjectItem: jest.fn() },
}))

/**
 * Library stand-ins: every named export is a component that draws nothing.
 *
 * @param {object} extra Named exports that are not components
 * @return {object} The module
 */
function mockLibrary(extra = {}) {
	return new Proxy(
		{ __esModule: true, ...extra },
		{
			get: (target, key) => (key in target || typeof key !== 'string' ? target[key] : { name: key, render: () => null }),
			has: () => true,
		},
	)
}

/**
 * A stand-in component module.
 *
 * @param {string} name The component
 * @return {object} The module
 */
function mockModule(name) {
	return { __esModule: true, default: { name, render: () => null } }
}

const lockOf = () => ObjectDetails.computed.lockInfo.call({})
const LOCK = { user: 'e2e-other', process: 'review', expiration: '2026-11-25T20:00:00+00:00' }

describe('ObjectDetails lock', () => {
	beforeEach(() => {
		jest.clearAllMocks()
	})

	it('reads the lock from the record\'s @self block, where the object API answers it', () => {
		objectStore.objectItem = { title: 'x', '@self': { id: 'u1', register: 4, schema: 7, locked: LOCK } }
		expect(lockOf()).toEqual(LOCK)
	})

	it('still reads a lock handed in on a flattened item', () => {
		objectStore.objectItem = { id: 'u1', locked: LOCK }
		expect(lockOf()).toEqual(LOCK)
	})

	it('is null for an unlocked record, and for no record', () => {
		objectStore.objectItem = { '@self': { id: 'u1', locked: null } }
		expect(lockOf()).toBeNull()
		objectStore.objectItem = { '@self': { id: 'u1', locked: [] } }
		expect(lockOf()).toBeNull()
		objectStore.objectItem = null
		expect(lockOf()).toBeNull()
	})

	it('unlocks through the record\'s own route and shows it released', async () => {
		objectStore.objectItem = { title: 'x', '@self': { id: 'u1', register: 4, schema: 7, locked: LOCK } }
		axios.post.mockResolvedValue({ data: {} })

		await ObjectDetails.methods.unlockRecord.call({ relationContext: { register: 4, schema: 7, id: 'u1' } })

		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/4/7/u1/unlock')
		expect(objectStore.setObjectItem).toHaveBeenCalledWith({ title: 'x', locked: null, '@self': { id: 'u1', register: 4, schema: 7, locked: null } })
	})

	it('does nothing without a record to unlock', async () => {
		await ObjectDetails.methods.unlockRecord.call({ relationContext: null })
		expect(axios.post).not.toHaveBeenCalled()
	})
})
