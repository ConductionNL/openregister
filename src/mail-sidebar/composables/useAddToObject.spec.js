/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The "Add to case" action on Mail's attachments.
 *
 * @spec exclude Unit test of a client-side DOM decoration.
 */
import { decorateAttachment } from './useAddToObject.js'

const mockPayload = { fileName: 'photo.pdf' }
jest.mock('./useAttachmentDrag.js', () => ({
	attachmentPayloadOf: jest.fn(() => mockPayload),
}))

/**
 * An attachment element as Mail renders it: content, then the actions menu.
 *
 * @return {HTMLElement}
 */
function attachment() {
	const el = document.createElement('div')
	el.className = 'attachment'
	el.innerHTML = '<div class="mail-attached--content"></div><div class="action-item"></div>'
	return el
}

describe('decorateAttachment', () => {
	it('puts one labelled action before the menu, and updates it in place', () => {
		const el = attachment()
		const onAdd = jest.fn()
		decorateAttachment(el, 'Add to case', onAdd)
		decorateAttachment(el, 'Add to complaint', onAdd)
		const buttons = el.querySelectorAll('.or-mail-add-to-object')
		expect(buttons).toHaveLength(1)
		expect(buttons[0].textContent).toBe('Add to complaint')
		expect(buttons[0].nextElementSibling.className).toBe('action-item')
		expect(buttons[0].hidden).toBe(false)
	})

	it('hides the action while the mail is connected to nothing', () => {
		const el = attachment()
		decorateAttachment(el, '', jest.fn())
		expect(el.querySelector('.or-mail-add-to-object').hidden).toBe(true)
	})

	it('hands the attachment on when clicked, without opening it', () => {
		const el = attachment()
		const opened = jest.fn()
		el.addEventListener('click', opened)
		const onAdd = jest.fn()
		decorateAttachment(el, 'Add to case', onAdd)
		el.querySelector('.or-mail-add-to-object').click()
		expect(onAdd).toHaveBeenCalledWith(mockPayload)
		expect(opened).not.toHaveBeenCalled()
	})
})
