import AgentsIndex from '../agents/AgentsIndex.vue'
import MigrationPacksIndex from '../migrationPack/MigrationPacksIndex.vue'
import OperationsConsoleIndex from '../operations/OperationsConsoleIndex.vue'
import FlowOverview from './FlowOverview.vue'
import FlowRunDetail from './FlowRunDetail.vue'

/**
 * Golden record of the components each page-shell view registers, so moving
 * the shared Nextcloud shell components into one module keeps every tag the
 * templates use resolvable.
 *
 * @spec exclude Golden record of component registration, no behaviour.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn(), post: jest.fn() } }))
jest.mock('@nextcloud/router', () => ({ __esModule: true, generateUrl: (path) => path }))
jest.mock('@nextcloud/vue', () => jest.requireActual('../../../tests/unit/support/ncGolden.js').ncLibrary())
jest.mock('@conduction/nextcloud-vue', () => new Proxy({ __esModule: true }, {
	get: (target, key) => (key in target ? target[key] : { name: String(key), render: () => null }),
}))
jest.mock('@nextcloud/auth', () => ({ __esModule: true, getCurrentUser: () => null, getRequestToken: () => '' }))

jest.mock('vue-material-design-icons/CogOutline.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/Download.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/Eye.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/Pencil.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/PlayCircleOutline.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/SitemapOutline.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/TrashCanOutline.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/Upload.vue', () => ({ __esModule: true, default: { render: () => null } }))

const VIEWS = { AgentsIndex, FlowOverview, FlowRunDetail, MigrationPacksIndex, OperationsConsoleIndex }

describe('page-shell views register their components', () => {
	it.each(Object.keys(VIEWS))('%s', (name) => {
		const components = VIEWS[name].components
		expect(Object.keys(components).sort()).toMatchSnapshot()
		for (const key of Object.keys(components)) {
			expect(components[key]).toBeTruthy()
		}
	})
})
