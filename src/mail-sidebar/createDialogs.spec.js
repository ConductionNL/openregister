/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Create dialogs other apps offer to the Mail panel.
 *
 * @spec exclude Unit test of a client-side registry lookup.
 */
import { createDialogFor, offeredCreateDialogs } from './createDialogs.js'

const open = () => Promise.resolve(null)

afterEach(() => {
	delete window.OCA
})

describe('createDialogFor', () => {
	it('finds the entry for a register and schema by slug or id', () => {
		window.OCA = { OpenRegister: { mailCreateDialogs: [{ register: 'dossiq', schema: 'case', open }] } }
		const register = { id: 42, slug: 'dossiq' }
		expect(createDialogFor(register, { id: 72, slug: 'case' })).toEqual({ register: 'dossiq', schema: 'case', open })
		window.OCA.OpenRegister.mailCreateDialogs.push({ register: 42, schema: 160, open })
		expect(createDialogFor(register, { id: 160, slug: 'mailIntakeEntry' }).schema).toBe(160)
		expect(createDialogFor(register, { id: 9, slug: 'complaint' })).toBeNull()
	})

	it('ignores entries without an open function, and works without any app', () => {
		window.OCA = { OpenRegister: { mailCreateDialogs: [{ register: 'dossiq', schema: 'case' }, null] } }
		expect(createDialogFor({ slug: 'dossiq' }, { slug: 'case' })).toBeNull()
		delete window.OCA
		expect(offeredCreateDialogs()).toEqual([])
		expect(createDialogFor({ slug: 'dossiq' }, { slug: 'case' })).toBeNull()
	})
})
