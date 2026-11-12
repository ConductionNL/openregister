/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Byte counts as the labels the screens show.
 */

/**
 * The size label of an uploaded or attached file: '< 1 KB' below a kilobyte,
 * one decimal above, 'n/a' for an empty file.
 *
 * @spec exclude Byte-size display helper; pure presentation.
 * @param {number} bytes The size in bytes
 * @return {string} The label
 */
export function uploadSizeLabel(bytes) {
	const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB']
	if (bytes === 0) return 'n/a'
	const i = parseInt(Math.floor(Math.log(bytes) / Math.log(1024)))
	if (i === 0 && sizes[i] === 'Bytes') return '< 1 KB'
	if (i === 0) return bytes + ' ' + sizes[i]
	return (bytes / Math.pow(1024, i)).toFixed(1) + ' ' + sizes[i]
}

/**
 * A storage size in binary units with up to two decimals, '0 B' for zero.
 *
 * @spec exclude Byte-size display helper; pure presentation.
 * @param {number} bytes The size in bytes
 * @return {string} The label, e.g. '1.5 MB'
 */
export function binarySize(bytes) {
	if (bytes === 0) return '0 B'

	const k = 1024
	const sizes = ['B', 'KB', 'MB', 'GB', 'TB']
	const i = Math.floor(Math.log(bytes) / Math.log(k))

	return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i]
}
