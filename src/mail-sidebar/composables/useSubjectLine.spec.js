/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The line under the subject in Mail's reading pane.
 *
 * @spec exclude Unit test of a client-side DOM decoration.
 */
import { renderSubjectLine, subjectLineText } from './useSubjectLine.js'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => text.replace(/{(\w+)}/g, (m, key) => vars[key] ?? m),
	translatePlural: (app, one, many, count) => (count === 1 ? one : many).replace('%n', count),
}))

describe('subjectLineText', () => {
	it('reads count, mailbox and the first connection', () => {
		expect(subjectLineText(1, 'Inbox', [{ schema: 'Case', identifier: '2026-4833' }, { schema: 'Case' }]))
			.toBe('1 message · Inbox · connected to case #2026-4833')
		expect(subjectLineText(3, 'Inbox', [{ schema: 'Complaint' }])).toBe('3 messages · Inbox · connected to complaint')
		expect(subjectLineText(2, '', [])).toBe('2 messages')
		expect(subjectLineText(0, '', [])).toBe('')
	})
})

describe('renderSubjectLine', () => {
	beforeEach(() => {
		document.body.innerHTML = `
			<div id="app-navigation-vue"><div class="app-navigation-entry active"><span class="app-navigation-entry__name"> Inbox </span></div></div>
			<div id="mail-thread-header-fields"><h2>Street light</h2></div>
			<div id="mail-message"><div class="envelope"></div></div>`
	})

	it('puts one line under the subject and updates it in place', () => {
		renderSubjectLine([])
		renderSubjectLine([{ schema: 'Case', identifier: '2026-4833' }])
		const lines = document.querySelectorAll('#mail-thread-header-fields > .or-mail-subject-line')
		expect(lines).toHaveLength(1)
		expect(lines[0].textContent).toBe('1 message · Inbox · connected to case #2026-4833')
		expect(lines[0].previousElementSibling.tagName).toBe('H2')
	})

	it('does nothing while no conversation is open', () => {
		document.body.innerHTML = ''
		expect(() => renderSubjectLine([])).not.toThrow()
	})
})
