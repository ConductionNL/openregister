/**
 * Golden record of the action badge in the audit trail changes and details
 * modals: its classes, icon and label for every action, and the rest of the
 * open dialog around it.
 *
 * @spec exclude Golden record of client-side audit trail badge markup.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import AuditTrailChanges from './AuditTrailChanges.vue'
import AuditTrailDetails from './AuditTrailDetails.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) => `t:${text}${vars ? JSON.stringify(vars) : ''}`,
	translatePlural: (app, one) => one,
}))
jest.mock('@nextcloud/vue', () => mockLibrary())
// The modals mix <script setup> with <script>; under vue3-jest the template
// reads the library components through the `vue` module, as in the list page
// goldens.
jest.mock('vue', () => ({
	...jest.requireActual('vue'),
	NcButton: mockLibrary().NcButton,
	NcDialog: mockLibrary().NcDialog,
	NcEmptyContent: mockLibrary().NcEmptyContent,
	NcNoteCard: mockLibrary().NcNoteCard,
}))
jest.mock('vue-material-design-icons/Close.vue', () => mockIcon('Close'))
jest.mock('vue-material-design-icons/CompareHorizontal.vue', () =>
	mockIcon('CompareHorizontal'),
)
jest.mock('vue-material-design-icons/ContentCopy.vue', () => mockIcon('ContentCopy'))
jest.mock('vue-material-design-icons/Delete.vue', () => mockIcon('Delete'))
jest.mock('vue-material-design-icons/Eye.vue', () => mockIcon('Eye'))
jest.mock('vue-material-design-icons/InformationOutline.vue', () =>
	mockIcon('InformationOutline'),
)
jest.mock('vue-material-design-icons/Pencil.vue', () => mockIcon('Pencil'))
jest.mock('vue-material-design-icons/Plus.vue', () => mockIcon('Plus'))
jest.mock('../../store/store.js', () => {
	const { reactive } = jest.requireActual('vue')
	return {
		auditTrailStore: reactive({ auditTrailItem: null }),
		navigationStore: reactive({ dialog: null, setDialog: () => {} }),
	}
})

/**
 * Library stand-ins: every named export is a stub of that name.
 *
 * @return {object} The module
 */
function mockLibrary() {
	const made = (globalThis.madeStubs = globalThis.madeStubs || {})
	return new Proxy(
		{ __esModule: true },
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
function mockIcon(name) {
	return { __esModule: true, default: mockStub(name) }
}

/**
 * A stand-in for a component: its tag, plain attributes and slots.
 *
 * @param {string} name The component name
 * @return {object} The stub
 */
function mockStub(name) {
	const { h: render } = jest.requireActual('vue')
	return {
		name,
		inheritAttrs: false,
		render() {
			const attrs = Object.fromEntries(
				Object.entries(this.$attrs).filter(
					([key, value]) =>
						!/^on[A-Z]/.test(key) && typeof value !== 'function',
				),
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

const { auditTrailStore, navigationStore } = jest.requireMock('../../store/store.js')

const MODALS = {
	changes: [AuditTrailChanges, 'auditTrailChanges'],
	details: [AuditTrailDetails, 'auditTrailDetails'],
}

const ACTIONS = ['create', 'update', 'delete', 'read', 'lock', undefined]

/**
 * The markup without comment nodes, which only development builds render.
 *
 * @param {string} html The markup
 * @return {string} The markup
 */
function markup(html) {
	return html.replace(/<!--[\s\S]*?-->/g, '')
}

/**
 * Open a modal over an audit trail entry with the given action.
 *
 * @param {Array} modal The component and its dialog name
 * @param {string|undefined} action The action
 * @return {Promise<object>} The wrapper
 */
async function open([component, dialog], action) {
	navigationStore.dialog = dialog
	auditTrailStore.auditTrailItem = {
		id: 42,
		action,
		created: '2026-01-02T03:04:05Z',
		user: 'alice',
		userName: 'Alice',
		object: 'obj-1',
		register: 1,
		schema: 2,
		changed: { title: { old: 'a', new: 'b' } },
	}
	const wrapper = mount(component, {
		global: {
			config: {
				globalProperties: {
					t: (app, text, vars) =>
						`t:${text}${vars ? JSON.stringify(vars) : ''}`,
				},
			},
		},
	})
	await flushPromises()
	return wrapper
}

describe('audit trail action badge golden', () => {
	beforeEach(() => {
		jest.spyOn(Date.prototype, 'toLocaleString').mockReturnValue('local-time')
		jest.spyOn(Date.prototype, 'toLocaleDateString').mockReturnValue(
			'local-date',
		)
		jest.spyOn(Date.prototype, 'toLocaleTimeString').mockReturnValue(
			'local-clock',
		)
	})
	afterEach(() => jest.restoreAllMocks())

	for (const [name, modal] of Object.entries(MODALS)) {
		it(`${name}: renders the badge for every action`, async () => {
			const badges = {}
			for (const action of ACTIONS) {
				const wrapper = await open(modal, action)
				const badge = wrapper.find('.action-badge')
				expect(badge.exists()).toBe(true)
				badges[String(action)] = markup(badge.html())
				wrapper.unmount()
			}
			expect(badges).toMatchSnapshot()
		})

		it(`${name}: renders the open dialog`, async () => {
			const wrapper = await open(modal, 'update')
			expect(markup(wrapper.html()).length).toBeGreaterThan(0)
			expect(markup(wrapper.html())).toMatchSnapshot()
			expect(wrapper.emitted()).toEqual({})
			wrapper.unmount()
		})
	}
})
