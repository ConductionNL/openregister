import { createPinia, setActivePinia } from 'pinia'
import { useOrganisationStore } from './organisation.js'

/**
 * Golden record of the organisation store's membership and save actions:
 * the request each sends, what it reloads, what it returns on an allowed
 * answer, and the error it throws and logs for a refusal (with and without
 * the server's message) and for a network failure.
 */

jest.mock('../../services/groupSearch.js', () => ({ loadNextcloudGroupsInto: jest.fn() }))

const ACTIONS = {
	setActiveOrganisationById: (store) => store.setActiveOrganisationById('org-1'),
	joinOrganisation: (store) => store.joinOrganisation('org-1'),
	joinOrganisationForUser: (store) => store.joinOrganisation('org-1', 'alice'),
	leaveOrganisation: (store) => store.leaveOrganisation('org-1'),
	createOrganisation: (store) => store.createOrganisation({ name: 'New', slug: 'new' }),
	updateOrganisation: (store) => store.updateOrganisation({ uuid: 'org-1', id: 7, name: 'Renamed', slug: '', active: '', users: ['u'] }),
	searchOrganisations: (store) => store.searchOrganisations('  acme  ', 10, 20),
	searchAllOrganisations: (store) => store.searchOrganisations(),
}

const ANSWERS = {
	allowed: () => ({
		ok: true,
		status: 200,
		json: () => Promise.resolve({
			activeOrganisation: { uuid: 'org-1', name: 'Active' },
			organisation: { uuid: 'org-1', name: 'Saved' },
			organisations: [{ uuid: 'org-2' }],
		}),
	}),
	allowedBare: () => ({ ok: true, status: 200, json: () => Promise.resolve({ name: 'Bare' }) }),
	refusedWithMessage: () => ({ ok: false, status: 403, json: () => Promise.resolve({ message: 'Not a member' }) }),
	refusedBare: () => ({ ok: false, status: 500, json: () => Promise.resolve({}) }),
}

/**
 * Run one action against one answer, recording requests, reloads, logs and
 * the outcome.
 *
 * @param {string} action The action
 * @param {Function} answer The fetch answer, or a rejection for a network failure
 * @return {Promise<object>} What happened
 */
async function run(action, answer) {
	setActivePinia(createPinia())
	const store = useOrganisationStore()
	store.organisationItem = false
	const log = []
	store.refreshOrganisationList = async () => log.push(['refreshOrganisationList'])
	store.getActiveOrganisation = async () => log.push(['getActiveOrganisation'])
	global.fetch = jest.fn(async (...args) => {
		log.push(['fetch', ...args])
		return answer()
	})
	const logged = jest.spyOn(console, 'error').mockImplementation(() => {})
	let outcome
	try {
		const result = await ACTIONS[action](store)
		outcome = { result: JSON.parse(JSON.stringify(result ?? null)) }
	} catch (e) {
		outcome = { thrown: e.message, cause: e.cause?.message }
	}
	const result = {
		log,
		outcome,
		logged: logged.mock.calls.map(([text, e]) => [text, e?.message]),
		activeOrganisation: store.activeOrganisation ? { ...store.activeOrganisation } : null,
		organisationItem: store.organisationItem ? { name: store.organisationItem.name } : store.organisationItem,
	}
	logged.mockRestore()
	return result
}

describe.each(Object.keys(ACTIONS))('organisation.%s', (action) => {
	it.each(Object.keys(ANSWERS))('on an %s answer', async (answer) => {
		expect(await run(action, ANSWERS[answer])).toMatchSnapshot()
	})

	it('on a network failure', async () => {
		expect(await run(action, () => {
			throw new Error('offline')
		})).toMatchSnapshot()
	})
})
