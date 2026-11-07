import { flushPromises, mount } from '@vue/test-utils'
/**
 * Golden record of the action menus on the admin list pages: the rendered
 * menu of every row state and what each entry does when clicked.
 *
 * @spec exclude Golden record of client-side list page menus.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import ApplicationsIndex from './application/ApplicationsIndex.vue'
import OrganisationsIndex from './organisation/OrganisationsIndex.vue'
import RegistersIndex from './register/RegistersIndex.vue'
import SourcesIndex from './source/SourcesIndex.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) => `t:${text}${vars ? JSON.stringify(vars) : ''}`,
	translatePlural: (app, one) => one,
}))
jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: {} }))
jest.mock('@nextcloud/dialogs', () => ({
	showError: jest.fn(),
	showSuccess: jest.fn(),
}))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/vue', () => mockLibrary())
jest.mock('vue', () => ({
	...jest.requireActual('vue'),
	NcActionButton: mockLibrary().NcActionButton,
	NcActions: mockLibrary().NcActions,
	NcAppContent: mockLibrary().NcAppContent,
	NcButton: mockLibrary().NcButton,
}))
jest.mock('@conduction/nextcloud-vue', () => ({
	__esModule: true,
	CnIndexPage: mockIndexPage(),
}))
jest.mock('vue-material-design-icons/AccountMultiplePlus.vue', () =>
	mockIcon('AccountMultiplePlus'),
)
jest.mock('vue-material-design-icons/AccountPlus.vue', () => mockIcon('AccountPlus'))
jest.mock('vue-material-design-icons/Api.vue', () => mockIcon('Api'))
jest.mock('vue-material-design-icons/ApplicationOutline.vue', () =>
	mockIcon('ApplicationOutline'),
)
jest.mock('vue-material-design-icons/Check.vue', () => mockIcon('Check'))
jest.mock('vue-material-design-icons/CloudUploadOutline.vue', () =>
	mockIcon('CloudUploadOutline'),
)
jest.mock('vue-material-design-icons/CogOutline.vue', () => mockIcon('CogOutline'))
jest.mock('vue-material-design-icons/DatabaseArrowRightOutline.vue', () =>
	mockIcon('DatabaseArrowRightOutline'),
)
jest.mock('vue-material-design-icons/DotsHorizontal.vue', () =>
	mockIcon('DotsHorizontal'),
)
jest.mock('vue-material-design-icons/Download.vue', () => mockIcon('Download'))
jest.mock('vue-material-design-icons/Eye.vue', () => mockIcon('Eye'))
jest.mock('vue-material-design-icons/InformationOutline.vue', () =>
	mockIcon('InformationOutline'),
)
jest.mock('vue-material-design-icons/OfficeBuilding.vue', () =>
	mockIcon('OfficeBuilding'),
)
jest.mock('vue-material-design-icons/OpenInNew.vue', () => mockIcon('OpenInNew'))
jest.mock('vue-material-design-icons/Pencil.vue', () => mockIcon('Pencil'))
jest.mock('vue-material-design-icons/Refresh.vue', () => mockIcon('Refresh'))
jest.mock('vue-material-design-icons/SwapHorizontal.vue', () =>
	mockIcon('SwapHorizontal'),
)
jest.mock('vue-material-design-icons/TrashCanOutline.vue', () =>
	mockIcon('TrashCanOutline'),
)
jest.mock('vue-material-design-icons/Upload.vue', () => mockIcon('Upload'))
jest.mock('../modals/organisation/SwitchOrganisationModal.vue', () => ({
	__esModule: true,
	default: { render: () => null },
}))
jest.mock('../components/cards/RegisterSchemaCard.vue', () => ({
	__esModule: true,
	default: { render: () => null },
}))
jest.mock('../components/register/RegisterFormFields.vue', () => ({
	__esModule: true,
	default: { render: () => null },
}))
jest.mock('../services/AppInitializationService.js', () => ({
	reloadAppData: () => Promise.resolve(),
}))
jest.mock('../store/store.js', () => mockStores())

/**
 * Library stand-ins: every named export is a stub of that name.
 *
 * @return {object} The module
 */
function mockLibrary() {
	globalThis.madeStubs = globalThis.madeStubs || {}
	const made = globalThis.madeStubs
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
 * An icon module whose stub carries the icon's name.
 *
 * @param {string} name The icon name
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

/**
 * CnIndexPage stand-in: renders the card and the row-actions slot of every
 * row in globalThis.listRows, below the action bar.
 *
 * @return {object} The stub
 */
function mockIndexPage() {
	const { h: render } = jest.requireActual('vue')
	return {
		name: 'CnIndexPage',
		inheritAttrs: false,
		methods: {
			openFormDialog(row) {
				globalThis.listLog.push(['openFormDialog', row.id])
			},
		},
		render() {
			return render('x-index', [
				render('x-bar', this.$slots['action-items']?.()),
				...globalThis.listRows.flatMap((row) => [
					render('x-card', this.$slots.card?.({ object: row })),
					render('x-row', this.$slots['row-actions']?.({ row })),
				]),
			])
		},
	}
}

/**
 * Stores whose methods log their name and the id of the row they receive.
 *
 * @return {object} The module
 */
function mockStores() {
	const arg = (value) => value?.id ?? value
	const store = (name) =>
		new Proxy(
			{},
			{
				get(target, key) {
					if (typeof key !== 'string' || key === 'then') return undefined
					if (key in target) return target[key]
					if (key === 'userStats')
						return { list: [], total: 2, active: { uuid: 'o2' } }
					if (/^(?!refresh).*List$/.test(key)) return []
					return (...args) => {
						globalThis.listLog.push([`${name}.${key}`, ...args.map(arg)])
						return Promise.resolve([])
					}
				},
			},
		)
	return new Proxy(
		{ __esModule: true },
		{
			get(target, key) {
				if (key in target || typeof key !== 'string') return target[key]
				target[key] = store(key)
				return target[key]
			},
			has: () => true,
		},
	)
}

const PAGES = {
	applications: {
		component: ApplicationsIndex,
		rows: [{ id: 1, uuid: 'a1', title: 'App' }],
	},
	organisations: {
		component: OrganisationsIndex,
		rows: [
			{ id: 1, uuid: 'o1', title: 'Plain', users: [] },
			{ id: 2, uuid: 'o2', title: 'Active', owner: 'system', users: [] },
			{
				id: 3,
				uuid: 'o3',
				title: 'Site',
				website: 'https://example.org',
				owner: 'me',
				users: [],
			},
		],
	},
	registers: {
		component: RegistersIndex,
		rows: [
			{ id: 1, title: 'Empty', stats: { total: 0 } },
			{ id: 2, title: 'Filled', stats: { total: 3 } },
		],
	},
	sources: {
		component: SourcesIndex,
		rows: [{ id: 1, title: 'Source', type: 'internal' }],
	},
}

/**
 * The rendered text of a wrapper, without the comments `v-if` leaves behind.
 *
 * @param {object} wrapper The mounted page
 * @return {string} The markup
 */
function markup(wrapper) {
	return wrapper
		.html()
		.replace(/<!--.*?-->/g, '')
		.replace(/ class=""/g, '')
		.replace(/^\s*\n|^\s+/gm, '')
}

describe('list page action menus golden', () => {
	it.each(Object.keys(PAGES))('%s', async (page) => {
		globalThis.listRows = PAGES[page].rows
		globalThis.listLog = []
		window.OC = { getCurrentUser: () => ({ uid: 'me' }) }
		window.open = (url) => globalThis.listLog.push(['open', url])
		const wrapper = mount(PAGES[page].component, {
			global: {
				config: {
					globalProperties: {
						$route: { query: {}, path: '/' },
						$router: {
							push: (path) => globalThis.listLog.push(['push', path]),
						},
					},
				},
			},
		})
		await flushPromises()
		const html = markup(wrapper)
		expect(html).toContain('x-ncactionbutton')
		expect(html).toMatchSnapshot()

		const clicks = []
		for (const button of wrapper.findAllComponents({ name: 'NcActionButton' })) {
			globalThis.listLog = []
			await button.vm.$attrs.onClick()
			await flushPromises()
			clicks.push([button.text(), ...globalThis.listLog])
		}
		expect(clicks.length).toBeGreaterThan(0)
		expect(clicks).toMatchSnapshot()
	})
})
