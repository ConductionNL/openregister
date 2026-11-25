/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Display names of Nextcloud users for the mail panel.
 *
 * @spec exclude Unit test of a client-side name lookup.
 */
import axios from '@nextcloud/axios'
import { clearDisplayNames, displayNameOf } from './displayNames.js'

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn() } }))
jest.mock('@nextcloud/router', () => ({ generateOcsUrl: (path) => '/ocs/v2.php/' + path }))
jest.mock('@nextcloud/auth', () => ({ getCurrentUser: () => ({ uid: 'me', displayName: 'Myself' }) }))

beforeEach(() => {
	clearDisplayNames()
	axios.get.mockReset()
})

describe('displayNameOf', () => {
	it('names the signed-in user from the session, without asking', async () => {
		expect(await displayNameOf('me')).toBe('Myself')
		expect(axios.get).not.toHaveBeenCalled()
	})

	it('reads a user once and returns the exact match', async () => {
		axios.get.mockResolvedValue({ data: { ocs: { data: [{ id: 'pieter2', label: 'Pieter Two' }, { id: 'pieter', label: 'Pieter Jansen' }] } } })
		expect(await displayNameOf('pieter')).toBe('Pieter Jansen')
		expect(await displayNameOf('pieter')).toBe('Pieter Jansen')
		expect(axios.get).toHaveBeenCalledTimes(1)
		expect(axios.get.mock.calls[0][1].params).toMatchObject({ search: 'pieter', 'shareTypes[]': 0 })
	})

	it('keeps the id when the user is not found or the read fails, and asks nothing for no id', async () => {
		axios.get.mockResolvedValueOnce({ data: { ocs: { data: [] } } })
		expect(await displayNameOf('ghost')).toBe('ghost')
		axios.get.mockRejectedValueOnce(new Error('down'))
		expect(await displayNameOf('anouk')).toBe('anouk')
		expect(await displayNameOf('')).toBe('')
		expect(axios.get).toHaveBeenCalledTimes(2)
	})
})
