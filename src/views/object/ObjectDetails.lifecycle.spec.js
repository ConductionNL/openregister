/**
 * ObjectDetails — the tab-page watchers, the switch to another object on
 * update, teardown, the files fetch, and marking unread without an address,
 * exercised on the options object against a synthetic `this`.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import ObjectDetails from './ObjectDetails.vue'
import { objectStore } from '../../store/store.js'

jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => `/index.php${path}` }))
jest.mock('@nextcloud/vue', () => mockLibrary())
jest.mock('@conduction/nextcloud-vue', () =>
	mockLibrary({ useIntegrationRegistry: () => ({ integrations: { value: [] } }) }),
)
jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn() } }))
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
	objectStore: {},
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

const LOADERS = ['getFiles', 'getAuditTrails', 'getRelations', 'countOpenTasks', 'syncLiveSubscription', 'markReadAfterRender', 'releaseLiveSubscription']

/**
 * A view context whose loaders are spies.
 *
 * @param {object} state Extra state
 * @return {object} The context
 */
function view(state = {}) {
	const ctx = { currentActiveObject: null, fileLoading: false, pagination: { files: { limit: 20, currentPage: 3 } }, ...state }
	for (const name of LOADERS) ctx[name] = jest.fn()
	return ctx
}

beforeEach(() => {
	for (const key of Object.keys(objectStore)) delete objectStore[key]
	global.fetch = jest.fn()
})

describe('ObjectDetails — tab pages', () => {
	it('refetches only the tab whose page changed', () => {
		const ctx = view()

		ObjectDetails.watch['pagination.files.currentPage'].handler.call(ctx)
		ObjectDetails.watch['pagination.auditTrails.currentPage'].handler.call(ctx)
		ObjectDetails.watch['pagination.relations.currentPage'].handler.call(ctx)

		expect(ctx.getFiles).toHaveBeenCalledTimes(1)
		expect(ctx.getAuditTrails).toHaveBeenCalledTimes(1)
		expect(ctx.getRelations).toHaveBeenCalledTimes(1)
	})
})

describe('ObjectDetails — switching objects', () => {
	it('reloads every tab and re-subscribes when another object is selected', () => {
		objectStore.objectItem = { id: 'b' }
		const ctx = view({ currentActiveObject: 'a' })

		ObjectDetails.updated.call(ctx)

		expect(ctx.currentActiveObject).toBe('b')
		for (const name of ['getFiles', 'getAuditTrails', 'getRelations', 'countOpenTasks', 'syncLiveSubscription', 'markReadAfterRender']) {
			expect(ctx[name]).toHaveBeenCalledTimes(1)
		}
	})

	it('does nothing on an update that keeps the same object', () => {
		objectStore.objectItem = { id: 'a' }
		const ctx = view({ currentActiveObject: 'a' })

		ObjectDetails.updated.call(ctx)

		expect(ctx.getFiles).not.toHaveBeenCalled()
		expect(ctx.syncLiveSubscription).not.toHaveBeenCalled()
	})

	it('releases the live subscription on teardown', () => {
		const ctx = view()

		ObjectDetails.beforeUnmount.call(ctx)

		expect(ctx.releaseLiveSubscription).toHaveBeenCalledTimes(1)
	})
})

describe('ObjectDetails — files', () => {
	it('fetches the current files page of the selected object, with the loader on until it settles', async () => {
		let settle
		objectStore.objectItem = { id: 'o-1' }
		objectStore.getFiles = jest.fn(() => new Promise((resolve) => { settle = resolve }))
		const ctx = view()

		ObjectDetails.methods.getFiles.call(ctx)

		expect(objectStore.getFiles).toHaveBeenCalledWith('o-1', { limit: 20, page: 3 })
		expect(ctx.fileLoading).toBe(true)
		settle()
		await Promise.resolve()
		await Promise.resolve()
		expect(ctx.fileLoading).toBe(false)
	})

	it('fetches nothing without a selected object or a files action', () => {
		const ctx = view()
		objectStore.getFiles = jest.fn()

		ObjectDetails.methods.getFiles.call(ctx)
		objectStore.objectItem = { id: 'o-1' }
		delete objectStore.getFiles
		ObjectDetails.methods.getFiles.call(ctx)

		expect(ctx.fileLoading).toBe(false)
	})
})

describe('ObjectDetails — read state', () => {
	it('sends nothing when the record has no address', async () => {
		objectStore.objectItem = { id: 'o-1' }
		objectStore.setObjectItem = jest.fn()

		await ObjectDetails.methods.markUnread.call(view())

		expect(global.fetch).not.toHaveBeenCalled()
		expect(objectStore.setObjectItem).not.toHaveBeenCalled()
	})
})
