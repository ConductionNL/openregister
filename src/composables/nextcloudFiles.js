/**
 * Links into the Nextcloud Files app and file-size formatting, shared by the
 * views and modals that show an object's folder and attachments. Plain
 * functions: list them in a component's `methods` to use them in a template.
 * Import the module as a namespace (`import * as nextcloudFiles`): in an SFC
 * that also has <script setup>, a named import with the same name as the
 * method shadows it in the dev/test template build.
 *
 * @spec exclude UI plumbing — opens the Nextcloud Files app in a new tab; pure presentation helper
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

/**
 * Opens the folder URL in a new tab after parsing the encoded URL and converting to Nextcloud format
 *
 * @param {string} url - The encoded folder URL to open (e.g. "Open Registers\/Publicatie Register\/Publicatie\/123")
 * @spec exclude UI plumbing — opens the Nextcloud Files app in a new tab
 * @return {void}
 */
export function openFolder(url) {
	// Parse the encoded URL by replacing escaped characters
	const decodedUrl = url.replace(/\\\//g, '/')

	// Ensure URL starts with forward slash
	const normalizedUrl = decodedUrl.startsWith('/')
		? decodedUrl
		: '/' + decodedUrl

	// Construct the proper Nextcloud Files app URL with the normalized path
	// Use window.location.origin to get the current domain instead of hardcoding
	const nextcloudUrl = `${window.location.origin}/index.php/apps/files/files?dir=${encodeURIComponent(normalizedUrl)}`

	// Open URL in new tab
	window.open(nextcloudUrl, '_blank')
}

/**
 * Opens a file in the Nextcloud Files app
 *
 * @param {object} file - The file object containing id, path, and other metadata
 * @spec exclude UI plumbing — opens the Nextcloud Files app in a new tab
 * @return {void}
 */
export function openFile(file) {
	// Extract the directory path without the filename
	const dirPath = file.path.substring(0, file.path.lastIndexOf('/'))

	// Remove the '/admin/files/' prefix if it exists
	const cleanPath = dirPath.replace(/^\/admin\/files\//, '/')

	// Construct the proper Nextcloud Files app URL with file ID and openfile parameter
	const filesAppUrl = `/index.php/apps/files/files/${file.id}?dir=${encodeURIComponent(cleanPath)}&openfile=true`

	// Open URL in new tab
	window.open(filesAppUrl, '_blank')
}

/**
 * Formats a file size in bytes to a human readable string
 *
 * @param {number} bytes - The file size in bytes
 * @spec exclude UI plumbing — pure presentation helper
 * @return {string} Formatted file size (e.g. "1.5 MB")
 */
export function formatFileSize(bytes) {
	const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB']
	if (bytes === 0) return 'n/a'
	const i = parseInt(Math.floor(Math.log(bytes) / Math.log(1024)))
	if (i === 0 && sizes[i] === 'Bytes') return '< 1 KB'
	if (i === 0) return bytes + ' ' + sizes[i]
	return (bytes / Math.pow(1024, i)).toFixed(1) + ' ' + sizes[i]
}
