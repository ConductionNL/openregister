/**
 * Golden record of loadNextcloudGroups on the organisation and application
 * stores: the request, the groups it caches and what it logs, for a good
 * answer, an answer without groups, a refusal, an unreadable body and a
 * network error.
 *
 * @spec exclude Golden record of a thin passthrough to the Nextcloud OCS groups API.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { createPinia, setActivePinia } from 'pinia'
import { useApplicationStore } from './application.js'
import { useOrganisationStore } from './organisation.js'

const STORES = [
	['organisation', useOrganisationStore],
	['application', useApplicationStore],
]

const ANSWERS = [
	[
		'groups',
		{ ok: true, body: { ocs: { data: { groups: ['admin', 'staff'] } } } },
	],
	['no groups', { ok: true, body: { ocs: { data: { groups: [] } } } }],
	['groups missing', { ok: true, body: { ocs: { data: {} } } }],
	['no ocs', { ok: true, body: {} }],
	[
		'refused',
		{ ok: false, status: 403, body: { ocs: { data: { groups: ['x'] } } } },
	],
	['unreadable', { ok: true, badJson: true }],
	['network', { network: true }],
]

describe('Nextcloud groups golden', () => {
	for (const [storeName, useStore] of STORES) {
		for (const [answerName, answer] of ANSWERS) {
			it(`${storeName}: ${answerName}`, async () => {
				setActivePinia(createPinia())
				const store = useStore()
				store.nextcloudGroups = [{ id: 'cached' }]
				global.fetch = jest.fn(async () => {
					if (answer.network) {
						throw new TypeError('Failed to fetch')
					}
					return {
						ok: answer.ok,
						status: answer.status ?? 200,
						json: async () => {
							if (answer.badJson) {
								throw new SyntaxError('Unexpected token')
							}
							return answer.body
						},
					}
				})
				const logged = []
				jest.spyOn(console, 'error').mockImplementation((text, error) =>
					logged.push([text, error?.message]),
				)
				const returned = await store.loadNextcloudGroups()
				console.error.mockRestore()
				expect({
					calls: global.fetch.mock.calls,
					returned,
					groups: store.nextcloudGroups,
					logged,
				}).toMatchSnapshot()
			})
		}
	}
})
