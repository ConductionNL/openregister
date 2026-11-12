/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The access-link page entry mounts the page with the anchor from the server.
 *
 * @spec exclude Unit test of the client-side access-link entry point.
 */

jest.mock('@nextcloud/initial-state', () => ({
	loadState: jest.fn((app, key, fallback) => (key === 'accessLinkAnchor' ? 'sec-2' : fallback)),
}))
jest.mock('./views/accessLink/AccessLinkPage.vue', () => ({
	name: 'AccessLinkPage',
	props: ['anchor'],
	template: '<p class="page">{{ anchor }}</p>',
}))

describe('access-link entry', () => {
	it('mounts the page into its host with the server anchor', () => {
		document.body.innerHTML = '<div id="openregister-access-link"></div>'
		require('./access-link.js')
		const { loadState } = require('@nextcloud/initial-state')
		expect(loadState).toHaveBeenCalledWith('openregister', 'accessLinkAnchor', '')
		expect(document.querySelector('#openregister-access-link .page').textContent).toBe('sec-2')
	})
})
