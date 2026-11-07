import { flushPromises, mount } from '@vue/test-utils'
/**
 * Golden record of the action menus on the admin list pages: the rendered
 * menu of every row state and what each entry does when clicked.
 *
 * @spec exclude Golden record of client-side list page menus.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import ConfigurationCard from '../components/cards/ConfigurationCard.vue'
import ApplicationsIndex from './application/ApplicationsIndex.vue'
import ConfigurationsIndex from './configuration/ConfigurationsIndex.vue'
import DeletedIndex from './deleted/DeletedIndex.vue'
import AuditTrailIndex from './logs/AuditTrailIndex.vue'
import SearchTrailIndex from './logs/SearchTrailIndex.vue'
import OrganisationsIndex from './organisation/OrganisationsIndex.vue'
import RegistersIndex from './register/RegistersIndex.vue'
import SchemasIndex from './schema/SchemasIndex.vue'
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
	NcCheckboxRadioSwitch: mockLibrary().NcCheckboxRadioSwitch,
	NcEmptyContent: mockLibrary().NcEmptyContent,
	NcLoadingIcon: mockLibrary().NcLoadingIcon,
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
jest.mock('vue-material-design-icons/Broom.vue', () => mockIcon('Broom'))
jest.mock('vue-material-design-icons/CheckCircle.vue', () => mockIcon('CheckCircle'))
jest.mock('vue-material-design-icons/Cog.vue', () => mockIcon('Cog'))
jest.mock('vue-material-design-icons/CloudUpload.vue', () => mockIcon('CloudUpload'))
jest.mock('vue-material-design-icons/CompareHorizontal.vue', () =>
	mockIcon('CompareHorizontal'),
)
jest.mock('vue-material-design-icons/ContentCopy.vue', () => mockIcon('ContentCopy'))
jest.mock('vue-material-design-icons/Delete.vue', () => mockIcon('Delete'))
jest.mock('vue-material-design-icons/DeleteOutline.vue', () =>
	mockIcon('DeleteOutline'),
)
jest.mock('vue-material-design-icons/EyeOutline.vue', () => mockIcon('EyeOutline'))
jest.mock('vue-material-design-icons/FormatListChecks.vue', () =>
	mockIcon('FormatListChecks'),
)
jest.mock('vue-material-design-icons/Plus.vue', () => mockIcon('Plus'))
jest.mock('vue-material-design-icons/Restore.vue', () => mockIcon('Restore'))
jest.mock('vue-material-design-icons/Sync.vue', () => mockIcon('Sync'))
jest.mock('vue-material-design-icons/TextBoxOutline.vue', () =>
	mockIcon('TextBoxOutline'),
)
jest.mock('vue-material-design-icons/AlertCircle.vue', () => mockIcon('AlertCircle'))
jest.mock('vue-material-design-icons/ApplicationCog.vue', () =>
	mockIcon('ApplicationCog'),
)
jest.mock('vue-material-design-icons/ClockOutline.vue', () =>
	mockIcon('ClockOutline'),
)
jest.mock('vue-material-design-icons/Cloud.vue', () => mockIcon('Cloud'))
jest.mock('vue-material-design-icons/Magnify.vue', () => mockIcon('Magnify'))
jest.mock('vue-material-design-icons/SourceBranch.vue', () =>
	mockIcon('SourceBranch'),
)
jest.mock('vue-material-design-icons/Star.vue', () => mockIcon('Star'))
jest.mock('vue-material-design-icons/Tag.vue', () => mockIcon('Tag'))
jest.mock('vue-material-design-icons/Update.vue', () => mockIcon('Update'))
jest.mock('vue-material-design-icons/DeleteEmpty.vue', () => mockIcon('DeleteEmpty'))
jest.mock('vue-material-design-icons/MagnifyPlus.vue', () => mockIcon('MagnifyPlus'))
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
					if (globalThis.storeLists && key in globalThis.storeLists)
						return globalThis.storeLists[key]
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
	schemas: {
		component: SchemasIndex,
		rows: [
			{ id: 1, title: 'Free', stats: { objects: { total: 0 } } },
			{ id: 2, title: 'Used', stats: { objects: { total: 2 } } },
		],
	},
	auditTrails: {
		component: AuditTrailIndex,
		rows: [
			{ id: 1, action: 'update', changed: { a: 1 } },
			{ id: 2, action: 'read' },
		],
	},
	deleted: {
		component: DeletedIndex,
		lists: {
			deletedList: [{ id: 1, uuid: 'd1', '@self': { id: 'd1', deleted: {} } }],
			deletedLoading: false,
			deletedPagination: { total: 1, page: 1, pages: 1, limit: 20 },
		},
	},
	searchTrails: {
		component: SearchTrailIndex,
		lists: {
			searchTrailList: [
				{ id: 1, searchTerm: 'a', queryParameters: { x: 1 } },
				{ id: 2 },
			],
			searchTrailLoading: false,
			searchTrailPagination: { total: 2, page: 1, pages: 1, limit: 20 },
		},
	},
	configurations: {
		component: ConfigurationsIndex,
		lists: {
			configurationList: [
				{ id: 1, title: 'Local', sourceType: 'local' },
				{
					id: 2,
					title: 'Remote',
					sourceType: 'github',
					localVersion: '1',
					remoteVersion: '2',
				},
			],
			loading: false,
			error: null,
		},
		viewMode: 'table',
	},
	card: {
		component: ConfigurationCard,
		props: {
			configuration: {
				id: 1,
				title: 'Local',
				isLocal: true,
				sourceType: 'local',
			},
		},
	},
	cardRemote: {
		component: ConfigurationCard,
		props: {
			configuration: {
				id: 2,
				title: 'Remote',
				sourceType: 'github',
				localVersion: '1',
				remoteVersion: '2',
				sourceUrl: 'https://example.org/a',
			},
		},
	},
	cardDiscovered: {
		component: ConfigurationCard,
		props: {
			configuration: {
				config: { app: 'x', title: 'Found' },
				repository: 'a/b',
			},
		},
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
		.replace(/ data-v-[a-f0-9]+(="")?/g, '')
		.replace(/ class=""/g, '')
		.replace(/<x-ncactions[^>]*>/g, '<x-ncactions>')
		.replace(
			/<slot-icon>\s*<x-dotshorizontal[^>]*><\/x-dotshorizontal>\s*<\/slot-icon>/g,
			'',
		)
		.replace(/(<x-ncactionbutton[^>]*) primary="true"/g, '$1')
		.replace(/\bt:(View|Edit|Delete|Refresh)\b/g, '$1')
		.replace(/(<x-ncactionbutton)([^>]*?) class="(\w+)"/g, '$1 class="$3"$2')
		.replace(/\s+/g, ' ')
		.replace(/ <\/slot-default>/g, '</slot-default>')
		.replace(/> ?</g, '>\n<')
}

describe('list page action menus golden', () => {
	it.each(Object.keys(PAGES))('%s', async (page) => {
		globalThis.listRows = PAGES[page].rows ?? []
		globalThis.storeLists = PAGES[page].lists
		globalThis.listLog = []
		window.OC = {
			getCurrentUser: () => ({ uid: 'me' }),
			dialogs: {
				info: (text, title) => globalThis.listLog.push(['info', title]),
			},
			Notification: { showTemporary: () => {} },
		}
		window.open = (url) => globalThis.listLog.push(['open', url])
		window.confirm = () => false
		const wrapper = mount(PAGES[page].component, {
			props: PAGES[page].props,
			global: {
				config: {
					globalProperties: {
						t: (app, text) => `t:${text}`,
						$route: { query: {}, path: '/' },
						$router: {
							push: (path) => globalThis.listLog.push(['push', path]),
						},
					},
				},
			},
		})
		await flushPromises()
		if (PAGES[page].viewMode) {
			wrapper.vm.$.proxy.viewMode = PAGES[page].viewMode
			await flushPromises()
		}
		const html = markup(wrapper)
		expect(html).toContain('x-ncactionbutton')
		expect(html).toMatchSnapshot()

		const clicks = []
		for (const button of wrapper.findAllComponents({ name: 'NcActionButton' })) {
			globalThis.listLog = []
			await button.vm.$attrs.onClick()
			await flushPromises()
			clicks.push([
				button.text().replace(/^t:(View|Edit|Delete|Refresh)$/, '$1'),
				...globalThis.listLog,
			])
		}
		expect(clicks.length).toBeGreaterThan(0)
		expect(clicks).toMatchSnapshot()
	})
})
