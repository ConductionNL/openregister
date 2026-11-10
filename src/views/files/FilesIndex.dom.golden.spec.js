/**
 * Golden record of the files table header: its cells, the sort indicator and
 * what a header click asks the API for.
 *
 * @spec exclude Golden record of client-side list markup.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import FilesIndex from './FilesIndex.vue'

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn() } }))
jest.mock('@nextcloud/dialogs', () => ({ showError: jest.fn(), showSuccess: jest.fn() }))
jest.mock('@nextcloud/l10n', () => ({ __esModule: true, t: (app, text) => `t:${text}` }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/vue', () => jest.requireActual('../../../tests/unit/support/ncGolden.js').ncLibrary())
jest.mock('vue-material-design-icons/AlertCircleOutline.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/FileDocumentOutline.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/Refresh.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('../../components/FilesSidebar.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('../../components/shared/ActionMenu.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('../../components/shared/FilterToggleHeader.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('../../components/shared/ListActionsBar.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('../../components/shared/OffsetPager.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('../../services/menuActions.js', () => ({ refreshAction: () => ({ label: 'Refresh' }) }))
jest.mock('vue-material-design-icons/Eye.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('../../services/displayDateTime.js', () => ({ displayDateTime: (value) => (value ? `date:${value}` : '-') }))

const FILES = [{ id: 1, fileName: 'a.pdf', mimeType: 'application/pdf', fileSize: 10, chunkCount: 2, entityCount: 1, riskLevel: 'low', extractedAt: '2026-01-01' }]

describe('FilesIndex table header', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.get.mockResolvedValue({ data: { results: FILES, files: FILES, data: FILES, total: 1 } })
	})

	it('renders the header and sorts from it', async () => {
		const wrapper = mount(FilesIndex, { global: { config: { warnHandler: () => {} } } })
		await flushPromises()
		await wrapper.setData({ filesList: FILES, loading: false })
		const header = () => wrapper.find('thead').html()
		const steps = [header()]
		for (const index of [0, 0, 1, 3, 6, 7]) {
			await wrapper.findAll('thead th')[index].trigger('click')
			await flushPromises()
			steps.push(`click ${index}: ${wrapper.vm.sortField} ${wrapper.vm.sortOrder}`)
		}
		steps.push(header())
		steps.push(`api calls: ${axios.get.mock.calls.length}`)
		expect(steps.join('\n')).toMatchSnapshot()
	})
})
