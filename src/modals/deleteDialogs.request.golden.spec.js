/**
 * Golden record of the request handling of DeleteConfiguration and
 * DeleteOrganisation: the dialog state after a success, after a refusal with
 * a message and after a refusal without one (the fallback text).
 *
 * @spec exclude Golden record of client-side dialog request handling.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import DeleteConfiguration from './configuration/DeleteConfiguration.vue'
import DeleteOrganisation from './organisation/DeleteOrganisation.vue'

jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
jest.mock('@nextcloud/vue', () => ({ NcNoteCard: {} }))
jest.mock('vue-material-design-icons/Delete.vue', () => ({ default: {} }))
jest.mock('./shared/ConfirmDialogFrame.vue', () => ({ default: {} }))
jest.mock('../store/store.js', () => ({
	configurationStore: { configurationItem: { id: 1 } },
	navigationStore: { setDialog: jest.fn(), setModal: jest.fn() },
	organisationStore: { organisationItem: { uuid: 'a', name: 'O' } },
}))

const stores = jest.requireMock('../store/store.js')

/**
 * Runs a dialog method on a plain dialog state and records what it does.
 *
 * @param {object} component The dialog component
 * @param {string} method The method to run
 * @return {Promise<object>} The state and calls after the run
 */
async function run(component, method) {
	const calls = []
	const dialog = {
		loading: false,
		error: null,
		success: false,
		closeModalTimeout: null,
		$router: { push: (to) => calls.push(['router.push', to]) },
	}
	const seen = []
	const watched = new Proxy(dialog, {
		set(target, key, value) {
			if (['loading', 'error', 'success'].includes(key)) seen.push([key, value])
			target[key] = value
			return true
		},
	})
	for (const name of Object.keys(component.methods)) {
		const fn = component.methods[name]
		watched[name] = (...args) => {
			if (name !== method) calls.push([name])
			return fn.apply(watched, args)
		}
	}
	await watched[method]()
	clearTimeout(watched.closeModalTimeout)
	return {
		calls,
		seen,
		state: { loading: watched.loading, error: watched.error, success: watched.success },
	}
}

beforeEach(() => {
	jest.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	jest.restoreAllMocks()
})

describe('DeleteConfiguration request', () => {
	it('closes after a success', async () => {
		stores.configurationStore.deleteConfiguration = jest.fn().mockResolvedValue(undefined)
		expect(await run(DeleteConfiguration, 'deleteConfiguration')).toMatchSnapshot()
	})

	it('shows the error message of a refusal', async () => {
		stores.configurationStore.deleteConfiguration = jest.fn().mockRejectedValue(new Error('Nope'))
		expect(await run(DeleteConfiguration, 'deleteConfiguration')).toMatchSnapshot()
	})

	it('shows the fallback text for a refusal without a message', async () => {
		stores.configurationStore.deleteConfiguration = jest.fn().mockRejectedValue({})
		expect(await run(DeleteConfiguration, 'deleteConfiguration')).toMatchSnapshot()
	})
})

describe('DeleteOrganisation request', () => {
	it('navigates and schedules the close after a success', async () => {
		stores.organisationStore.deleteOrganisation = jest.fn().mockResolvedValue({ response: { ok: true } })
		expect(await run(DeleteOrganisation, 'deleteOrganisation')).toMatchSnapshot()
	})

	it('records a not-ok answer', async () => {
		stores.organisationStore.deleteOrganisation = jest.fn().mockResolvedValue({ response: { ok: false } })
		expect(await run(DeleteOrganisation, 'deleteOrganisation')).toMatchSnapshot()
	})

	it('logs and shows the error message of a refusal', async () => {
		stores.organisationStore.deleteOrganisation = jest.fn().mockRejectedValue(new Error('Nope'))
		const result = await run(DeleteOrganisation, 'deleteOrganisation')
		expect({ ...result, logged: console.error.mock.calls.length }).toMatchSnapshot()
	})

	it('shows the fallback text for a refusal without a message', async () => {
		stores.organisationStore.deleteOrganisation = jest.fn().mockRejectedValue({})
		expect(await run(DeleteOrganisation, 'deleteOrganisation')).toMatchSnapshot()
	})
})
