/**
 * EntityChangesList: the edit history of a schema or register, read from its
 * `changes` endpoint, with who, when, the cause and each changed path with its
 * old and new value (history-schema-and-settings-edits-audited, task 3.1).
 *
 * @spec openspec/changes/history-schema-and-settings-edits-audited/tasks.md#task-3-1
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import EntityChangesList from './EntityChangesList.vue'

jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
jest.mock('@nextcloud/router', () => ({
	generateUrl: (url, params = {}) => Object.entries(params).reduce((acc, [key, value]) => acc.replace(`{${key}}`, value), url),
}))
jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn() } }))
jest.mock('@nextcloud/vue', () => ({ NcLoadingIcon: { name: 'NcLoadingIcon', render: () => null } }))
jest.mock('vue', () => ({ ...jest.requireActual('vue'), NcLoadingIcon: { name: 'NcLoadingIcon', render: () => null } }))

const axios = jest.requireMock('@nextcloud/axios').default

const ROWS = [
	{
		uuid: 'r3',
		action: 'schema.updated',
		user: 'beheerder',
		userName: 'Beheerder',
		created: '2026-11-14T10:00:00+00:00',
		cause: 'person',
		changed: { fields: [{ path: 'properties.name.maxLength', old: 255, new: 100 }, { path: 'authorization.read', old: null, new: ['team'] }] },
	},
	{ uuid: 'r2', action: 'schema.updated', user: 'system', userName: 'System', created: '2026-11-13T10:00:00+00:00', cause: 'import', changed: { fields: [{ path: 'title', old: 'Melding', new: 'Meldingen' }] } },
	{ uuid: 'r1', action: 'schema.created', user: 'beheerder', userName: '', created: '2026-11-12T10:00:00+00:00', cause: null, changed: {} },
]

/**
 * Mount the list for schema 12.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountList() {
	const wrapper = mount(EntityChangesList, { props: { kind: 'schemas', entityId: 12 }, propsData: { kind: 'schemas', entityId: 12 } })
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	jest.clearAllMocks()
})

describe('EntityChangesList', () => {
	it('reads the first page of the schema\'s changes', async () => {
		axios.get.mockResolvedValue({ data: { results: ROWS, page: 1, limit: 20, hasMore: false } })
		await mountList()

		expect(axios.get).toHaveBeenCalledWith('/apps/openregister/api/schemas/12/changes', { params: { _page: 1, _limit: 20 } })
	})

	it('lists who, the cause and what changed for each of the three rows', async () => {
		axios.get.mockResolvedValue({ data: { results: ROWS, page: 1, limit: 20, hasMore: false } })
		const wrapper = await mountList()

		const rows = wrapper.findAll('li.entityChanges__row')
		expect(rows).toHaveLength(3)
		expect(rows.map((row) => row.find('.entityChanges__who').text())).toEqual(['Beheerder', 'System', 'beheerder'])
		expect(rows[0].find('.entityChanges__cause').text()).toBe('person')
		expect(rows[2].find('.entityChanges__cause').exists()).toBe(false)
		expect(rows.map((row) => row.find('.entityChanges__action').text())).toEqual(['Updated', 'Updated', 'Created'])

		const cells = rows[0].findAll('tbody tr').map((tr) => tr.findAll('td').map((td) => td.text()))
		expect(cells).toEqual([
			['properties.name.maxLength', '255', '100'],
			['authorization.read', '—', '["team"]'],
		])
		expect(rows[2].find('table').exists()).toBe(false)
		expect(wrapper.find('.entityChanges__more').exists()).toBe(false)
	})

	it('appends the next page on request', async () => {
		axios.get.mockResolvedValueOnce({ data: { results: [ROWS[0]], page: 1, limit: 20, hasMore: true } })
		axios.get.mockResolvedValueOnce({ data: { results: [ROWS[1]], page: 2, limit: 20, hasMore: false } })
		const wrapper = await mountList()

		await wrapper.find('.entityChanges__more').trigger('click')
		await flushPromises()

		expect(axios.get).toHaveBeenLastCalledWith('/apps/openregister/api/schemas/12/changes', { params: { _page: 2, _limit: 20 } })
		expect(wrapper.findAll('li.entityChanges__row')).toHaveLength(2)
		expect(wrapper.find('.entityChanges__more').exists()).toBe(false)
	})

	it('says so when nothing is recorded, and when the history cannot be read', async () => {
		axios.get.mockResolvedValue({ data: { results: [], hasMore: false } })
		expect((await mountList()).find('.entityChanges__empty').text()).toBe('No changes recorded')

		axios.get.mockRejectedValue(new Error('403'))
		const failed = await mountList()
		expect(failed.find('.entityChanges__error').text()).toBe('Error loading audit trails')
		expect(failed.findAll('li.entityChanges__row')).toHaveLength(0)
	})
})
