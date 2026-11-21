/**
 * The settings store's warning getter, the loadSettings re-entry guard and
 * the confirmation dialog toggles.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { createPinia, setActivePinia } from 'pinia'
import { useSettingsStore } from './settings.js'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), post: jest.fn(), put: jest.fn(), patch: jest.fn(), delete: jest.fn() },
}))
jest.mock('@nextcloud/dialogs', () => ({
	__esModule: true,
	showError: jest.fn(),
	showSuccess: jest.fn(),
}))
jest.mock('@nextcloud/l10n', () => ({
	__esModule: true,
	translate: jest.fn((app, text) => text),
}))
jest.mock('@nextcloud/router', () => ({
	__esModule: true,
	generateUrl: jest.fn((path) => `url:${path}`),
}))

describe('settings store dialogs', () => {
	beforeEach(() => setActivePinia(createPinia()))

	it('hasWarnings is true only when a warning count is above zero', () => {
		const store = useSettingsStore()
		store.stats.warnings = { a: 0, b: 0 }
		expect(store.hasWarnings).toBe(false)
		store.stats.warnings = { a: 0, b: 2 }
		expect(store.hasWarnings).toBe(true)
	})

	it('loadSettings does nothing while a load is already in progress', async () => {
		const store = useSettingsStore()
		store.loadRbacSettings = jest.fn()
		store.loading = true
		store.loadingInProgress = true
		await store.loadSettings()
		expect(store.loadRbacSettings).not.toHaveBeenCalled()
	})

	it('confirmMassValidate hides the dialog, clears results and runs the validation', async () => {
		const store = useSettingsStore()
		store.massValidate = jest.fn(async () => 'ran')
		store.showMassValidateDialog()
		expect(store.showMassValidateConfirmation).toBe(true)
		store.massValidateResults = { old: true }
		expect(await store.confirmMassValidate({ batch: 5 })).toBe('ran')
		expect(store.massValidate).toHaveBeenCalledWith({ batch: 5 })
		expect(store.showMassValidateConfirmation).toBe(false)
		expect(store.massValidateResults).toBeNull()
	})

	it.each([
		['showRebaseDialog', 'showRebaseConfirmation'],
		['showClearAuditTrailsDialog', 'showClearAuditTrailsConfirmation'],
		['showClearSearchTrailsDialog', 'showClearSearchTrailsConfirmation'],
		['showClearBlobObjectsDialog', 'showClearBlobObjectsConfirmation'],
		['showClearCacheDialog', 'showClearCacheConfirmation'],
	])('%s opens its confirmation', (action, flag) => {
		const store = useSettingsStore()
		store[flag] = false
		store[action]()
		expect(store[flag]).toBe(true)
	})
})
