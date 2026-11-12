/**
 * settleConfirmRequest shows a confirm dialog's outcome the way the delete
 * dialogs' own promise chains did.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec exclude Unit test of client-side dialog plumbing.
 */
import { settleConfirmRequest } from './confirmDialogState.js'

jest.mock('../../store/store.js', () => ({
	navigationStore: { setDialog: jest.fn() },
}))

/**
 * A dialog mid-request, as the delete dialogs leave it.
 *
 * @return {object} The dialog state
 */
function makeDialog() {
	return {
		success: false,
		error: 'old',
		loading: true,
		closeModalTimeout: null,
		closeDialog: jest.fn(),
	}
}

describe('settleConfirmRequest', () => {
	beforeEach(() => jest.useFakeTimers())
	afterEach(() => jest.useRealTimers())

	it('marks success and closes after two seconds', async () => {
		const dialog = makeDialog()
		await settleConfirmRequest(
			dialog,
			Promise.resolve({ response: { ok: true } }),
			'Failed',
		)
		expect(dialog).toMatchObject({ success: true, error: false, loading: false })
		expect(dialog.closeModalTimeout).not.toBeNull()
		jest.advanceTimersByTime(1999)
		expect(dialog.closeDialog).not.toHaveBeenCalled()
		jest.advanceTimersByTime(1)
		expect(dialog.closeDialog).toHaveBeenCalledTimes(1)
	})

	it('stays open on a refused response', async () => {
		const dialog = makeDialog()
		await settleConfirmRequest(
			dialog,
			Promise.resolve({ response: { ok: false } }),
			'Failed',
		)
		expect(dialog).toMatchObject({ success: false, error: false, loading: false })
		expect(dialog.closeModalTimeout).toBeNull()
		jest.runAllTimers()
		expect(dialog.closeDialog).not.toHaveBeenCalled()
	})

	it("shows the error's message, or the fallback without one", async () => {
		const dialog = makeDialog()
		await settleConfirmRequest(dialog, Promise.reject(new Error('Gone')), 'Failed')
		expect(dialog).toMatchObject({ success: false, error: 'Gone', loading: false })
		await settleConfirmRequest(dialog, Promise.reject(new Error('')), 'Failed')
		expect(dialog.error).toBe('Failed')
	})
})
