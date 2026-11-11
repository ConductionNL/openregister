import { flushPromises, mount } from '@vue/test-utils'
/**
 * The schema count modals say they are counting until the stats answer, and
 * only then what the count is.
 *
 * @spec exclude Test of client-side modal state around a stats request.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import DeleteSchemaObjects from './DeleteSchemaObjects.vue'
import ValidateSchema from './ValidateSchema.vue'

jest.mock('@nextcloud/l10n', () => ({
	t: (app, text) => text,
	translate: (app, text) => text,
	translatePlural: (app, one) => one,
}))
jest.mock('@nextcloud/vue', () => mockLibrary())
jest.mock('vue', () => ({ ...jest.requireActual('vue'), ...mockLibrary() }))
jest.mock('vue-material-design-icons/AlertCircle.vue', () => ({
	__esModule: true,
	default: {},
}))
jest.mock('vue-material-design-icons/Cancel.vue', () => ({
	__esModule: true,
	default: {},
}))
jest.mock('vue-material-design-icons/CheckCircle.vue', () => ({
	__esModule: true,
	default: {},
}))
jest.mock('vue-material-design-icons/DatabaseOutline.vue', () => ({
	__esModule: true,
	default: {},
}))
jest.mock('vue-material-design-icons/DeleteSweep.vue', () => ({
	__esModule: true,
	default: {},
}))
jest.mock('vue-material-design-icons/Eye.vue', () => ({
	__esModule: true,
	default: {},
}))
jest.mock('vue-material-design-icons/TrashCanOutline.vue', () => ({
	__esModule: true,
	default: {},
}))
jest.mock('../../store/store.js', () => ({
	navigationStore: { dialog: null, setDialog: () => {} },
	registerStore: {},
	schemaStore: { schemaItem: { id: 147, title: 'Project' }, getSchemaStats: null },
}))

const { navigationStore, schemaStore } = jest.requireMock('../../store/store.js')

/**
 * The library components these modals use, as stubs rendering their slots.
 *
 * @return {object} The module
 */
function mockLibrary() {
	const { h: render } = jest.requireActual('vue')
	const stub = (name) => ({
		name,
		render() {
			return render(
				'div',
				Object.values(this.$slots).map((slot) => slot({})),
			)
		},
	})
	return {
		__esModule: true,
		NcButton: stub('NcButton'),
		NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch'),
		NcDialog: stub('NcDialog'),
		NcEmptyContent: stub('NcEmptyContent'),
		NcLoadingIcon: stub('NcLoadingIcon'),
		NcNoteCard: stub('NcNoteCard'),
	}
}

const MODALS = [
	['deleteSchemaObjects', DeleteSchemaObjects],
	['validateSchema', ValidateSchema],
]

/**
 * Mounts a modal against a stats request that answers when told to.
 *
 * @param {Array} modal The dialog name and the component
 * @return {object} The wrapper and the answer callbacks
 */
function open([dialog, component]) {
	navigationStore.dialog = dialog
	const answer = {}
	schemaStore.getSchemaStats = () =>
		new Promise((resolve, reject) => Object.assign(answer, { resolve, reject }))
	const wrapper = mount(component, {
		global: { mocks: { t: (app, text) => text } },
	})
	return { wrapper, answer }
}

describe.each(MODALS)('%s count', (name, component) => {
	const modal = [name, component]

	beforeEach(() => jest.spyOn(console, 'error').mockImplementation(() => {}))
	afterEach(() => jest.restoreAllMocks())

	it('says it is counting while the stats are on their way', async () => {
		const { wrapper } = open(modal)
		await flushPromises()
		expect(wrapper.text()).toContain('Counting objects...')
		expect(wrapper.text()).not.toContain('No objects found')
	})

	it('shows the count once the stats answer', async () => {
		const { wrapper, answer } = open(modal)
		await flushPromises()
		answer.resolve({ objects: { total: 2, invalid: 0, deleted: 0 } })
		await flushPromises()
		expect(wrapper.text()).not.toContain('Counting objects...')
		expect(wrapper.find('.count-value').text()).toBe('2')
	})

	it('says there are no objects when the count is zero', async () => {
		const { wrapper, answer } = open(modal)
		await flushPromises()
		answer.resolve({ objects: { total: 0 } })
		await flushPromises()
		expect(wrapper.text()).toContain('No objects found')
		expect(wrapper.text()).not.toContain('Counting objects...')
	})

	it('stops counting when the stats fail', async () => {
		const { wrapper, answer } = open(modal)
		await flushPromises()
		answer.reject(new Error('down'))
		await flushPromises()
		expect(wrapper.text()).not.toContain('Counting objects...')
		expect(wrapper.text()).not.toContain('No objects found')
		expect(wrapper.text()).toContain('We could not count the objects.')
	})

	it('drops the failure when a new count starts', async () => {
		const { wrapper, answer } = open(modal)
		await flushPromises()
		answer.reject(new Error('down'))
		await flushPromises()
		wrapper.vm.$.proxy.loadObjectCount()
		answer.resolve({ objects: { total: 0 } })
		await flushPromises()
		expect(wrapper.text()).not.toContain('We could not count the objects.')
		expect(wrapper.text()).toContain('No objects found')
	})
})
