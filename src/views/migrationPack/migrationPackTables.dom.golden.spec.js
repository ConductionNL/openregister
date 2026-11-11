import { flushPromises, mount } from '@vue/test-utils'
import PackPreviewPanel from '../../components/migrationPack/PackPreviewPanel.vue'
import MigrationPacksIndex from './MigrationPacksIndex.vue'
import { tableText } from '../../../tests/unit/support/tableGolden.js'

/**
 * Golden record of the migration pack list and the preview panel's tables:
 * what each one shows (header labels and cell texts, independent of the
 * markup that draws them), the row buttons, and the sample inputs.
 *
 * @spec exclude Golden record of the client-side migration pack tables.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

let mockAdmin = true

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn(), post: jest.fn() } }))
jest.mock('@nextcloud/router', () => ({ __esModule: true, generateUrl: (path) => path }))
jest.mock('@nextcloud/auth', () => ({ __esModule: true, getCurrentUser: () => ({ uid: 'u', isAdmin: mockAdmin }) }))
jest.mock('@nextcloud/l10n', () => ({
	__esModule: true,
	getCanonicalLocale: () => 'en-GB',
	translate: (app, text, vars) => `t:${text}${vars ? JSON.stringify(vars) : ''}`,
	translatePlural: (app, one, many, count) => `n:${count}:${many}`,
}))
jest.mock('@nextcloud/vue', () => jest.requireActual('../../../tests/unit/support/ncGolden.js').ncLibrary())
jest.mock('@conduction/nextcloud-vue', () => {
	const { CnDataTableStub } = jest.requireActual('../../../tests/unit/support/tableGolden.js')
	// A cell without a slot as the library's formatValue draws it: text cut at
	// 100 characters plus an ellipsis, an empty value as a dash.
	const formatValue = (value) => {
		if (value === null || value === undefined || value === '') {
			return '—'
		}
		const text = String(value)
		return text.length > 100 ? `${text.substring(0, 100)}...` : text
	}
	return {
		__esModule: true,
		CnDataTable: {
			...CnDataTableStub,
			render() {
				const $slots = { ...this.$slots }
				for (const col of this.columns) {
					$slots[`column-${col.key}`] ||= ({ value }) => formatValue(value)
				}
				return CnDataTableStub.render.call(new Proxy(this, {
					get: (target, key) => (key === '$slots' ? $slots : target[key]),
				}))
			},
		},
	}
})
jest.mock('vue-material-design-icons/Download.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/Eye.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/Pencil.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/TrashCanOutline.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/Upload.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('../../dialogs/migrationPack/DeleteMigrationPackDialog.vue', () => ({
	__esModule: true,
	default: { name: 'DeleteMigrationPackDialog', props: ['pack'], render: () => null },
}))

const PACKS = [
	{ id: 2, name: 'Zeta', packSlug: 'zeta', sourceFormat: 'csv', version: '1.0', builtin: true, updatedAt: null },
	{ id: 1, packSlug: 'alpha', sourceFormat: 'xlsx', version: '2', builtin: false, updatedAt: null },
]

/**
 * Mount the list over these packs.
 *
 * @param {Array} packs The packs the API returns
 * @return {Promise<object>} The wrapper
 */
async function mountList(packs) {
	jest.requireMock('@nextcloud/axios').default.get.mockResolvedValue({ data: { results: packs } })
	const wrapper = mount(MigrationPacksIndex, {
		global: {
			stubs: { 'router-link': { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' } },
			mocks: { $router: { push: jest.fn() } },
		},
	})
	await flushPromises()
	return wrapper
}

describe('MigrationPacksIndex table', () => {
	afterEach(() => {
		mockAdmin = true
	})

	it.each([true, false])('shows every pack with its buttons (admin %s)', async (admin) => {
		mockAdmin = admin
		const wrapper = await mountList(PACKS)
		expect(tableText(wrapper)).toMatchSnapshot()
		expect(wrapper.findAll('tbody a').map((a) => a.attributes('data-to'))).toMatchSnapshot()
		expect(wrapper.findAll('tbody button').map((b) => b.attributes())).toMatchSnapshot()
	})

	it('lets the name and slug wrap and leaves a missing format blank', async () => {
		const wrapper = await mountList([{ id: 1, name: 'One', packSlug: 'one', sourceFormat: null, version: null, builtin: false, updatedAt: null }])
		const wrapping = wrapper.findComponent({ name: 'CnDataTable' }).props('columns')
			.filter((column) => column.cellClass === 'cn-cell--wrap')
			.map((column) => column.key)
		expect(wrapping).toEqual(['name', 'packSlug'])
		const cells = wrapper.findAll('tbody td').map((cell) => cell.text())
		expect([cells[2], cells[3]]).toEqual(['', ''])
	})

	it('shows the empty state without packs', async () => {
		const wrapper = await mountList([])
		expect(wrapper.findAll('table')).toHaveLength(0)
		expect(wrapper.find('[data-stub="empty"]').text()).toMatchSnapshot()
	})

	it('opens the editor and the delete dialog from a row', async () => {
		const wrapper = await mountList(PACKS)
		const buttons = wrapper.findAll('tbody tr')[0].findAll('button')
		await buttons[0].trigger('click')
		expect(wrapper.vm.$router.push).toHaveBeenCalledWith({ name: 'migration-pack-detail', params: { id: '1' } })
		await buttons[2].trigger('click')
		expect(wrapper.findComponent({ name: 'DeleteMigrationPackDialog' }).props('pack')).toEqual(wrapper.vm.packs[0])
	})
})

describe('PackPreviewPanel tables', () => {
	it('asks a sample value per source column and keeps what is typed', async () => {
		const wrapper = mount(PackPreviewPanel, { props: { definition: null, columns: ['Naam', 'Datum'] } })
		expect(tableText(wrapper)).toMatchSnapshot()
		const inputs = wrapper.findAll('input')
		expect(inputs.map((i) => i.attributes('aria-label'))).toMatchSnapshot()
		await inputs[1].setValue('2026-01-01')
		expect(wrapper.vm.sample).toEqual({ Datum: '2026-01-01' })
		wrapper.unmount()
	})

	it('shows the fields the import would write and the row problems', async () => {
		const wrapper = mount(PackPreviewPanel, { props: { definition: null, columns: [] } })
		await wrapper.setData({
			result: {
				data: { naam: 'Jan', leeftijd: 4, adres: { stad: 'Ede' } },
				errors: [
					{ source: 'Datum', target: 'geboren', message: 'Not a date' },
					{ message: 'Row is empty' },
				],
			},
		})
		expect(tableText(wrapper)).toMatchSnapshot()
		wrapper.unmount()
	})

	it('keeps a long problem whole and lets the free text wrap', async () => {
		const long = 'A long sentence that runs on. '.repeat(6).trim()
		const wrapper = mount(PackPreviewPanel, { props: { definition: null, columns: [] } })
		await wrapper.setData({ result: { data: { naam: long }, errors: [{ source: 'Datum', target: 'geboren', message: long }] } })
		expect(wrapper.findAll('tbody td').map((cell) => cell.text()).filter((text) => text.includes(long))).toHaveLength(2)
		const [fields, problems] = wrapper.findAllComponents({ name: 'CnDataTable' }).map((table) => table.props('columns')
			.filter((column) => column.cellClass === 'cn-cell--wrap')
			.map((column) => column.key))
		expect(fields).toEqual(['name', 'value'])
		expect(problems).toEqual(['source', 'target', 'message'])
		wrapper.unmount()
	})

	it('says so when no field came out', async () => {
		const wrapper = mount(PackPreviewPanel, { props: { definition: null, columns: [] } })
		await wrapper.setData({ result: { data: {}, errors: [] } })
		expect(wrapper.findAll('table')).toHaveLength(0)
		expect(wrapper.text()).toContain('No fields: every mapping was empty or failed.')
		wrapper.unmount()
	})
})
