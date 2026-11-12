/**
 * The e-Depot host key section reads the saved fingerprint and saves only that setting.
 *
 * @spec openspec/specs/edepot-transfer/spec.md
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import EdepotHostKeyConfiguration from './EdepotHostKeyConfiguration.vue'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), patch: jest.fn() },
}))
jest.mock('@nextcloud/dialogs', () => ({
	__esModule: true,
	showError: jest.fn(),
	showSuccess: jest.fn(),
}))
jest.mock('@nextcloud/router', () => ({ generateUrl: (url) => `/gen${url}` }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/ContentSave.vue', () => ({}))
jest.mock('../../../components/shared/SettingsSection.vue', () => ({}))

/**
 * A section instance over plain state.
 *
 * @param {object} state Its state
 * @return {object} The instance
 */
function section(state = {}) {
	const vm = { ...EdepotHostKeyConfiguration.data(), ...state }
	for (const [name, method] of Object.entries(EdepotHostKeyConfiguration.methods)) {
		vm[name] = method.bind(vm)
	}
	vm.t = (app, text) => text
	return vm
}

beforeEach(() => {
	jest.clearAllMocks()
	jest.spyOn(console, 'error').mockImplementation(() => {})
})

describe('EdepotHostKeyConfiguration', () => {
	it('shows the saved fingerprint', async () => {
		axios.get.mockResolvedValue({ data: { transport: 'sftp', hostKeyFingerprint: 'SHA256:abc' } })
		const vm = section()

		await vm.load()

		expect(axios.get).toHaveBeenCalledWith('/gen/apps/openregister/api/settings/edepot')
		expect(vm.fingerprint).toBe('SHA256:abc')
		expect(vm.loading).toBe(false)
	})

	it('shows an empty field when no fingerprint is pinned', async () => {
		axios.get.mockResolvedValue({ data: { transport: 'sftp' } })
		const vm = section({ fingerprint: 'stale' })

		await vm.load()

		expect(vm.fingerprint).toBe('')
	})

	it('saves only the trimmed fingerprint', async () => {
		axios.patch.mockResolvedValue({ data: { success: true } })
		const vm = section({ fingerprint: '  SHA256:abc \n' })

		await vm.save()

		expect(axios.patch).toHaveBeenCalledWith(
			'/gen/apps/openregister/api/settings/edepot',
			{ hostKeyFingerprint: 'SHA256:abc' },
		)
		expect(vm.fingerprint).toBe('SHA256:abc')
		expect(showSuccess).toHaveBeenCalled()
		expect(vm.saving).toBe(false)
	})

	it('reports a failed save and keeps what was typed', async () => {
		axios.patch.mockRejectedValue(new Error('offline'))
		const vm = section({ fingerprint: 'SHA256:abc' })

		await vm.save()

		expect(showError).toHaveBeenCalledWith('Could not save the e-Depot host key')
		expect(vm.fingerprint).toBe('SHA256:abc')
		expect(vm.saving).toBe(false)
	})
})
