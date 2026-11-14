import { showError } from '@nextcloud/dialogs'
/**
 * The record page's Follow toggle, read state and "Mark as unread"
 * (record-star-follow-and-unread-on-screen, tasks 2.1 and 2.2).
 *
 * @spec openspec/changes/record-star-follow-and-unread-on-screen/tasks.md
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { mount } from '@vue/test-utils'
import ObjectDetails from './ObjectDetails.vue'
import { objectStore } from '../../store/store.js'
import { sendReadState } from './recordFollow.js'

jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => `t:${text}` }))
jest.mock('@nextcloud/vue', () => mockLibrary())
jest.mock('@conduction/nextcloud-vue', () =>
	mockLibrary({ useIntegrationRegistry: () => ({ integrations: { value: [] } }) }),
)
jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: {} }))
jest.mock('@nextcloud/dialogs', () => ({ __esModule: true, showError: jest.fn() }))
jest.mock('../../components/tabs/AppTab.vue', () => mockModule('AppTab'))
jest.mock('../../components/tabs/AppTabs.vue', () => mockModule('AppTabs'))
jest.mock('../../components/access-links/ObjectAccessLinks.vue', () =>
	mockModule('ObjectAccessLinks'),
)
jest.mock('../../components/object-relations/ContactsTab.vue', () =>
	mockModule('ContactsTab'),
)
jest.mock('../../components/object-relations/DeckTab.vue', () => mockModule('DeckTab'))
jest.mock('../../components/object-relations/EmailsTab.vue', () =>
	mockModule('EmailsTab'),
)
jest.mock('../../components/object-relations/EventsTab.vue', () =>
	mockModule('EventsTab'),
)
jest.mock('../../components/object-relations/ReferencedByTab.vue', () =>
	mockModule('ReferencedByTab'),
)
jest.mock('../../components/object-relations/RelationsTab.vue', () =>
	mockModule('RelationsTab'),
)
jest.mock('vue-material-design-icons/CubeOutline.vue', () =>
	mockModule('CubeOutline'),
)
jest.mock('vue-material-design-icons/EmailMarkAsUnread.vue', () =>
	mockModule('EmailMarkAsUnread'),
)
jest.mock('vue-material-design-icons/DotsHorizontal.vue', () =>
	mockModule('DotsHorizontal'),
)
jest.mock('vue-material-design-icons/ExclamationThick.vue', () =>
	mockModule('ExclamationThick'),
)
jest.mock('vue-material-design-icons/Eye.vue', () =>
	mockModule('Eye'),
)
jest.mock('vue-material-design-icons/FileOutline.vue', () =>
	mockModule('FileOutline'),
)
jest.mock('vue-material-design-icons/FolderOutline.vue', () =>
	mockModule('FolderOutline'),
)
jest.mock('vue-material-design-icons/LockOpenOutline.vue', () =>
	mockModule('LockOpenOutline'),
)
jest.mock('vue-material-design-icons/LockOutline.vue', () =>
	mockModule('LockOutline'),
)
jest.mock('vue-material-design-icons/OpenInNew.vue', () =>
	mockModule('OpenInNew'),
)
jest.mock('vue-material-design-icons/Pencil.vue', () =>
	mockModule('Pencil'),
)
jest.mock('vue-material-design-icons/TimelineQuestionOutline.vue', () =>
	mockModule('TimelineQuestionOutline'),
)
jest.mock('vue-material-design-icons/TrashCanOutline.vue', () =>
	mockModule('TrashCanOutline'),
)
jest.mock('../../store/store.js', () => ({
	navigationStore: {},
	objectStore: {},
}))
jest.mock('./recordFollow.js', () => ({
	...jest.requireActual('./recordFollow.js'),
	sendReadState: jest.fn(),
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

const RECORD = {
	id: 'o1',
	'@self': { id: 'o1', register: 'r', schema: 's', watching: true, watchNotify: false, watcherCount: 2, can: { update: true, manage: false } },
}

/**
 * Mount the view on a record, with its sub-resource loads stubbed.
 *
 * @param {object|null} item The record in the store
 * @return {object} The wrapper
 */
function view(item) {
	for (const key of Object.keys(objectStore)) delete objectStore[key]
	Object.assign(objectStore, {
		objectItem: item,
		files: { results: [] },
		auditTrails: { results: [] },
		used: { results: [], total: 0 },
		uses: { results: [] },
		setObjectItem: jest.fn(),
	})
	const View = {
		...ObjectDetails,
		beforeUnmount() {},
		watch: {},
		methods: {
			...ObjectDetails.methods,
			getFiles() {},
			getAuditTrails() {},
			getRelations() {},
			syncLiveSubscription() {},
		},
	}
	return mount(View)
}

/**
 * Let the pending promises settle.
 *
 * @return {Promise<void>}
 */
function settle() {
	return new Promise((resolve) => setTimeout(resolve, 0))
}

describe('ObjectDetails follow and read state', () => {
	beforeEach(() => {
		sendReadState.mockReset()
		sendReadState.mockResolvedValue(true)
		showError.mockReset()
	})

	it('places the Follow toggle with the record\'s follow state', () => {
		const toggle = view(RECORD).find('x-CnFollowToggle')

		expect(toggle.exists()).toBe(true)
		expect(toggle.attributes()).toMatchObject({ register: 'r', schema: 's', objectid: 'o1', watching: 'true', notify: 'false', watchercount: '2', canmanage: 'false' })
	})

	it('marks the record read once after it renders', async () => {
		const wrapper = view(RECORD)
		await settle()
		await wrapper.vm.markReadAfterRender()

		expect(sendReadState).toHaveBeenCalledTimes(1)
		expect(sendReadState).toHaveBeenCalledWith({ register: 'r', schema: 's', id: 'o1' }, 'PUT')
	})

	it('sends nothing and shows no toggle without a loaded record', async () => {
		const wrapper = view({ id: 'o1', '@self': { id: 'o1' } })
		await settle()

		expect(sendReadState).not.toHaveBeenCalled()
		expect(wrapper.find('x-CnFollowToggle').exists()).toBe(false)
	})

	it('marks the record unread and leaves it', async () => {
		const wrapper = view(RECORD)
		await settle()
		sendReadState.mockClear()

		await wrapper.vm.markUnread()

		expect(sendReadState).toHaveBeenCalledWith({ register: 'r', schema: 's', id: 'o1' }, 'DELETE')
		expect(objectStore.setObjectItem).toHaveBeenCalledWith(null)
	})

	it('stays on the record when marking it unread fails', async () => {
		const wrapper = view(RECORD)
		await settle()
		sendReadState.mockResolvedValue(false)

		await wrapper.vm.markUnread()

		expect(objectStore.setObjectItem).not.toHaveBeenCalled()
	})

	it('shows the server\'s message when a Follow change fails', () => {
		view(RECORD).vm.onFollowError('Not allowed')

		expect(showError).toHaveBeenCalledWith('Not allowed')
	})
})
