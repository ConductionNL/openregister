/**
 * The document store block shows where managed files live, as the file
 * settings report it, and says so when the store could not be read.
 *
 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import DocumentStoreStatus from './DocumentStoreStatus.vue'

jest.mock('pinia', () => ({ mapStores: () => ({}) }))
jest.mock('../../../store/settings.js', () => ({ useSettingsStore: {} }))

/**
 * The block's computed values over a given store answer.
 *
 * @param {object|null|undefined} documentStore What the file settings returned
 * @return {object} The computed values
 */
function block(documentStore) {
	const vm = {
		settingsStore: { fileOptions: { documentStore } },
		t: (app, text) => text,
	}
	for (const [name, getter] of Object.entries(DocumentStoreStatus.computed)) {
		Object.defineProperty(vm, name, { get: getter.bind(vm), configurable: true })
	}
	return vm
}

const PRIMARY = {
	root: 'Open Registers',
	storageId: 'home::openregister',
	backend: 'local',
	backendName: 'local',
	mountPoint: '/openregister/',
	separate: false,
	freeBytes: null,
}

describe('DocumentStoreStatus', () => {
	it('shows the default root on the primary storage as not separate', () => {
		const vm = block(PRIMARY)

		expect(vm.store.root).toBe('Open Registers')
		expect(vm.backend).toBe('local (local)')
		expect(vm.separate).toBe('No, on the primary storage')
	})

	it('names an external mount as separate, as occ prints it', () => {
		const vm = block({
			...PRIMARY,
			root: 'Documents',
			backend: 'external',
			separate: true,
		})

		expect(vm.backend).toBe('external (local)')
		expect(vm.separate).toBe('Yes, apart from the primary storage')
	})

	it('shows the bare backend when no storage class is named', () => {
		const vm = block({ ...PRIMARY, backend: 'object-storage', backendName: '' })

		expect(vm.backend).toBe('object-storage')
	})

	it('says free space is not reported when the storage does not know it', () => {
		expect(block(PRIMARY).freeSpace).toBe('Not reported')
		expect(block({ ...PRIMARY, freeBytes: -2 }).freeSpace).toBe('Not reported')
	})

	it('shows free space in the largest whole unit', () => {
		expect(block({ ...PRIMARY, freeBytes: 512 }).freeSpace).toBe('512 B')
		expect(block({ ...PRIMARY, freeBytes: 1536 }).freeSpace).toBe('1.5 KB')
		expect(block({ ...PRIMARY, freeBytes: 5 * 1024 ** 3 }).freeSpace).toBe(
			'5.0 GB',
		)
	})

	it('has no store when the server could not read it', () => {
		expect(block(null).store).toBeNull()
		expect(block(undefined).store).toBeNull()
		expect(block('broken').store).toBeNull()
	})
})
