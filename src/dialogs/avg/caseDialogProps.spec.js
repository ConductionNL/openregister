/**
 * The AVG case dialogs that act on one case (RedactionDialog,
 * DenialComposerDialog) take the same props and offer the active pack's
 * denial grounds the same way.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec exclude Unit test of presentational dialog props.
 */
import DenialComposerDialog from './DenialComposerDialog.vue'
import RedactionDialog from './RedactionDialog.vue'

jest.mock('../../store/store.js', () => ({ __esModule: true, avgStore: {} }))
jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: {} }))
jest.mock('@nextcloud/router', () => ({ __esModule: true, generateUrl: (p) => p }))
jest.mock('@nextcloud/vue', () => ({ NcDialog: {}, NcSelect: {}, NcTextField: {} }))
jest.mock('./AvgFormFooter.vue', () => ({}))

const pack = {
	denialGrounds: [
		{ key: 'art15_4', label: 'Rights of others', citation: 'Art. 15(4)' },
		{ key: '' },
		{ key: 'manifestly_unfounded' },
	],
}

describe.each([
	['RedactionDialog', RedactionDialog],
	['DenialComposerDialog', DenialComposerDialog],
])('%s', (name, dialog) => {
	it('requires the case id and takes an optional pack', () => {
		expect(dialog.props.caseId).toEqual({ type: String, required: true })
		expect(dialog.props.pack).toEqual({ type: Object, default: null })
		expect(Object.keys(dialog.props)).toEqual(['caseId', 'pack'])
	})

	it('offers the pack denial grounds that have a key', () => {
		const options = dialog.computed.groundOptions.call({ pack })
		expect(options.map((o) => o.value)).toEqual(['art15_4', 'manifestly_unfounded'])
		expect(options[0]).toEqual({ value: 'art15_4', label: 'Rights of others', citation: 'Art. 15(4)' })
	})

	it('offers no grounds without a pack', () => {
		expect(dialog.computed.groundOptions.call({ pack: null })).toEqual([])
	})
})
