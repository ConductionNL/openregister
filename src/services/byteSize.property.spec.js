/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Property tests for the byte-size labels: every size up to the terabyte
 * range gets a known unit, a value between 1 and 1024, and a label that reads
 * back to the original size within the rounding of its decimals.
 *
 * @spec exclude Property test of a presentation helper.
 */
import fc from 'fast-check'
import { binarySize, uploadSizeLabel } from './byteSize.js'

const UNITS = ['B', 'KB', 'MB', 'GB', 'TB']
const MAX_BYTES = 1024 ** 5 - 1

function parse (label) {
	const [value, unit] = label.split(' ')
	return { value: Number(value), unit }
}

describe('binarySize', () => {
	it('labels every positive size with a known unit and a value from 1 to 1024', () => {
		fc.assert(fc.property(fc.integer({ min: 1, max: MAX_BYTES }), (bytes) => {
			const { value, unit } = parse(binarySize(bytes))
			expect(UNITS).toContain(unit)
			expect(value).toBeGreaterThanOrEqual(1)
			expect(value).toBeLessThanOrEqual(1024)
		}))
	})

	it('reads back to the size within the rounding of two decimals', () => {
		fc.assert(fc.property(fc.integer({ min: 1, max: MAX_BYTES }), (bytes) => {
			const { value, unit } = parse(binarySize(bytes))
			const scale = 1024 ** UNITS.indexOf(unit)
			expect(Math.abs(value * scale - bytes)).toBeLessThanOrEqual(0.005 * scale + Number.EPSILON * bytes)
		}))
	})
})

describe('uploadSizeLabel', () => {
	it('labels every size below a kilobyte as less than 1 KB', () => {
		fc.assert(fc.property(fc.integer({ min: 1, max: 1023 }), (bytes) => {
			expect(uploadSizeLabel(bytes)).toBe('< 1 KB')
		}))
	})

	it('labels every larger size with one decimal and a known unit', () => {
		fc.assert(fc.property(fc.integer({ min: 1024, max: MAX_BYTES }), (bytes) => {
			expect(uploadSizeLabel(bytes)).toMatch(/^\d+\.\d (KB|MB|GB|TB)$/)
		}))
	})
})
