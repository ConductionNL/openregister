/**
 * The settings store's read entry visibility actions: read the stored groups
 * with the groups to pick from, and store a new list under `audit`.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 * @spec openspec/changes/audit-read-events-visible-to/specs/audit-trail-immutable/spec.md#requirement-read-entries-are-shown-only-to-the-groups-named-for-them
 */
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { createPinia, setActivePinia } from 'pinia'
import { useSettingsStore } from './settings.js'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), post: jest.fn(), put: jest.fn() },
}))
jest.mock('@nextcloud/dialogs', () => ({
	__esModule: true,
	showError: jest.fn(),
	showSuccess: jest.fn(),
}))
jest.mock('@nextcloud/l10n', () => ({
	__esModule: true,
	translate: jest.fn((app, text, vars) => `[${text}]${vars ? JSON.stringify(vars) : ''}`),
}))
jest.mock('@nextcloud/router', () => ({
	__esModule: true,
	generateUrl: jest.fn((path) => `url:${path}`),
}))

describe('settings store: read entry visibility', () => {
	let store
	beforeEach(() => {
		jest.clearAllMocks()
		jest.spyOn(console, 'error').mockImplementation(() => {})
		setActivePinia(createPinia())
		store = useSettingsStore()
	})

	it('reads the stored groups and the groups to pick from', async () => {
		axios.get.mockResolvedValue({ data: { audit: { readEventsVisibleTo: ['jeugd'] }, availableGroups: { jeugd: 'Jeugdteam', public: 'Public' } } })

		const loaded = await store.loadAuditSettings()

		expect(axios.get).toHaveBeenCalledWith('url:/apps/openregister/api/settings')
		expect(loaded).toEqual({
			readEventsVisibleTo: ['jeugd'],
			groups: [{ id: 'jeugd', label: 'Jeugdteam' }, { id: 'public', label: 'Public' }],
		})
	})

	it('reads an answer without the setting as no group', async () => {
		axios.get.mockResolvedValue({ data: {} })

		expect(await store.loadAuditSettings()).toEqual({ readEventsVisibleTo: [], groups: [] })
	})

	it('stores the list under audit and says so', async () => {
		axios.put.mockResolvedValue({ data: { audit: { readEventsVisibleTo: ['jeugd'] } } })

		const answer = await store.updateAuditSettings({ readEventsVisibleTo: ['jeugd'] })

		expect(axios.put).toHaveBeenCalledWith('url:/apps/openregister/api/settings', { audit: { readEventsVisibleTo: ['jeugd'] } })
		expect(answer).toEqual({ audit: { readEventsVisibleTo: ['jeugd'] } })
		expect(showSuccess).toHaveBeenCalledWith('[Read entry visibility updated]')
	})

	it('shows why a save failed', async () => {
		axios.put.mockRejectedValue(new Error('403 Forbidden'))

		await expect(store.updateAuditSettings({ readEventsVisibleTo: [] })).rejects.toThrow('403 Forbidden')
		expect(showError).toHaveBeenCalledWith('[Failed to update the read entry setting: {error}]{"error":"403 Forbidden"}')
	})
})
