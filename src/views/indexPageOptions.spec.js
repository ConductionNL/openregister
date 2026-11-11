/**
 * The shared list-view settings: the built-in CnIndexPage actions stay off,
 * the timestamp columns close the table, and the row openers select a row
 * before they open a modal or dialog on it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec exclude Unit test of list-view configuration plumbing.
 */
import { navigationStore } from '../store/store.js'
import {
	BUILTIN_ACTIONS_OFF,
	rowOpeners,
	timestampColumns,
} from './indexPageOptions.js'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => `${app}:${text}`,
}))
jest.mock('../store/store.js', () => ({
	navigationStore: { setModal: jest.fn(), setDialog: jest.fn() },
}))

describe('BUILTIN_ACTIONS_OFF', () => {
	it('switches every built-in row and mass action off', () => {
		expect(BUILTIN_ACTIONS_OFF).toEqual({
			showEditAction: false,
			showCopyAction: false,
			showDeleteAction: false,
			showMassImport: false,
			showMassExport: false,
			showMassCopy: false,
			showMassDelete: false,
		})
		expect(Object.isFrozen(BUILTIN_ACTIONS_OFF)).toBe(true)
	})
})

describe('timestampColumns', () => {
	it('gives sortable created and updated columns', () => {
		expect(timestampColumns()).toEqual([
			{ key: 'created', label: 'openregister:Created', sortable: true },
			{ key: 'updated', label: 'openregister:Updated', sortable: true },
		])
	})

	it('adds extra settings to both columns', () => {
		expect(timestampColumns({ type: 'date-time' })).toEqual([
			{
				key: 'created',
				label: 'openregister:Created',
				sortable: true,
				type: 'date-time',
			},
			{
				key: 'updated',
				label: 'openregister:Updated',
				sortable: true,
				type: 'date-time',
			},
		])
	})
})

describe('rowOpeners', () => {
	const calls = []
	const { openModal, openDialog } = rowOpeners((item) =>
		calls.push(['select', item.id]),
	)

	beforeEach(() => {
		calls.length = 0
		navigationStore.setModal.mockImplementation((name) =>
			calls.push(['modal', name]),
		)
		navigationStore.setDialog.mockImplementation((name) =>
			calls.push(['dialog', name]),
		)
	})

	it('selects the row, then opens the modal', () => {
		openModal({ id: 7 }, 'editSource')
		expect(calls).toEqual([
			['select', 7],
			['modal', 'editSource'],
		])
	})

	it('selects the row, then opens the dialog', () => {
		openDialog({ id: 8 }, 'deleteSource')
		expect(calls).toEqual([
			['select', 8],
			['dialog', 'deleteSource'],
		])
	})
})
