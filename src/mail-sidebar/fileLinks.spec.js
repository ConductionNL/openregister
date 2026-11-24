/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import { fill, itemOf, resultsOf, safeName, valueAt } from './fileLinks.js'

jest.mock('@nextcloud/auth', () => ({ getCurrentUser: () => ({ uid: 'pieter' }) }))
jest.mock('@nextcloud/axios', () => ({ get: jest.fn(), put: jest.fn(), request: jest.fn(), delete: jest.fn() }))

const BLOCK = {
	key: '72:workpackage',
	title: 'Work package',
	show: {
		id: 'id',
		title: 'subject',
		identifier: '#{id}',
		context: '_links.project.title',
		status: '_links.status.title',
		handler: '_links.assignee.title',
		url: 'http://op.example/work_packages/{id}',
	},
}

const RESULT = {
	id: 38,
	subject: 'Replace street lights Lindelaan',
	_links: { project: { title: 'Lindelaan street lighting' }, status: { title: 'New' }, assignee: { title: 'Pieter Jansen' } },
}

describe('fileLinks', () => {
	it('reads dotted paths and fills tokens', () => {
		expect(valueAt(RESULT, '_links.status.title')).toBe('New')
		expect(valueAt(RESULT, 'missing.path')).toBeUndefined()
		expect(fill('Projects/{subject}', RESULT)).toBe('Projects/Replace street lights Lindelaan')
	})

	it('shows a result as a card says it', () => {
		expect(itemOf(BLOCK, RESULT)).toEqual({
			id: 38,
			title: 'Replace street lights Lindelaan',
			identifier: '#38',
			context: 'Lindelaan street lighting',
			status: 'New',
			handler: 'Pieter Jansen',
			url: 'http://op.example/work_packages/38',
		})
	})

	it('takes the results from an OCS envelope, a list or a results field', () => {
		expect(resultsOf({ ocs: { data: [RESULT] } })).toEqual([RESULT])
		expect(resultsOf([RESULT])).toEqual([RESULT])
		expect(resultsOf({ results: [RESULT] })).toEqual([RESULT])
		expect(resultsOf({})).toEqual([])
	})

	it('makes a name Files accepts', () => {
		expect(safeName('Re: lights / Lindelaan?')).toBe('Re lights Lindelaan')
		expect(safeName('')).toBe('mail')
	})
})
