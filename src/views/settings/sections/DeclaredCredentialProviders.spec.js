/**
 * The declared credential providers section lists every declaration with its
 * host and rules, offers only the decisions its state allows, and reads the list
 * again after a decision.
 *
 * @spec openspec/changes/app-declared-credential-providers/specs/app-declared-credential-providers/spec.md#requirement-a-novel-declaration-is-unusable-until-an-administrator-approves-it
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import DeclaredCredentialProviders from './DeclaredCredentialProviders.vue'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), post: jest.fn() },
}))
jest.mock('@nextcloud/dialogs', () => ({
	__esModule: true,
	showError: jest.fn(),
}))
jest.mock('@nextcloud/router', () => ({
	generateUrl: (url, params = {}) => `/gen${url.replace(/\{(\w+)\}/g, (match, key) => params[key])}`,
}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('../../../components/shared/SettingsSection.vue', () => ({}))

const LIST_URL = '/gen/apps/openregister/api/credentials/providers/declared'

/**
 * A section instance over plain state.
 *
 * @param {object} state Its state
 * @return {object} The instance
 */
function section(state = {}) {
	const vm = { ...DeclaredCredentialProviders.data(), ...state }
	for (const [name, method] of Object.entries(DeclaredCredentialProviders.methods)) {
		vm[name] = method.bind(vm)
	}
	vm.t = (app, text) => text
	return vm
}

/**
 * One declaration as the review route returns it.
 *
 * @param {object} overrides Values to change
 * @return {object} The row
 */
function row(overrides = {}) {
	return {
		identifier: 'alpha:codeberg',
		title: 'Codeberg',
		origin: 'alpha',
		status: 'pending',
		lane: 'approval',
		autoAdmitted: false,
		host: 'codeberg.org',
		baseUrl: 'https://codeberg.org/api/v1',
		allowRules: [{ method: 'GET', pathPattern: '/repos/*' }],
		reason: '',
		...overrides,
	}
}

beforeEach(() => {
	jest.clearAllMocks()
	jest.spyOn(console, 'error').mockImplementation(() => {})
})

describe('DeclaredCredentialProviders', () => {
	it('reads the review list from the admin route', async () => {
		axios.get.mockResolvedValue({ data: { results: [row()] } })
		const vm = section()

		await vm.load()

		expect(axios.get).toHaveBeenCalledWith(LIST_URL)
		expect(vm.providers).toEqual([row()])
		expect(vm.loading).toBe(false)
	})

	it('shows an empty list and an error when the list cannot be read', async () => {
		axios.get.mockRejectedValue(new Error('403'))
		const vm = section({ providers: [row()] })

		await vm.load()

		expect(vm.providers).toEqual([])
		expect(showError).toHaveBeenCalledWith('The declared providers could not be loaded.')
		expect(vm.loading).toBe(false)
	})

	it('treats an answer without results as an empty list', async () => {
		axios.get.mockResolvedValue({ data: {} })
		const vm = section()

		await vm.load()

		expect(vm.providers).toEqual([])
	})

	it('offers only the decisions a state allows', () => {
		const vm = section()

		expect(vm.actionsFor(row({ status: 'pending' }))).toEqual(['approve', 'reject'])
		expect(vm.actionsFor(row({ status: 'rejected' }))).toEqual(['approve'])
		expect(vm.actionsFor(row({ status: 'revoked' }))).toEqual(['approve'])
		expect(vm.actionsFor(row({ status: 'admitted' }))).toEqual(['revoke'])
		expect(vm.actionsFor(row({ status: 'invalid' }))).toEqual([])
	})

	it('offers no decision on a declaration admitted by itself', () => {
		const vm = section()

		expect(vm.actionsFor(row({ status: 'admitted', lane: 'narrowing', autoAdmitted: true }))).toEqual([])
	})

	it('posts a decision to the approval route of that identifier and reads the list again', async () => {
		axios.post.mockResolvedValue({ data: { status: 'admitted' } })
		axios.get.mockResolvedValue({ data: { results: [row({ status: 'admitted' })] } })
		const vm = section({ providers: [row()] })

		await vm.decide(row(), 'approve')

		expect(axios.post).toHaveBeenCalledWith('/gen/apps/openregister/api/credentials/providers/alpha:codeberg/approve')
		expect(axios.get).toHaveBeenCalledWith(LIST_URL)
		expect(vm.providers[0].status).toBe('admitted')
		expect(vm.busy).toBe('')
	})

	it('says so when a decision fails and keeps the list as it was', async () => {
		axios.post.mockRejectedValue(new Error('409'))
		const vm = section({ providers: [row()] })

		await vm.decide(row(), 'reject')

		expect(showError).toHaveBeenCalledWith('The decision could not be saved.')
		expect(axios.get).not.toHaveBeenCalled()
		expect(vm.providers).toEqual([row()])
		expect(vm.busy).toBe('')
	})

	it('labels every state and decision', () => {
		const vm = section()

		expect(['admitted', 'pending', 'rejected', 'revoked', 'invalid'].map(vm.statusLabel))
			.toEqual(['Admitted', 'Pending', 'Rejected', 'Revoked', 'Invalid'])
		expect(vm.statusLabel('unknown')).toBe('unknown')
		expect(['approve', 'reject', 'revoke'].map(vm.actionLabel)).toEqual(['Approve', 'Reject', 'Revoke'])
	})
})
