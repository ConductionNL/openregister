import { flushPromises, mount } from '@vue/test-utils'
/**
 * Golden record of the source edit modal: its rendered DOM in each state,
 * what opening and closing put in the form, what saving sends and shows,
 * and what the database actions show.
 *
 * @spec exclude Golden record of client-side dialog markup and actions.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import BusyButton from '../../components/shared/BusyButton.vue'
import CloseCancelButton from '../../components/shared/CloseCancelButton.vue'
import EditSource from './EditSource.vue'

jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) =>
		vars ? `${text} ${JSON.stringify(vars)}` : text,
}))
jest.mock('@nextcloud/vue', () => ({
	__esModule: true,
	NcButton: mockStub('NcButton'),
	NcCheckboxRadioSwitch: mockStub('NcCheckboxRadioSwitch'),
	NcDialog: mockStub('NcDialog'),
	NcLoadingIcon: mockStub('NcLoadingIcon'),
	NcNoteCard: mockStub('NcNoteCard'),
	NcSelect: mockStub('NcSelect'),
	NcTextArea: mockStub('NcTextArea'),
	NcTextField: mockStub('NcTextField'),
}))
jest.mock('vue-material-design-icons/Cancel.vue', () => mockModule('Cancel'))
jest.mock('vue-material-design-icons/ContentSaveOutline.vue', () =>
	mockModule('ContentSaveOutline'),
)
jest.mock('vue-material-design-icons/DatabaseCheckOutline.vue', () =>
	mockModule('DatabaseCheckOutline'),
)
jest.mock('vue-material-design-icons/DatabaseImportOutline.vue', () =>
	mockModule('DatabaseImportOutline'),
)
jest.mock('vue-material-design-icons/Plus.vue', () => mockModule('Plus'))
jest.mock('../../store/store.js', () => ({
	navigationStore: { modal: 'editSource', setModal: jest.fn() },
	sourceStore: { sourceItem: null, saveSource: jest.fn() },
}))

const { navigationStore, sourceStore } = jest.requireMock('../../store/store.js')

/**
 * A stand-in component module.
 *
 * @param {string} name The component name
 * @return {object} The module
 */
function mockModule(name) {
	return { __esModule: true, default: mockStub(name) }
}

/**
 * A stand-in for a component: its tag, attributes and slots.
 *
 * @param {string} name The component name
 * @return {object} The stub
 */
function mockStub(name) {
	const { h: render } = require('vue')
	return {
		name,
		inheritAttrs: false,
		setup(props, { attrs, slots }) {
			return () => {
				const listeners = Object.keys(attrs).filter((key) =>
					/^on[A-Z]/.test(key),
				)
				const plain = Object.fromEntries(
					Object.entries(attrs).filter(
						([key]) => !listeners.includes(key),
					),
				)
				if (listeners.length) plain['data-on'] = listeners.join(' ')
				return render(
					`x-${name}`,
					plain,
					Object.entries(slots).map(([slot, fill]) =>
						render(`slot-${slot}`, fill({})),
					),
				)
			}
		},
	}
}

/**
 * The markup without scoped-style ids and comment nodes.
 *
 * @param {string} html The markup
 * @return {string} The markup
 */
function normalised(html) {
	return html
		.replace(/ data-v-[a-f0-9]+(="")?/g, '')
		.replace(/<!--[\s\S]*?-->/g, '')
		.split('\n')
		.filter((line) => line.trim() !== '')
		.join('\n')
}

const SOURCES = {
	none: null,
	internal: { id: 7, title: 'Main', description: 'd', type: 'internal' },
	unknownType: { id: 8, title: 'Odd', type: 'ftp' },
	mysql: {
		id: 9,
		title: 'Shop',
		type: 'database',
		databaseUrl: 'ignored',
		authConfig: {
			driver: 'pdo_pgsql',
			host: 'db',
			port: 5432,
			dbname: 'shop',
			user: 'u',
			credential: 'cred',
			writable: true,
		},
	},
	sqlite: {
		id: 10,
		title: 'Local',
		type: 'database',
		authConfig: { driver: 'pdo_sqlite', path: '/x.db', writable: 'yes' },
	},
	noDriver: {
		id: 11,
		title: 'Bare',
		type: 'database',
		authConfig: { driver: 'oracle', host: 'h' },
	},
}

/**
 * The modal with its setup bindings supplied. Under jest the compiled bindings
 * of an SFC with both a setup and a plain script resolve the library components
 * to undefined, so the modal would render nothing.
 *
 * @return {object} The modal with stand-in bindings
 */
function editSourceModal() {
	const library = jest.requireMock('@nextcloud/vue')
	const icons = [
		'Cancel',
		'ContentSaveOutline',
		'DatabaseCheckOutline',
		'DatabaseImportOutline',
		'Plus',
	]
	const bindings = {
		...library,
		...Object.fromEntries(icons.map((name) => [name, mockStub(name)])),
		BusyButton,
		CloseCancelButton,
		navigationStore,
		sourceStore,
		t: (app, text) => text,
	}
	return { ...EditSource, setup: () => bindings }
}

/**
 * The rendered markup of a modal that must be open.
 *
 * @param {object} wrapper The wrapper
 * @return {string} The normalised markup
 */
function domOf(wrapper) {
	const html = normalised(wrapper.html())
	expect(html).toContain('x-ncdialog')
	return html
}

/**
 * Mount the modal for a source in the store.
 *
 * @param {object|null} source The store's source item
 * @return {Promise<object>} The wrapper
 */
async function mountFor(source) {
	sourceStore.sourceItem = source
	const wrapper = mount(editSourceModal())
	await flushPromises()
	return wrapper
}

/**
 * The form state of the modal.
 *
 * @param {object} vm The component
 * @return {object} The state
 */
function formOf(vm) {
	return {
		sourceItem: vm.sourceItem,
		connection: vm.connection,
		type: vm.typeOptions.value,
		driver: vm.driverOptions.value,
		success: vm.success,
		loading: vm.loading,
		actionLoading: vm.actionLoading,
		actionMessage: vm.actionMessage,
		actionMessageType: vm.actionMessageType,
		error: vm.error,
		hasUpdated: vm.hasUpdated,
		timer: vm.closeModalTimeout === null ? null : typeof vm.closeModalTimeout,
	}
}

beforeEach(() => {
	jest.clearAllMocks()
	jest.spyOn(console, 'error').mockImplementation(() => {})
	jest.spyOn(console, 'warn').mockImplementation(() => {})
	global.fetch = jest.fn()
})

describe('EditSource golden', () => {
	for (const [name, source] of Object.entries(SOURCES)) {
		it(`opens on a ${name} source`, async () => {
			const wrapper = await mountFor(source)
			expect(formOf(wrapper.vm)).toMatchSnapshot('form')
			expect(domOf(wrapper)).toMatchSnapshot('dom')
			wrapper.unmount()
		})
	}

	it('renders the result notes', async () => {
		const wrapper = await mountFor(SOURCES.mysql)
		for (const data of [
			{ error: 'Broken' },
			{ actionMessage: 'Fine', actionMessageType: 'success' },
			{ actionLoading: true, loading: true },
			{ success: true, error: false },
		]) {
			await wrapper.setData(data)
			expect(domOf(wrapper)).toMatchSnapshot()
		}
		wrapper.unmount()
	})

	it('closes and resets the form', async () => {
		const wrapper = await mountFor(SOURCES.mysql)
		Object.assign(wrapper.vm.$.proxy, {
			success: true,
			actionMessage: 'x',
			error: 'e',
			loading: true,
			actionLoading: true,
		})
		wrapper.vm.closeModal()
		expect(navigationStore.setModal.mock.calls).toEqual([[false]])
		expect(formOf(wrapper.vm)).toMatchSnapshot()
		wrapper.unmount()
	})

	for (const [name, outcome] of Object.entries({
		saved: () => Promise.resolve({ response: { ok: true } }),
		refused: () => Promise.resolve({ response: { ok: false } }),
		thrown: () => Promise.reject(new Error('Network')),
		thrownBare: () => Promise.reject(new Error('')),
	})) {
		for (const source of ['internal', 'mysql', 'sqlite']) {
			it(`saves a ${source} source: ${name}`, async () => {
				sourceStore.saveSource.mockImplementation(outcome)
				const wrapper = await mountFor(SOURCES[source])
				await wrapper.vm.editSource()
				const during = wrapper.vm.loading
				await flushPromises()
				clearTimeout(wrapper.vm.closeModalTimeout)
				expect({
					sent: sourceStore.saveSource.mock.calls,
					during,
					form: formOf(wrapper.vm),
				}).toMatchSnapshot()
				wrapper.unmount()
			})
		}
	}

	it('closes from the cancel button', async () => {
		const wrapper = await mountFor(SOURCES.internal)
		const [cancel] = wrapper.findAllComponents({ name: 'NcButton' })
		cancel.vm.$attrs.onClick()
		expect(navigationStore.setModal.mock.calls).toEqual([[false]])
		wrapper.unmount()
	})

	it('saves only a true writable flag as writable', async () => {
		sourceStore.saveSource.mockResolvedValue({ response: { ok: false } })
		const wrapper = await mountFor(SOURCES.mysql)
		wrapper.vm.$.proxy.connection.writable = 'yes'
		await wrapper.vm.editSource()
		await flushPromises()
		expect(sourceStore.saveSource.mock.calls[0][0].authConfig.writable).toBe(
			false,
		)
		wrapper.unmount()
	})

	for (const [name, answer] of Object.entries({
		ok: () => Promise.resolve({ ok: true, json: () => ({}) }),
		okCounts: () =>
			Promise.resolve({
				ok: true,
				json: () => ({ created: [1, 2], updated: [3] }),
			}),
		failed: () =>
			Promise.resolve({ ok: false, json: () => ({ error: 'Denied' }) }),
		failedBare: () => Promise.resolve({ ok: false, json: () => ({}) }),
		thrown: () => Promise.reject(new Error('Offline')),
		thrownBare: () => Promise.reject(new Error('')),
		badJson: () =>
			Promise.resolve({
				ok: true,
				json: () => {
					throw new Error('Not JSON')
				},
			}),
	})) {
		for (const action of ['testConnection', 'introspect']) {
			it(`${action}: ${name}`, async () => {
				global.fetch.mockImplementation(answer)
				window.OC = name === 'ok' ? undefined : { requestToken: 'tok' }
				const wrapper = await mountFor(SOURCES.mysql)
				const running = wrapper.vm[action]()
				const during = {
					actionLoading: wrapper.vm.actionLoading,
					actionMessage: wrapper.vm.actionMessage,
				}
				await running
				expect({
					fetched: global.fetch.mock.calls,
					during,
					form: formOf(wrapper.vm),
				}).toMatchSnapshot()
				wrapper.unmount()
			})
		}
	}
})
