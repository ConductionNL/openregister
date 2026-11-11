import * as flowDetail from './flowDetail.js'
import { flowPageHelpers, flowVersionLabel } from './flowPageHelpers.js'

/**
 * @spec exclude Unit test of the flow page helpers.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
describe('flowPageHelpers', () => {
	it('exposes the flowDetail formatters unchanged', () => {
		for (const [name, helper] of Object.entries(flowPageHelpers)) {
			expect(helper).toBe(flowDetail[name])
		}
		expect(Object.keys(flowPageHelpers).sort()).toEqual([
			'formatDateTime', 'formatDuration', 'shortUuid', 'shownStatus', 'statusLabel', 'statusVariant', 'triggerLabel',
		])
	})
})

describe('flowVersionLabel', () => {
	const versions = [{ version: 1, semver: '1.0.0' }, { version: 2, semver: null }]

	it('shows the semantic version when the version is listed', () => {
		expect(flowVersionLabel(versions, 1)).toBe('v1.0.0')
	})

	it('falls back to the number when the version has no semver or is not listed', () => {
		expect(flowVersionLabel(versions, 2)).toBe('v2')
		expect(flowVersionLabel(versions, 7)).toBe('v7')
		expect(flowVersionLabel(versions, 0)).toBe('v0')
	})

	it('is empty without a version', () => {
		expect(flowVersionLabel(versions, null)).toBe('')
		expect(flowVersionLabel(versions, undefined)).toBe('')
	})
})
