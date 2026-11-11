/**
 * Downloading a register's API specification: the request, the saved file,
 * and the toast when it fails.
 *
 * @spec exclude Client-side download plumbing.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import RegistersIndex from './RegistersIndex.vue'

jest.mock('@nextcloud/axios', () => ({ get: jest.fn() }))
jest.mock('@nextcloud/dialogs', () => ({
	showError: jest.fn(),
	showSuccess: jest.fn(),
}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	translatePlural: (app, one, many) => many,
}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/Api.vue', () => ({}))
jest.mock('vue-material-design-icons/CloudUploadOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/CogOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/DotsHorizontal.vue', () => ({}))
jest.mock('vue-material-design-icons/Download.vue', () => ({}))
jest.mock('vue-material-design-icons/Eye.vue', () => ({}))
jest.mock('vue-material-design-icons/InformationOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Pencil.vue', () => ({}))
jest.mock('vue-material-design-icons/Refresh.vue', () => ({}))
jest.mock('vue-material-design-icons/TrashCanOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Upload.vue', () => ({}))
jest.mock('../../components/cards/RegisterSchemaCard.vue', () => ({}))
jest.mock('../../store/store.js', () => ({
	configurationStore: {},
	navigationStore: {},
	registerStore: {},
	schemaStore: {},
}))

const RealBlob = global.Blob
let steps

beforeEach(() => {
	global.Blob = jest.fn(() => ({ blob: 1 }))
	jest.clearAllMocks()
	steps = []
	URL.createObjectURL = jest.fn(() => 'blob:spec')
	URL.revokeObjectURL = jest.fn((href) => steps.push(['revoke', href]))
	jest.spyOn(document.body, 'appendChild').mockImplementation((node) => {
		steps.push(['append', node.tagName, node.href, node.download])
		return node
	})
	jest.spyOn(document.body, 'removeChild').mockImplementation((node) => {
		steps.push(['remove', node.tagName])
		return node
	})
	jest
		.spyOn(HTMLAnchorElement.prototype, 'click')
		.mockImplementation(() => steps.push(['click']))
	jest.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	global.Blob = RealBlob
	jest.restoreAllMocks()
})

describe('RegistersIndex.downloadOas', () => {
	it('fetches the specification and saves it as pretty JSON', async () => {
		axios.get.mockResolvedValue({ data: { openapi: '3.0.0' } })
		await RegistersIndex.methods.downloadOas({ id: 7, title: 'Pets' })
		expect(axios.get.mock.calls).toEqual([
			['http://localhost/index.php/apps/openregister/api/registers/7/oas'],
		])
		expect(global.Blob.mock.calls).toEqual([
			[['{\n  "openapi": "3.0.0"\n}'], { type: 'application/json' }],
		])
		expect(URL.createObjectURL.mock.calls).toEqual([[{ blob: 1 }]])
		expect(steps).toEqual([
			['append', 'A', 'blob:spec', 'pets-api-specification.json'],
			['click'],
			['remove', 'A'],
			['revoke', 'blob:spec'],
		])
		expect(showError).not.toHaveBeenCalled()
	})

	it('shows an error and saves nothing when the request fails', async () => {
		const error = new Error('Network down')
		axios.get.mockRejectedValue(error)
		await RegistersIndex.methods.downloadOas({ id: 7, title: 'Pets' })
		expect(steps).toEqual([])
		expect(showError.mock.calls).toEqual([
			['Failed to download API specification'],
		])
		expect(console.error.mock.calls).toEqual([['Error downloading OAS:', error]])
	})
})
