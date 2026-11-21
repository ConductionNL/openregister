/**
 * Golden record of the Uses and Used by tabs of the object detail view:
 * their list items with icon and subname, and the empty state.
 *
 * @spec exclude Golden record of client-side object relation list markup.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { mount } from '@vue/test-utils'
import ObjectDetails from './ObjectDetails.vue'
import { objectStore } from '../../store/store.js'

jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => `t:${text}` }))
jest.mock('@nextcloud/vue', () => mockLibrary())
jest.mock('@conduction/nextcloud-vue', () =>
	mockLibrary({ useIntegrationRegistry: () => ({ integrations: { value: [] } }) }),
)
jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: {} }))
jest.mock('@nextcloud/dialogs', () => ({ __esModule: true, default: {} }))
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

const RELATIONS = [
	{
		'@self': { id: 'u1', name: 'First', uri: '/objects/u1' },
		id: 'u1',
		title: 'First',
	},
	{ uuid: 'u2', name: 'Second', relation: { displayLabel: 'Parent of' } },
	'plain-uuid',
]

/**
 * Render the view with these relations and return the two relation tabs.
 *
 * @param {object} relations uses and used results
 * @return {string} The markup of the Uses and Used by tabs
 */
function renderTabs(relations) {
	for (const key of Object.keys(objectStore)) delete objectStore[key]
	Object.assign(objectStore, {
		objectItem: { id: 'o1', '@self': { id: 'o1' } },
		uses: { results: relations.uses },
		used: { results: relations.used, total: relations.used.length },
		files: { results: [] },
		auditTrails: { results: [] },
	})
	const View = {
		...ObjectDetails,
		mounted() {},
		beforeUnmount() {},
		watch: {},
	}
	const wrapper = mount(View)
	const tabs = wrapper
		.findAll('x-AppTab')
		.filter((tab) =>
			['t:Uses', 't:Used by'].includes(tab.attributes('title')),
		)
	const html = tabs.map((tab) => tab.html()).join('\n')
	wrapper.unmount()
	return html
}

describe('ObjectDetails relation tabs (DOM golden)', () => {
	it('lists the relations of both tabs', () => {
		expect(renderTabs({ uses: RELATIONS, used: RELATIONS })).toMatchSnapshot()
	})

	it('shows the empty state of both tabs', () => {
		expect(renderTabs({ uses: [], used: [] })).toMatchSnapshot()
	})
})
