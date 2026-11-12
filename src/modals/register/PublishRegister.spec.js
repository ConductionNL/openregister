/**
 * The publish-register modal: it hands the selected register, its default
 * file path and the register texts to the shared GitHub publish form, and
 * refreshes the register list after a publish.
 *
 * @spec exclude Client-side publish-register modal.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import PublishRegister from './PublishRegister.vue'

jest.mock('@nextcloud/l10n', () => ({ translate: (app, s) => `[${app}] ${s}` }))
jest.mock('../github/PublishToGithub.vue', () => ({}))
jest.mock('../../store/store.js', () => ({
	registerStore: { registerItem: null, refreshRegisterList: jest.fn() },
}))

const { registerStore } = jest.requireMock('../../store/store.js')
const { computed, methods } = PublishRegister

beforeEach(() => {
	jest.clearAllMocks()
	registerStore.registerItem = null
})

describe('PublishRegister', () => {
	it('exposes the selected register', () => {
		registerStore.registerItem = { id: 3, slug: 'personen' }
		expect(computed.register.call({})).toBe(registerStore.registerItem)
	})

	it.each([
		[{ slug: 'personen' }, 'personen_openregister.json'],
		[{ slug: '' }, ''],
		[{}, ''],
		[null, ''],
	])('derives the default file path of %j', (register, path) => {
		expect(computed.defaultFilePath.call({ register })).toBe(path)
	})

	it('passes the register texts, translating the visible ones', () => {
		expect(computed.copy.call({})).toEqual({
			title: '[openregister] Publish Register OAS to GitHub',
			entityHeading: '[openregister] Register',
			filePathPlaceholder: '[openregister] e.g., lib/settings/register.json',
			filePathHint: '[openregister] Path where the register OAS file will be saved in the repository',
			commitPlaceholder: '[openregister] Update register OAS: ...',
			commitPrefix: 'Update register OAS: ',
			publishedMessage: 'Register OAS published successfully!',
			failureMessage: 'Failed to publish register OAS',
		})
	})

	it('refreshes the register list and returns its promise', () => {
		const done = Promise.resolve('ok')
		registerStore.refreshRegisterList.mockReturnValue(done)
		expect(methods.refresh.call({})).toBe(done)
		expect(registerStore.refreshRegisterList).toHaveBeenCalledTimes(1)
	})
})
