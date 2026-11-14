/**
 * The shared-configuration trust section reads the trust configuration and the
 * instance public key, and saves only the settings that changed.
 *
 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import FederatedTrustConfiguration from './FederatedTrustConfiguration.vue'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), put: jest.fn() },
}))
jest.mock('@nextcloud/dialogs', () => ({
	__esModule: true,
	showError: jest.fn(),
	showSuccess: jest.fn(),
}))
jest.mock('@nextcloud/router', () => ({ generateUrl: (url) => `/gen${url}` }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/ContentCopy.vue', () => ({}))
jest.mock('vue-material-design-icons/ContentSave.vue', () => ({}))
jest.mock('../../../components/shared/SettingsSection.vue', () => ({}))

const TRUST_URL = '/gen/apps/openregister/api/federated-config/trust'
const KEY_URL = '/gen/apps/openregister/api/federated-config/public-key'

/**
 * A section instance over plain state.
 *
 * @param {object} state Its state
 * @return {object} The instance
 */
function section(state = {}) {
	const vm = { ...FederatedTrustConfiguration.data(), ...state }
	for (const [name, method] of Object.entries(FederatedTrustConfiguration.methods)) {
		vm[name] = method.bind(vm)
	}
	vm.t = (app, text) => text
	return vm
}

/**
 * The trust configuration as the server returns it.
 *
 * @param {object} overrides Values to change
 * @return {object} The server body
 */
function serverTrust(overrides = {}) {
	return {
		sourceAllowlist: 'ConductionNL',
		trustedKeys: 'a2V5MQ==',
		publishGroups: '',
		installGroups: 'admin,editors',
		allowUnverifiedBundles: '',
		...overrides,
	}
}

beforeEach(() => {
	jest.clearAllMocks()
	jest.spyOn(console, 'error').mockImplementation(() => {})
})

describe('FederatedTrustConfiguration', () => {
	it('shows the trust configuration and the public key', async () => {
		axios.get.mockImplementation((url) => Promise.resolve(
			url === TRUST_URL
				? { data: serverTrust({ allowUnverifiedBundles: 'true' }) }
				: { data: { publicKey: 'cHVi' } },
		))
		const vm = section()

		await vm.load()

		expect(axios.get).toHaveBeenCalledWith(TRUST_URL)
		expect(axios.get).toHaveBeenCalledWith(KEY_URL)
		expect(vm.trust).toEqual({
			sourceAllowlist: 'ConductionNL',
			trustedKeys: 'a2V5MQ==',
			publishGroups: '',
			installGroups: 'admin,editors',
			allowUnverifiedBundles: true,
		})
		expect(vm.publicKey).toBe('cHVi')
		expect(vm.loading).toBe(false)
	})

	it('reads only the stored "true" as allowing unsigned bundles', () => {
		const vm = section()

		vm.apply(serverTrust({ allowUnverifiedBundles: '1' }))
		expect(vm.trust.allowUnverifiedBundles).toBe(false)

		vm.apply(serverTrust({ allowUnverifiedBundles: 'true' }))
		expect(vm.trust.allowUnverifiedBundles).toBe(true)
	})

	it('tells the admin when the trust settings cannot be read', async () => {
		axios.get.mockRejectedValue(new Error('403'))
		const vm = section()

		await vm.load()

		expect(showError).toHaveBeenCalledWith('Could not load the trust settings')
		expect(vm.loading).toBe(false)
	})

	it('sends nothing when nothing changed', () => {
		const vm = section()
		vm.apply(serverTrust())

		expect(vm.changes()).toEqual([])
	})

	it('sends each changed setting with its list trimmed of blanks', () => {
		const vm = section()
		vm.apply(serverTrust())
		vm.trust.sourceAllowlist = ' ConductionNL , , acme/configs '
		vm.trust.allowUnverifiedBundles = true

		expect(vm.changes()).toEqual([
			{ field: 'sourceAllowlist', value: 'ConductionNL,acme/configs' },
			{ field: 'allowUnverifiedBundles', value: 'true' },
		])
	})

	it('turns the unsigned-bundle allowance off with an empty value', () => {
		const vm = section()
		vm.apply(serverTrust({ allowUnverifiedBundles: 'true' }))
		vm.trust.allowUnverifiedBundles = false

		expect(vm.changes()).toEqual([{ field: 'allowUnverifiedBundles', value: '' }])
	})

	it('saves the changes and shows what the server holds afterwards', async () => {
		axios.put.mockResolvedValue({ data: serverTrust({ trustedKeys: 'a2V5MQ==,a2V5Mg==' }) })
		const vm = section()
		vm.apply(serverTrust())
		vm.trust.trustedKeys = 'a2V5MQ==, a2V5Mg=='

		await vm.save()

		expect(axios.put).toHaveBeenCalledTimes(1)
		expect(axios.put).toHaveBeenCalledWith(TRUST_URL, { field: 'trustedKeys', value: 'a2V5MQ==,a2V5Mg==' })
		expect(vm.trust.trustedKeys).toBe('a2V5MQ==,a2V5Mg==')
		expect(vm.changes()).toEqual([])
		expect(showSuccess).toHaveBeenCalledWith('Trust settings saved')
		expect(vm.saving).toBe(false)
	})

	it('stops and says so when a setting is refused', async () => {
		axios.put.mockRejectedValue(new Error('403'))
		const vm = section()
		vm.apply(serverTrust())
		vm.trust.publishGroups = 'editors'
		vm.trust.installGroups = 'editors'

		await vm.save()

		expect(axios.put).toHaveBeenCalledTimes(1)
		expect(showError).toHaveBeenCalledWith('Could not save the trust settings')
		expect(showSuccess).not.toHaveBeenCalled()
		expect(vm.saving).toBe(false)
	})

	it('copies the public key', async () => {
		const writeText = jest.fn().mockResolvedValue()
		Object.defineProperty(global.navigator, 'clipboard', { value: { writeText }, configurable: true })
		const vm = section({ publicKey: 'cHVi' })

		await vm.copyPublicKey()

		expect(writeText).toHaveBeenCalledWith('cHVi')
		expect(showSuccess).toHaveBeenCalledWith('Copied')
	})
})
