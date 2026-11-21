/**
 * packDraft duplicateTargets: rows without a target are never duplicates.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { duplicateTargets } from './packDraft.js'

describe('packDraft duplicateTargets', () => {
	it('skips empty targets and reports the 1-based rows of each repeated target', () => {
		const result = duplicateTargets([
			{ target: '' }, { target: 'name' }, { target: '' }, { target: 'name' }, { target: 'age' },
		])
		expect([...result]).toEqual([['name', [2, 4]]])
	})
})
