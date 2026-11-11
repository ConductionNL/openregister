import AvgIndex from './AvgIndex.vue'
import { avgStore as mockStore } from '../../store/store.js'

/**
 * Golden record of the AVG page's subject-rights actions: the erasure that
 * asks first, the portability export, the archive that asks first, and the
 * case bundle download. Each pins what is asked, what the store is called
 * with, which file the browser gets, and that a refusal (the store has
 * already shown it) leaves nothing else done.
 *
 * @spec exclude Golden record of client-side GDPR actions.
 */


jest.mock('../../store/store.js', () => ({
	__esModule: true,
	avgStore: {},
}))
jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), post: jest.fn(), put: jest.fn() },
}))
jest.mock('@nextcloud/router', () => ({
	__esModule: true,
	generateUrl: jest.fn((p) => p),
}))
// Stub @nextcloud/vue wholesale — we exercise the options object, not the
// rendered DOM, so the heavy real component chain (auth/logger/storage) is
// unnecessary and otherwise breaks under jsdom.
jest.mock(
	'@nextcloud/vue',
	() =>
		new Proxy(
			{ __esModule: true },
			{
				get: (target, prop) =>
					prop in target
						? target[prop]
						: { name: String(prop), render: () => null },
			},
		),
)
// vue-material-design-icons ships ESM .vue files that this repo's jest
// transform chain does not compile (no existing spec imports icons). Stub
// each imported icon so the component options object can be required.
jest.mock(
	'vue-material-design-icons/AccountCheckOutline.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/AccountSearch.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/AlertCircleOutline.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/AlertOutline.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/Archive.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/ArrowLeft.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/BankOutline.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/CheckCircleOutline.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/CheckboxMarkedCircleOutline.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/ClipboardTextClockOutline.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/ClockOutline.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/DatabaseSearchOutline.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/Download.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/EyeOutline.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/FileDocumentEditOutline.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/FileDocumentOutline.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/Magnify.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/MagnifyClose.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/Marker.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/OpenInNew.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/Pencil.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/Plus.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/Refresh.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/ShieldAlertOutline.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/ShieldLockOutline.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/TrashCanOutline.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)


let events

/**
 * A bare page carrying the page's methods, with the given data.
 *
 * @param {object} state The data
 * @return {object} The page
 */
function pageWith(state) {
	const page = { ...state }
	for (const [name, method] of Object.entries(AvgIndex.methods)) {
		page[name] = method.bind(page)
	}
	return page
}

/**
 * A store call that records itself, then answers.
 *
 * @param {string} name The call
 * @param {Function} answer The answer
 * @return {Function} The mock
 */
function recorded(name, answer) {
	return jest.fn((...args) => {
		events.push([name, ...args])
		return answer()
	})
}

beforeEach(() => {
	events = []
	global.confirm = jest.fn((text) => {
		events.push(['confirm', text])
		return true
	})
	URL.createObjectURL = jest.fn((blob) => {
		events.push(['createObjectURL', blob instanceof Blob ? `Blob(${blob.type}, ${blob.size})` : blob])
		return 'blob:avg'
	})
	URL.revokeObjectURL = jest.fn((url) => events.push(['revokeObjectURL', url]))
	jest.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function () {
		events.push(['click', this.href, this.download])
	})
})

afterEach(() => jest.restoreAllMocks())

describe('AvgIndex.confirmErasure', () => {
	it.each([
		['confirmed', true, { subject: 's-1', type: 'bsn' }, { matchedCount: 3 }, () => Promise.resolve({})],
		['confirmed without a type or count', true, { subject: 's-1', type: '' }, null, () => Promise.resolve({})],
		['declined', false, { subject: 's-1', type: 'bsn' }, { matchedCount: 3 }, () => Promise.resolve({})],
		['refused by the store', true, { subject: 's-1', type: 'bsn' }, { matchedCount: 1 }, () => Promise.reject(new Error('403'))],
	])('erases when %s', async (label, answer, dsar, dsarSummary, erasure) => {
		global.confirm.mockImplementation((text) => {
			events.push(['confirm', text])
			return answer
		})
		mockStore.runErasure = recorded('runErasure', erasure)
		await pageWith({ dsar, dsarSummary }).confirmErasure()
		expect(events).toMatchSnapshot()
	})
})

describe('AvgIndex.downloadPortability', () => {
	it.each([
		['an export', { subject: 'jan@example.nl', type: 'email' }, () => Promise.resolve({ name: 'Jan' })],
		['no export', { subject: 's', type: '' }, () => Promise.resolve(null)],
		['a refusal', { subject: 's', type: 'bsn' }, () => Promise.reject(new Error('403'))],
	])('saves %s', async (label, dsar, portability) => {
		mockStore.runPortability = recorded('runPortability', portability)
		await pageWith({ dsar }).downloadPortability()
		expect(events).toMatchSnapshot()
	})
})

describe('AvgIndex.archiveActivity', () => {
	it.each([
		['confirmed', true, () => Promise.resolve()],
		['declined', false, () => Promise.resolve()],
		['refused by the store', true, () => Promise.reject(new Error('403'))],
	])('archives when %s', async (label, answer, archive) => {
		global.confirm.mockImplementation((text) => {
			events.push(['confirm', text])
			return answer
		})
		mockStore.archiveActivity = recorded('archiveActivity', archive)
		const page = pageWith({})
		page.refreshActivities = async () => events.push(['refreshActivities'])
		await page.archiveActivity({ uuid: 'pa-1' })
		expect(events).toMatchSnapshot()
	})
})

describe('AvgIndex.generateAndDownloadBundle', () => {
	it.each([
		['a bundle', () => Promise.resolve({ downloadToken: 'tok' }), () => Promise.resolve(new Blob(['%PDF'], { type: 'application/pdf' }))],
		['no token', () => Promise.resolve({}), () => Promise.resolve(new Blob(['x']))],
		['no file', () => Promise.resolve({ downloadToken: 'tok' }), () => Promise.resolve(null)],
		['a refused generation', () => Promise.reject(new Error('403')), () => Promise.resolve(null)],
		['a refused download', () => Promise.resolve({ downloadToken: 'tok' }), () => Promise.reject(new Error('410'))],
	])('downloads %s', async (label, generate, download) => {
		mockStore.generateBundle = recorded('generateBundle', generate)
		mockStore.downloadBundle = recorded('downloadBundle', download)
		const page = pageWith({ selectedCaseId: 'c-9' })
		page.reloadSelectedCase = async () => events.push(['reloadSelectedCase'])
		await page.generateAndDownloadBundle()
		expect(events).toMatchSnapshot()
	})
})
