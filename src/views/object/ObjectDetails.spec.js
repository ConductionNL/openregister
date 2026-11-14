/**
 * The record page's Translate action (ai-translation-with-a-glossary task
 * 3.2): shown to administrators on a schema with a translatable property in a
 * register with more than one language; it opens the Translate dialog through
 * the dialog host and saves the answer on the record.
 *
 * @spec openspec/changes/ai-translation-with-a-glossary/tasks.md
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import ObjectDetails from './ObjectDetails.vue'
import { navigationStore, objectStore } from '../../store/store.js'

jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => `/index.php${path}` }))
jest.mock('@nextcloud/vue', () => mockLibrary())
jest.mock('@conduction/nextcloud-vue', () =>
	mockLibrary({ useIntegrationRegistry: () => ({ integrations: { value: [] } }) }),
)
jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn() } }))
jest.mock('@nextcloud/auth', () => ({ getCurrentUser: jest.fn() }))
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
	navigationStore: { setTransferData: jest.fn(), setDialog: jest.fn() },
	objectStore: {},
}))

/**
 * Library stand-ins: every named export is a stub of that name.
 *
 * @param {object} extra Named exports that are not components
 * @return {object} The module
 */
function mockLibrary(extra = {}) {
	const made = {}
	return new Proxy(
		{ __esModule: true, ...extra },
		{
			get(target, key) {
				if (key in target || typeof key !== 'string') return target[key]
				made[key] = made[key] || mockStub(key)
				return made[key]
			},
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
	return { __esModule: true, default: mockStub(name) }
}

/**
 * A stand-in for a component: its tag, plain attributes and slots.
 *
 * @param {string} name The component name
 * @return {object} The stub
 */
function mockStub(name) {
	const { h: render } = require('vue')
	return {
		name,
		inheritAttrs: false,
		render() {
			const attrs = Object.fromEntries(
				Object.entries(this.$attrs).filter(([key]) => !/^on[A-Z]/.test(key)),
			)
			return render(
				`x-${name}`,
				attrs,
				Object.keys(this.$slots)
					.sort()
					.map((slot) => render(`slot-${slot}`, this.$slots[slot]({}))),
			)
		},
	}
}


const { computed, methods } = ObjectDetails

/**
 * The page's `this` for one record of register 3, schema 7.
 *
 * @param {object} overrides Fields to set
 * @return {object} The context
 */
function page(overrides = {}) {
	const ctx = {
		relationContext: { register: 3, schema: 7, id: 'obj-1' },
		translationKey: '3-7',
		translation: { key: '', languages: [], translatable: [] },
		liveTarget: () => ({ ctx: { register: 3, schema: 7 }, uuid: 'uuid-1', type: '3-7', key: '3-7::uuid-1' }),
		...overrides,
	}
	ctx.saveTranslations = (result) => methods.saveTranslations.call(ctx, result)
	return ctx
}

/**
 * Answer the register and schema reads.
 *
 * @param {string[]} languages The register's languages
 * @param {object} properties The schema's properties
 * @return {void}
 */
function answer(languages, properties) {
	axios.get.mockImplementation(async (url) => (url.includes('/registers/') ? { data: { id: 3, languages } } : { data: { id: 7, properties } }))
}

const translatableSchema = { title: { type: 'string', translatable: true }, code: { type: 'string' } }

describe('the Translate action', () => {
	beforeEach(() => {
		jest.clearAllMocks()
		getCurrentUser.mockReturnValue({ isAdmin: true })
	})

	it('is shown to an administrator for a multi-language register and a translatable property', async () => {
		answer(['nl', 'en'], translatableSchema)
		const ctx = page()
		await methods.loadTranslationFacts.call(ctx)

		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/openregister/api/registers/3')
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/openregister/api/schemas/7')
		expect(ctx.translation).toEqual({ key: '3-7', languages: ['nl', 'en'], translatable: ['title'] })
		expect(computed.canTranslateObject.call(ctx)).toBe(true)
	})

	it('is hidden for a single-language register', async () => {
		answer(['nl'], translatableSchema)
		const ctx = page()
		await methods.loadTranslationFacts.call(ctx)

		expect(computed.canTranslateObject.call(ctx)).toBe(false)
	})

	it('is hidden for a schema without a translatable property', async () => {
		answer(['nl', 'en'], { code: { type: 'string' } })
		const ctx = page()
		await methods.loadTranslationFacts.call(ctx)

		expect(computed.canTranslateObject.call(ctx)).toBe(false)
	})

	it('is hidden, and reads nothing, for a user who is not an administrator', async () => {
		getCurrentUser.mockReturnValue({ isAdmin: false })
		answer(['nl', 'en'], translatableSchema)
		const ctx = page()
		await methods.loadTranslationFacts.call(ctx)

		expect(axios.get).not.toHaveBeenCalled()
		expect(computed.canTranslateObject.call(ctx)).toBe(false)
	})

	it('is hidden when the reads fail', async () => {
		axios.get.mockRejectedValue(new Error('403'))
		const ctx = page()
		await methods.loadTranslationFacts.call(ctx)

		expect(computed.canTranslateObject.call(ctx)).toBe(false)
	})

	it('is hidden once the page moved to a record of another schema', async () => {
		answer(['nl', 'en'], translatableSchema)
		const ctx = page()
		await methods.loadTranslationFacts.call(ctx)
		ctx.translationKey = '3-8'

		expect(computed.canTranslateObject.call(ctx)).toBe(false)
	})

	it('opens the Translate dialog through the dialog host with the record and the languages', () => {
		const ctx = page({ translation: { key: '3-7', languages: ['nl', 'en'], translatable: ['title'] } })
		methods.openTranslate.call(ctx)

		const handed = navigationStore.setTransferData.mock.calls[0][0]
		expect(handed.uuid).toBe('uuid-1')
		expect(handed.languages).toEqual(['nl', 'en'])
		expect(typeof handed.onTranslated).toBe('function')
		expect(navigationStore.setDialog).toHaveBeenCalledWith('bulkTranslate')
	})

	it('saves the answer on the record and shows the saved record', async () => {
		const saved = { id: 'uuid-1', title: { nl: 'Vergunning', en: 'Permit' } }
		Object.assign(objectStore, {
			objectItem: { id: 'uuid-1', title: 'Vergunning', code: 'A1' },
			objectTypes: ['3-7'],
			registerObjectType: jest.fn(),
			saveObject: jest.fn().mockResolvedValue(saved),
			setObjectItem: jest.fn(),
		})
		const ctx = page({ translation: { key: '3-7', languages: ['nl', 'en'], translatable: ['title'] } })
		methods.openTranslate.call(ctx)
		await navigationStore.setTransferData.mock.calls[0][0].onTranslated({ from: 'nl', to: 'en', translated: { title: 'Permit' }, skipped: {} })

		expect(objectStore.saveObject).toHaveBeenCalledWith('3-7', { id: 'uuid-1', title: { nl: 'Vergunning', en: 'Permit' }, code: 'A1' })
		expect(objectStore.setObjectItem).toHaveBeenCalledWith(saved)
		expect(showError).not.toHaveBeenCalled()
	})

	it('saves nothing when nothing was translated, and says so when the save fails', async () => {
		Object.assign(objectStore, {
			objectItem: { id: 'uuid-1', title: 'Vergunning' },
			objectTypes: ['3-7'],
			registerObjectType: jest.fn(),
			saveObject: jest.fn().mockResolvedValue(null),
			setObjectItem: jest.fn(),
		})
		const ctx = page()
		await methods.saveTranslations.call(ctx, { to: 'en', translated: {}, skipped: { title: 'no-source-value' } })
		expect(objectStore.saveObject).not.toHaveBeenCalled()

		await methods.saveTranslations.call(ctx, { to: 'en', translated: { title: 'Permit' } })
		expect(objectStore.saveObject).toHaveBeenCalledTimes(1)
		expect(objectStore.setObjectItem).not.toHaveBeenCalled()
		expect(showError).toHaveBeenCalledWith('The translations could not be saved on the object.')
	})
})
