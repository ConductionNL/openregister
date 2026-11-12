/**
 * Golden record of the register and schema pickers of the search sidebar:
 * their labels, select attributes, option slot and selection hints, with
 * nothing and with something selected.
 *
 * @spec exclude Golden record of client-side sidebar markup.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { mount } from '@vue/test-utils'
import SearchSideBar from './SearchSideBar.vue'

jest.mock('@conduction/nextcloud-vue', () => ({
	CnIndexSidebar: {
		name: 'CnIndexSidebar',
		render() {
			const { h } = require('vue')
			return h('x-cnindexsidebar', this.$slots['search-above']?.({}))
		},
	},
}))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => `t:${text}` }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/vue', () => mockLibrary())
jest.mock('vue-material-design-icons/CogOutline.vue', () => mockLibrary().Icon)
jest.mock('vue-material-design-icons/ContentSaveOutline.vue', () => mockLibrary().Icon)
jest.mock('vue-material-design-icons/Delete.vue', () => mockLibrary().Icon)
jest.mock('vue-material-design-icons/Filter.vue', () => mockLibrary().Icon)
jest.mock('vue-material-design-icons/Magnify.vue', () => mockLibrary().Icon)
jest.mock('vue-material-design-icons/Pencil.vue', () => mockLibrary().Icon)
jest.mock('vue-material-design-icons/Star.vue', () => mockLibrary().Icon)
jest.mock('vue-material-design-icons/StarOutline.vue', () => mockLibrary().Icon)
jest.mock('vue-material-design-icons/ViewDashboardOutline.vue', () => mockLibrary().Icon)
jest.mock('../../modals/view/DeleteView.vue', () => ({ render: () => null }))
jest.mock('../../modals/view/EditView.vue', () => ({ render: () => null }))
jest.mock('../../store/store.js', () => ({}))

const stores = jest.requireMock('../../store/store.js')

/**
 * Library stand-ins: every named export is a stub of that name that shows
 * its plain attributes and its option slot filled with a sample option.
 *
 * @return {object} The module
 */
function mockLibrary() {
	globalThis.searchStubs = globalThis.searchStubs || {}
	const made = globalThis.searchStubs
	return new Proxy(
		{ __esModule: true },
		{
			get(target, key) {
				if (key === 'default') return mockLibrary().Icon
				if (key in target || typeof key !== 'string') return target[key]
				made[key] = made[key] || mockStub(key)
				return made[key]
			},
			has: () => true,
		},
	)
}

/**
 * A stand-in component.
 *
 * @param {string} name The component name
 * @return {object} The stub
 */
function mockStub(name) {
	const { h } = require('vue')
	const text = (value) =>
		typeof value === 'object' || typeof value === 'function'
			? JSON.stringify(value)
			: value
	return {
		name,
		inheritAttrs: false,
		render() {
			const attrs = Object.fromEntries(
				Object.entries(this.$attrs)
					.filter(([key]) => !/^on[A-Z]/.test(key))
					.map(([key, value]) => [key, text(value)]),
			)
			const sample = { title: 'Pets', description: 'All pets' }
			return h(
				`x-${name}`,
				attrs,
				Object.keys(this.$slots)
					.sort()
					.map((slot) => h(`slot-${slot}`, this.$slots[slot](sample))),
			)
		},
	}
}

/**
 * Anything at all: the store parts the pickers do not read.
 */
const anything = new Proxy(function() {}, {
	get(target, key) {
		if (key === Symbol.toPrimitive) return () => ''
		if (key === Symbol.iterator) return [][Symbol.iterator].bind([])
		if (key === 'then' || key === 'toJSON') return undefined
		if (key === 'length') return 0
		return anything
	},
	apply: () => anything,
})

/**
 * A store that answers every unknown field with `anything`.
 *
 * @param {object} fields The known fields
 * @return {object} The store
 */
function lenient(fields) {
	return new Proxy(fields, {
		get: (target, prop) => (prop in target ? target[prop] : anything),
	})
}

/**
 * The filter section holding the pickers, without scoped ids and comments.
 *
 * @param {object} state Data to set on the sidebar
 * @return {Promise<string>} The markup
 */
async function pickers(state) {
	stores.navigationStore = lenient({ sidebarState: {} })
	stores.objectStore = lenient({ searchParams: { filters: {} } })
	stores.registerStore = lenient({ registerList: [{ id: 1, title: 'R' }] })
	stores.schemaStore = lenient({ schemaList: [{ id: 2, title: 'S' }] })
	stores.viewsStore = lenient({})
	const quiet = { ...SearchSideBar, mounted: undefined, watch: {} }
	const wrapper = mount(quiet, {
		global: {
			mocks: { $route: { path: '/tables', query: {} }, $router: {} },
		},
	})
	await wrapper.setData(state)
	return wrapper
		.find('#registerSelect')
		.element.parentElement.parentElement.outerHTML.replace(
			/ data-v-[a-f0-9]+(="")?/g,
			'',
		)
		.replace(/<!--[\s\S]*?-->/g, '')
		.replace(/>\s*</g, '>\n<')
}

describe('search sidebar scope pickers', () => {
	it('renders the pickers with nothing selected', async () => {
		expect(await pickers({})).toMatchSnapshot()
	})

	it('renders the pickers with registers and schemas selected', async () => {
		expect(
			await pickers({
				selectedRegisters: [{ id: 1 }],
				selectedSchemas: [{ id: 2 }, { id: 3 }],
				registerLoading: true,
				schemaLoading: false,
			}),
		).toMatchSnapshot()
	})
})
