import { flushPromises, mount } from '@vue/test-utils'
/**
 * Golden record of the name/title cell the CnIndexPage list views hand to
 * the table, rendered for a row with and one without a description.
 *
 * @spec exclude Golden record of client-side list markup.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import ApplicationsIndex from './application/ApplicationsIndex.vue'
import OrganisationsIndex from './organisation/OrganisationsIndex.vue'
import RegistersIndex from './register/RegistersIndex.vue'
import SchemasIndex from './schema/SchemasIndex.vue'
import SourcesIndex from './source/SourcesIndex.vue'

/**
 * A stand-in that answers every property read and call with itself, so a
 * view's store reads and fetches resolve to something inert.
 *
 * @return {object} The stand-in
 */
function inert() {
	let proxy = null
	proxy = new Proxy(() => proxy, {
		get: (target, key) => {
			if (key === 'then') return undefined
			if (key === Symbol.iterator) return [][Symbol.iterator]
			if (key === Symbol.toPrimitive) return () => ''
			if (key === 'length') return 0
			return proxy
		},
		apply: () => proxy,
	})
	return proxy
}

jest.mock('../store/store.js', () => new Proxy({ __esModule: true }, {
	get: (target, key) => (key === '__esModule' ? true : globalThis.inertStore),
}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => 't:' + text,
	translatePlural: (app, one, many) => 'n:' + many,
	getLanguage: () => 'en',
	getLocale: () => 'en',
}))
jest.mock('@nextcloud/vue', () => jest.requireActual('../../tests/unit/support/ncGolden.js').ncLibrary())
// The pages mix <script setup> and an options block; their compiled templates
// read the library components through 'vue', as listActions.dom.golden.spec.js notes.
jest.mock('vue', () => {
	const nc = jest.requireActual('../../tests/unit/support/ncGolden.js').ncLibrary()
	const names = ['NcActionButton', 'NcAppContent', 'NcButton']
	return { ...jest.requireActual('vue'), ...Object.fromEntries(names.map((name) => [name, nc[name]])) }
})
jest.mock('@conduction/nextcloud-vue', () => {
	const { h } = jest.requireActual('vue')
	const rows = [
		{ id: 7, uuid: 'u-7', name: 'Named', title: 'Titled', description: 'Says what it is', isDefault: true },
		{ id: 8, uuid: 'u-8', name: 'Bare', title: 'Bare title', description: '' },
	]
	return {
		__esModule: true,
		CnIndexPage: {
			name: 'CnIndexPage',
			render() {
				return h('div', { class: 'slots' }, ['column-name', 'column-title']
					.filter((name) => this.$slots[name])
					.flatMap((name) => rows.map((row) => h('p', { class: name }, this.$slots[name]({ row })))))
			},
		},
	}
})
jest.mock('@nextcloud/dialogs', () => ({ showError: jest.fn(), showSuccess: jest.fn() }))
jest.mock('@nextcloud/axios', () => ({ get: jest.fn(), post: jest.fn() }))
jest.mock('vue-material-design-icons/AccountMultiplePlus.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/AccountPlus.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/Api.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/Check.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/CloudUploadOutline.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/DatabaseArrowRightOutline.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/DotsHorizontal.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/Download.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/Eye.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/InformationOutline.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/OfficeBuilding.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/OpenInNew.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/Pencil.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/Refresh.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/SwapHorizontal.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/TrashCanOutline.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/Upload.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/ApplicationOutline.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('../modals/organisation/SwitchOrganisationModal.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('../components/cards/RegisterSchemaCard.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('../components/register/RegisterFormFields.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('../components/shared/ManagedBadge.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('../components/shared/ActionMenu.vue', () => ({
	__esModule: true,
	default: {
		name: 'ActionMenu',
		props: ['actions'],
		render() {
			const { h } = jest.requireActual('vue')
			return h('menu', this.actions.map((a) => h('button', a.label)))
		},
	},
}))
jest.mock('../services/listDateTime.js', () => ({ listDateTime: (v) => 'date:' + v }))

describe('index name cells', () => {
	beforeAll(() => {
		globalThis.inertStore = inert()
	})

	it.each([
		['ApplicationsIndex', ApplicationsIndex],
		['OrganisationsIndex', OrganisationsIndex],
		['RegistersIndex', RegistersIndex],
		['SchemasIndex', SchemasIndex],
		['SourcesIndex', SourcesIndex],
	])('%s', async (name, View) => {
		const wrapper = mount(View, { global: { config: { warnHandler: () => {} } } })
		await flushPromises()
		expect(wrapper.findAll('.slots > p').map((cell) => cell.html()).join('\n')).toMatchSnapshot()
	})
})
