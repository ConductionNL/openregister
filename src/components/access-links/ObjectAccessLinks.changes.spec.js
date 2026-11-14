/**
 * Switching an access link off and revoking it: both keep the panel busy,
 * reload the links afterwards, and say so when the server refuses.
 *
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { showError } from '@nextcloud/dialogs'
import SubjectAccessLinks from './SubjectAccessLinks.vue'
import {
	revokeAccessLink,
	setAccessLinkDisabled,
} from '../../services/accessLinks.js'

jest.mock('@nextcloud/vue', () => ({ __esModule: true }))
jest.mock('@nextcloud/dialogs', () => ({
	showError: jest.fn(),
	showSuccess: jest.fn(),
}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	translatePlural: (app, one) => one,
}))
jest.mock('../../services/accessLinks.js', () => ({
	CAPABILITIES: [],
	linksForSubject: jest.fn(),
	linkState: jest.fn(),
	listAccessLinks: jest.fn(),
	mintAccessLink: jest.fn(),
	mintPayload: jest.fn(),
	revokeAccessLink: jest.fn(),
	setAccessLinkDisabled: jest.fn(),
}))

/**
 * A panel instance whose `load` records whether it ran while busy.
 *
 * @param {object} state Extra state
 * @return {object} The instance
 */
function panel(state = {}) {
	const ctx = { busy: false, created: null, loads: [], ...state }
	for (const [name, method] of Object.entries(SubjectAccessLinks.methods)) {
		ctx[name] = method.bind(ctx)
	}
	ctx.load = jest.fn(async () => ctx.loads.push(ctx.busy))
	return ctx
}

describe('ObjectAccessLinks link changes', () => {
	beforeEach(() => {
		jest.clearAllMocks()
	})

	it('switches a link off, then reloads while still busy', async () => {
		const ctx = panel()
		await ctx.toggle({ id: 4 }, true)
		expect(setAccessLinkDisabled).toHaveBeenCalledWith(4, true)
		expect(ctx.loads).toEqual([true])
		expect(ctx.busy).toBe(false)
		expect(showError).not.toHaveBeenCalled()
	})

	it('revokes a link and forgets it when it was just created', async () => {
		const ctx = panel({ created: { id: 4 } })
		await ctx.revoke({ id: 4 })
		expect(revokeAccessLink).toHaveBeenCalledWith(4)
		expect(ctx.created).toBeNull()
		expect(ctx.loads).toEqual([true])
	})

	it('keeps another freshly created link when revoking', async () => {
		const ctx = panel({ created: { id: 5 } })
		await ctx.revoke({ id: 4 })
		expect(ctx.created).toEqual({ id: 5 })
	})

	it('says so and stops being busy when the server refuses', async () => {
		setAccessLinkDisabled.mockRejectedValueOnce(new Error('no'))
		revokeAccessLink.mockRejectedValueOnce(new Error('no'))
		const ctx = panel({ created: { id: 4 } })
		await ctx.toggle({ id: 4 }, false)
		await ctx.revoke({ id: 4 })
		expect(showError).toHaveBeenCalledTimes(2)
		expect(showError).toHaveBeenCalledWith('That did not work. Try again later.')
		expect(ctx.loads).toEqual([])
		expect(ctx.created).toEqual({ id: 4 })
		expect(ctx.busy).toBe(false)
	})
})
