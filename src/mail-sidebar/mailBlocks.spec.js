/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import { blocksOf } from './mailBlocks.js'

const CASE = {
	id: 72,
	title: 'Case',
	configuration: {
		linkedTypes: ['mail'],
		mailObjectName: 'case',
		mailObjectTemplate: { title: '{{subject}}' },
	},
}

describe('blocksOf', () => {
	it('gives a schema without blocks one block keyed by its id', () => {
		expect(blocksOf(CASE)).toEqual([{ ...CASE, blockKey: '72', blockFilter: {}, appDialog: true }])
	})

	it('gives one block per declared entry, each with its own title, name, template, filter and dialog', () => {
		const schema = {
			...CASE,
			configuration: {
				...CASE.configuration,
				mailBlocks: [
					{ key: 'case' },
					{
						key: 'queue',
						title: 'Work queue',
						name: 'work queue item',
						template: { title: '{{subject}}', assignee: '' },
						filter: { assignee: 'IS NULL' },
						appDialog: false,
					},
				],
			},
		}
		const [first, second] = blocksOf(schema)
		expect(first).toMatchObject({ id: 72, title: 'Case', blockKey: '72:case', blockFilter: {}, appDialog: true })
		expect(first.configuration.mailObjectName).toBe('case')
		expect(second).toMatchObject({ id: 72, title: 'Work queue', blockKey: '72:queue', blockFilter: { assignee: 'IS NULL' }, appDialog: false })
		expect(second.configuration.mailObjectName).toBe('work queue item')
		expect(second.configuration.mailObjectTemplate).toEqual({ title: '{{subject}}', assignee: '' })
		expect(second.configuration.linkedTypes).toEqual(['mail'])
	})

	it('keys an entry without a key by its place, and skips entries that are not objects', () => {
		const schema = { ...CASE, configuration: { ...CASE.configuration, mailBlocks: [null, { title: 'One' }] } }
		expect(blocksOf(schema).map((b) => b.blockKey)).toEqual(['72:1'])
	})
})
