/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Byte counts as display labels.
 *
 * @spec exclude Unit test of a presentation helper.
 */
import { binarySize, uploadSizeLabel } from './byteSize.js'

describe('uploadSizeLabel', () => {
	it.each([
		[0, 'n/a'],
		[500, '< 1 KB'],
		[1536, '1.5 KB'],
		[5 * 1024 * 1024, '5.0 MB'],
	])('labels %s bytes as %s', (bytes, label) => {
		expect(uploadSizeLabel(bytes)).toBe(label)
	})
})

describe('binarySize', () => {
	it.each([
		[0, '0 B'],
		[512, '512 B'],
		[1536, '1.5 KB'],
		[1234567890, '1.15 GB'],
	])('labels %s bytes as %s', (bytes, label) => {
		expect(binarySize(bytes)).toBe(label)
	})
})
