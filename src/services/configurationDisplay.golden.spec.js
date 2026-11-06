/**
 * Golden record of what the configuration card, list and import modal share:
 * the source and sync labels and the version check.
 *
 * @spec exclude Golden record of client-side configuration display helpers.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import ConfigurationCard from '../components/cards/ConfigurationCard.vue'
import ImportConfiguration from '../modals/configuration/ImportConfiguration.vue'
import ConfigurationsIndex from '../views/configuration/ConfigurationsIndex.vue'

jest.mock('@nextcloud/l10n', () => ({
	t: (app, text, vars) => `t:${text}${vars ? JSON.stringify(vars) : ''}`,
	translate: (app, text, vars) => `t:${text}${vars ? JSON.stringify(vars) : ''}`,
	translatePlural: (app, one) => one,
}))
jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { post: jest.fn() },
}))
jest.mock('@nextcloud/dialogs', () => ({
	showError: jest.fn(),
	showSuccess: jest.fn(),
}))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/vue', () => ({ __esModule: true }))
jest.mock('@conduction/nextcloud-vue', () => ({ __esModule: true }))
jest.mock('vue-material-design-icons/AlertCircle.vue', () => ({
	__esModule: true,
	default: {},
}))
jest.mock('vue-material-design-icons/ApplicationCog.vue', () => ({
	__esModule: true,
	default: {},
}))
jest.mock('vue-material-design-icons/Cancel.vue', () => ({
	__esModule: true,
	default: {},
}))
jest.mock('vue-material-design-icons/Check.vue', () => ({
	__esModule: true,
	default: {},
}))
jest.mock('vue-material-design-icons/CheckCircle.vue', () => ({
	__esModule: true,
	default: {},
}))
jest.mock('vue-material-design-icons/ClockOutline.vue', () => ({
	__esModule: true,
	default: {},
}))
jest.mock('vue-material-design-icons/Cloud.vue', () => ({
	__esModule: true,
	default: {},
}))
jest.mock('vue-material-design-icons/CloudUpload.vue', () => ({
	__esModule: true,
	default: {},
}))
jest.mock('vue-material-design-icons/CloudUploadOutline.vue', () => ({
	__esModule: true,
	default: {},
}))
jest.mock('vue-material-design-icons/CogOutline.vue', () => ({
	__esModule: true,
	default: {},
}))
jest.mock('vue-material-design-icons/DotsHorizontal.vue', () => ({
	__esModule: true,
	default: {},
}))
jest.mock('vue-material-design-icons/Download.vue', () => ({
	__esModule: true,
	default: {},
}))
jest.mock('vue-material-design-icons/Eye.vue', () => ({
	__esModule: true,
	default: {},
}))
jest.mock('vue-material-design-icons/EyeOutline.vue', () => ({
	__esModule: true,
	default: {},
}))
jest.mock('vue-material-design-icons/FileUpload.vue', () => ({
	__esModule: true,
	default: {},
}))
jest.mock('vue-material-design-icons/Github.vue', () => ({
	__esModule: true,
	default: {},
}))
jest.mock('vue-material-design-icons/Gitlab.vue', () => ({
	__esModule: true,
	default: {},
}))
jest.mock('vue-material-design-icons/Import.vue', () => ({
	__esModule: true,
	default: {},
}))
jest.mock('vue-material-design-icons/LinkVariant.vue', () => ({
	__esModule: true,
	default: {},
}))
jest.mock('vue-material-design-icons/Magnify.vue', () => ({
	__esModule: true,
	default: {},
}))
jest.mock('vue-material-design-icons/OfficeBuilding.vue', () => ({
	__esModule: true,
	default: {},
}))
jest.mock('vue-material-design-icons/OpenInNew.vue', () => ({
	__esModule: true,
	default: {},
}))
jest.mock('vue-material-design-icons/Pencil.vue', () => ({
	__esModule: true,
	default: {},
}))
jest.mock('vue-material-design-icons/Plus.vue', () => ({
	__esModule: true,
	default: {},
}))
jest.mock('vue-material-design-icons/Refresh.vue', () => ({
	__esModule: true,
	default: {},
}))
jest.mock('vue-material-design-icons/SourceBranch.vue', () => ({
	__esModule: true,
	default: {},
}))
jest.mock('vue-material-design-icons/Star.vue', () => ({
	__esModule: true,
	default: {},
}))
jest.mock('vue-material-design-icons/Sync.vue', () => ({
	__esModule: true,
	default: {},
}))
jest.mock('vue-material-design-icons/Tag.vue', () => ({
	__esModule: true,
	default: {},
}))
jest.mock('vue-material-design-icons/TrashCanOutline.vue', () => ({
	__esModule: true,
	default: {},
}))
jest.mock('vue-material-design-icons/Update.vue', () => ({
	__esModule: true,
	default: {},
}))
jest.mock('../store/store.js', () => ({
	configurationStore: { refreshConfigurationList: jest.fn() },
	navigationStore: {},
}))

const axios = jest.requireMock('@nextcloud/axios').default
const dialogs = jest.requireMock('@nextcloud/dialogs')
const { configurationStore } = jest.requireMock('../store/store.js')

const NOW = new Date('2026-03-10T12:00:00Z')
const hoursAgo = (hours) => new Date(NOW.getTime() - hours * 3600000).toISOString()

const SYNCS = [
	{ syncStatus: 'success', lastSyncDate: hoursAgo(0) },
	{ syncStatus: 'success', lastSyncDate: hoursAgo(1) },
	{ syncStatus: 'success', lastSyncDate: hoursAgo(23) },
	{ syncStatus: 'success', lastSyncDate: hoursAgo(24) },
	{ syncStatus: 'success', lastSyncDate: hoursAgo(49) },
	{ syncStatus: 'success' },
	{ syncStatus: 'failed' },
	{ syncStatus: 'pending' },
	{},
]

const SOURCES = ['local', 'github', 'gitlab', 'url', 'other', undefined]

const ANSWERS = [
	() =>
		Promise.resolve({
			data: { hasUpdate: true, localVersion: '1', remoteVersion: '2' },
		}),
	() => Promise.resolve({ data: { hasUpdate: false } }),
	() =>
		Promise.reject(
			Object.assign(new Error('plain'), {
				response: { data: { error: 'said' } },
			}),
		),
	() => Promise.reject(new Error('plain')),
]

describe('configuration display golden', () => {
	beforeEach(() => {
		jest.useFakeTimers().setSystemTime(NOW)
		jest.spyOn(console, 'error').mockImplementation(() => {})
	})
	afterEach(() => {
		jest.useRealTimers()
		jest.restoreAllMocks()
	})

	it.each([
		['card', ConfigurationCard],
		['list', ConfigurationsIndex],
	])('%s labels', (name, component) => {
		expect(
			SOURCES.map((source) => component.methods.getSourceTypeLabel(source)),
		).toMatchSnapshot()
		expect(
			SYNCS.map((sync) => component.methods.getSyncStatusText(sync)),
		).toMatchSnapshot()
	})

	it.each([
		['list', () => ConfigurationsIndex.methods.checkVersion],
		['import modal', () => ImportConfiguration.methods.handleCheckVersion],
	])('%s version check', async (name, method) => {
		const seen = []
		for (const answer of ANSWERS) {
			axios.post = jest.fn(answer)
			dialogs.showError.mockClear()
			dialogs.showSuccess.mockClear()
			configurationStore.refreshConfigurationList.mockClear()
			await method()({ id: 5 })
			seen.push({
				posted: axios.post.mock.calls,
				success: dialogs.showSuccess.mock.calls,
				error: dialogs.showError.mock.calls,
				refreshed:
					configurationStore.refreshConfigurationList.mock.calls.length,
			})
		}
		expect(seen).toMatchSnapshot()
	})
})
