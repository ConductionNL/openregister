/**
 * The export keys section shows a new key's recovery string once, and never after a reload.
 *
 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-export-keys-are-kept-in-the-credential-broker
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import ExportKeysConfiguration from './ExportKeysConfiguration.vue'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), post: jest.fn(), delete: jest.fn() },
}))
jest.mock('@nextcloud/dialogs', () => ({
	__esModule: true,
	showError: jest.fn(),
}))
jest.mock('@nextcloud/router', () => ({ generateUrl: (url) => `/gen${url}` }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/KeyPlus.vue', () => ({}))
jest.mock('../../../components/shared/SettingsSection.vue', () => ({}))

const LIST = '/gen/apps/openregister/api/export-keys'

/**
 * A section instance over plain state.
 *
 * @param {object} state Its state
 * @return {object} The instance
 */
function section(state = {}) {
	const vm = { ...ExportKeysConfiguration.data(), ...state }
	for (const [name, method] of Object.entries(ExportKeysConfiguration.methods)) {
		vm[name] = method.bind(vm)
	}
	vm.t = (app, text) => text
	return vm
}

const listed = { data: { results: [{ id: 'k1', fingerprint: '0123456789abcdef', createdAt: '2026-01-01T10:00:00+00:00' }], organisation: 'org-a' } }

beforeEach(() => {
	jest.clearAllMocks()
	jest.spyOn(console, 'error').mockImplementation(() => {})
})

describe('ExportKeysConfiguration', () => {
	it('lists the keys by fingerprint and creation date', async () => {
		axios.get.mockResolvedValue(listed)
		const vm = section()

		await vm.load()

		expect(axios.get).toHaveBeenCalledWith(LIST)
		expect(vm.keys).toEqual([{ id: 'k1', fingerprint: '0123456789abcdef', createdAt: '2026-01-01T10:00:00+00:00' }])
		expect(vm.loading).toBe(false)
	})

	it('keeps nothing but id, fingerprint and date from a listed row', async () => {
		axios.get.mockResolvedValue({ data: { results: [{ id: 'k1', fingerprint: 'f', createdAt: 'd', recovery: 'LEAK' }] } })
		const vm = section()

		await vm.load()

		expect(vm.keys).toEqual([{ id: 'k1', fingerprint: 'f', createdAt: 'd' }])
	})

	it('shows the recovery string once after a create', async () => {
		axios.post.mockResolvedValue({ data: { id: 'k2', fingerprint: 'fedcba9876543210', recovery: 'RECOVERY' } })
		axios.get.mockResolvedValue(listed)
		const vm = section()

		await vm.create()

		expect(axios.post).toHaveBeenCalledWith(LIST)
		expect(vm.recovery).toEqual({ id: 'k2', fingerprint: 'fedcba9876543210', recovery: 'RECOVERY' })
		expect(axios.get).toHaveBeenCalledWith(LIST)
		expect(vm.busy).toBe(false)
	})

	it('never shows the recovery string again: dismissed, then a reload', async () => {
		axios.post.mockResolvedValue({ data: { id: 'k2', fingerprint: 'f', recovery: 'RECOVERY' } })
		axios.get.mockResolvedValue(listed)
		const vm = section()
		await vm.create()

		vm.dismissRecovery()
		await vm.load()

		expect(vm.recovery).toBeNull()
		expect(JSON.stringify(vm.keys)).not.toContain('RECOVERY')
	})

	it('a fresh section after a reload starts without a recovery string', async () => {
		axios.get.mockResolvedValue(listed)
		const vm = section()

		await vm.load()

		expect(vm.recovery).toBeNull()
	})

	it('rotates through the key\'s rotate route and shows the new recovery string', async () => {
		axios.post.mockResolvedValue({ data: { id: 'k2', fingerprint: 'f2', recovery: 'NEW', previousId: 'k1' } })
		axios.get.mockResolvedValue(listed)
		const vm = section()

		await vm.rotate({ id: 'k1' })

		expect(axios.post).toHaveBeenCalledWith(`${LIST}/k1/rotate`)
		expect(vm.recovery.recovery).toBe('NEW')
	})

	it('deletes only after the administrator confirms', async () => {
		axios.delete.mockResolvedValue({ data: { id: 'k1', removed: true } })
		axios.get.mockResolvedValue({ data: { results: [] } })
		const vm = section()

		window.confirm = jest.fn(() => false)
		await vm.remove({ id: 'k1' })
		expect(axios.delete).not.toHaveBeenCalled()

		window.confirm = jest.fn(() => true)
		await vm.remove({ id: 'k1' })
		expect(axios.delete).toHaveBeenCalledWith(`${LIST}/k1`)
		expect(vm.keys).toEqual([])
		expect(vm.recovery).toBeNull()
	})

	it('a refused action says so and shows no recovery string', async () => {
		axios.post.mockRejectedValue(new Error('403'))
		axios.get.mockResolvedValue(listed)
		const vm = section()

		await vm.create()

		expect(showError).toHaveBeenCalledWith('The export key action failed')
		expect(vm.recovery).toBeNull()
		expect(vm.busy).toBe(false)
	})

	it('a failed load says so', async () => {
		axios.get.mockRejectedValue(new Error('500'))
		const vm = section()

		await vm.load()

		expect(showError).toHaveBeenCalledWith('Could not load the export keys')
		expect(vm.loading).toBe(false)
	})

	it('an unparseable or missing date reads Unknown', () => {
		const vm = section()

		expect(vm.formatDate(null)).toBe('Unknown')
		expect(vm.formatDate('not a date')).toBe('Unknown')
		expect(vm.formatDate('2026-01-01T10:00:00+00:00')).not.toBe('Unknown')
	})
})
