/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A Mail-panel block that connects the mail to an item of another app through
 * files: the mail (.eml) and its attachments are saved to the user's Files
 * and those files are linked to the item through the app's file-link API.
 * The block is configured, not coded per app: a `mailBlocks` entry with
 * `type: "fileLink"` names the search and link endpoints and which fields of a
 * result to show.
 *
 * Which items a mail is connected to is kept in a small JSON file per mail in
 * the user's hidden `.mail-links` folder, so the connection survives a reload
 * and can be undone: the record lists every saved file and every file link.
 *
 * @spec openspec/specs/mail-sidebar/spec.md
 */

import axios from '@nextcloud/axios'
import { getCurrentUser } from '@nextcloud/auth'
import { generateRemoteUrl, generateUrl } from '@nextcloud/router'
import { reactive } from 'vue'

export const LINKS_FOLDER = '.mail-links'

/** The items the open mail is connected to through file-link blocks. */
export const externalLinks = reactive({ key: '', items: [] })

/** The file-link blocks the panel offers, by key. */
export const fileLinkBlocks = reactive({})

/**
 * Remember the file-link blocks the panel offers.
 *
 * @param {Array<object>} blocks The panel's blocks.
 * @return {void}
 */
export function rememberBlocks(blocks) {
	for (const block of blocks) {
		if (block.fileLink) fileLinkBlocks[block.fileLink.key] = block.fileLink
	}
}

/**
 * A value at a dotted path of an object.
 *
 * @param {object} object The object.
 * @param {string|undefined} path The path, e.g. `_links.status.title`.
 * @return {*}
 */
export function valueAt(object, path) {
	if (!path) return undefined
	return String(path).split('.').reduce((value, key) => (value == null ? undefined : value[key]), object)
}

/**
 * Fill `{field}` tokens from an object.
 *
 * @param {string} template The template.
 * @param {object} object The values.
 * @return {string}
 */
export function fill(template, object) {
	return String(template || '').replace(/\{([\w.]+)\}/g, (match, path) => {
		const value = valueAt(object, path)
		return value == null ? '' : String(value)
	})
}

/**
 * A file or folder name without characters Files refuses.
 *
 * @param {string} name The name.
 * @return {string}
 */
export function safeName(name) {
	return String(name || '').replace(/[\\/:*?"<>|]+/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 120) || 'mail'
}

/**
 * One search result as a card shows it.
 *
 * @param {object} block The block's `mailBlocks` entry.
 * @param {object} result The result as the app returns it.
 * @return {{id: *, title: string, identifier: string, context: string, status: string, handler: string, url: string}}
 */
export function itemOf(block, result) {
	const show = block.show || {}
	return {
		id: valueAt(result, show.id || 'id'),
		title: String(valueAt(result, show.title || 'title') ?? ''),
		identifier: show.identifier ? fill(show.identifier, result) : '',
		context: show.context ? String(valueAt(result, show.context) ?? '') : '',
		status: show.status ? String(valueAt(result, show.status) ?? '') : '',
		handler: show.handler ? String(valueAt(result, show.handler) ?? '') : '',
		url: show.url ? fill(show.url, result) : '',
	}
}

/**
 * The results list of a response: the OCS envelope's data, or the body.
 *
 * @param {*} data The response body.
 * @return {Array<object>}
 */
export function resultsOf(data) {
	const inner = data?.ocs?.data ?? data
	if (Array.isArray(inner)) return inner
	if (Array.isArray(inner?.results)) return inner.results
	return []
}

/**
 * Search the block's items.
 *
 * @param {object} block The block's `mailBlocks` entry.
 * @param {string} term The search term.
 * @return {Promise<Array<object>>} Items, as cards show them.
 */
export async function searchItems(block, term) {
	const search = block.search || {}
	const response = await axios.get(search.url, {
		params: { ...(search.params || {}), [search.query || 'search']: term },
		headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
	})
	return resultsOf(response.data).map((result) => itemOf(block, result))
}

/**
 * The user's WebDAV URL for a path in their Files.
 *
 * @param {string} path The path, relative to the Files root.
 * @return {string}
 */
function davUrl(path) {
	const uid = getCurrentUser()?.uid
	return generateRemoteUrl('dav/files/' + encodeURIComponent(uid)) + '/' + path.split('/').map(encodeURIComponent).join('/')
}

/**
 * Make a folder and its parents in the user's Files.
 *
 * @param {string} path The folder.
 * @return {Promise<void>}
 */
async function makeFolder(path) {
	let current = ''
	for (const part of path.split('/').filter(Boolean)) {
		current = current ? current + '/' + part : part
		await axios.request({ method: 'MKCOL', url: davUrl(current), validateStatus: (s) => s === 201 || s === 405 })
	}
}

/**
 * Store content in the user's Files and read back its file id.
 *
 * @param {string} path The file's path.
 * @param {Blob|string} content The content.
 * @return {Promise<number>} The file id.
 */
async function putFile(path, content) {
	await axios.put(davUrl(path), content)
	const response = await axios.request({
		method: 'PROPFIND',
		url: davUrl(path),
		headers: { Depth: '0', 'Content-Type': 'application/xml' },
		data: '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:fileid/></d:prop></d:propfind>',
	})
	const match = String(response.data).match(/<oc:fileid>(\d+)<\/oc:fileid>/)
	if (!match) throw new Error('No file id for ' + path)
	return Number(match[1])
}

/**
 * The mail's record of its file-link connections.
 *
 * @param {number} accountId The mail account.
 * @param {number} messageId The message.
 * @return {string} The record's path.
 */
function recordPath(accountId, messageId) {
	return LINKS_FOLDER + '/' + accountId + '-' + messageId + '.json'
}

/**
 * Read the connections of a mail into `externalLinks`.
 *
 * @param {number} accountId The mail account.
 * @param {number} messageId The message.
 * @return {Promise<Array<object>>}
 */
export async function loadConnections(accountId, messageId) {
	const key = accountId + '-' + messageId
	externalLinks.key = key
	let items = []
	try {
		const response = await axios.get(davUrl(recordPath(accountId, messageId)), { validateStatus: (s) => s === 200 })
		items = Array.isArray(response.data) ? response.data : []
	} catch {
		items = []
	}
	if (externalLinks.key === key) externalLinks.items = items
	return items
}

/**
 * Write the mail's connections.
 *
 * @param {number} accountId The mail account.
 * @param {number} messageId The message.
 * @param {Array<object>} items The connections.
 * @return {Promise<void>}
 */
async function saveConnections(accountId, messageId, items) {
	await makeFolder(LINKS_FOLDER)
	if (items.length === 0) {
		await axios.delete(davUrl(recordPath(accountId, messageId)), { validateStatus: () => true })
	} else {
		await axios.put(davUrl(recordPath(accountId, messageId)), JSON.stringify(items, null, 1))
	}
	if (externalLinks.key === accountId + '-' + messageId) externalLinks.items = items
}

/**
 * Link files to an item through the block's link endpoint.
 *
 * @param {object} block The block's `mailBlocks` entry.
 * @param {object} item The item.
 * @param {Array<{id: number, name: string}>} files The files.
 * @return {Promise<void>}
 */
async function linkFiles(block, item, files) {
	const body = JSON.parse(JSON.stringify(block.link.body), (key, value) => {
		if (value === '{id}') return Number.isNaN(Number(item.id)) ? item.id : Number(item.id)
		if (value === '{files}') return files.map((f) => ({ id: f.id, name: f.name }))
		return value
	})
	await axios.request({ method: block.link.method || 'POST', url: block.link.url, data: body, headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' } })
}

/**
 * Save one attachment of the mail into the item's folder.
 *
 * @param {number} messageId The message.
 * @param {{attachmentId: string, fileName: string}} attachment The attachment.
 * @param {string} folder The folder.
 * @return {Promise<{id: number, name: string, path: string}>}
 */
async function saveAttachment(messageId, attachment, folder) {
	const name = safeName(attachment.fileName || 'attachment-' + attachment.attachmentId)
	const response = await axios.get(generateUrl('/apps/mail/api/messages/{id}/attachment/{attachmentId}', { id: messageId, attachmentId: attachment.attachmentId }), { responseType: 'blob' })
	const path = folder + '/' + name
	return { id: await putFile(path, response.data), name, path }
}

/**
 * Connect the mail to an item: save the mail and its attachments into the
 * item's folder, link them to the item, and record the connection.
 *
 * @param {object} block The block's `mailBlocks` entry.
 * @param {object} item The chosen item.
 * @param {{accountId: number, messageId: number, subject: string, attachments: Array<object>}} mail The open mail.
 * @return {Promise<object>} The connection.
 */
export async function connectMail(block, item, mail) {
	const folder = fill(block.folder || 'Mail/{title}', { ...item, title: safeName(item.title) })
	await makeFolder(folder)
	const eml = await axios.get(generateUrl('/apps/mail/api/messages/{id}/export', { id: mail.messageId }), { responseType: 'blob' })
	const emlName = safeName(mail.subject) + '.eml'
	const files = [{ id: await putFile(folder + '/' + emlName, eml.data), name: emlName, path: folder + '/' + emlName }]
	for (const attachment of mail.attachments || []) {
		files.push(await saveAttachment(mail.messageId, attachment, folder))
	}
	await linkFiles(block, item, files)
	const connection = { block: block.key, kind: block.name || block.title, ...item, folder, files }
	const items = [...(await loadConnections(mail.accountId, mail.messageId)).filter((c) => !(c.block === connection.block && String(c.id) === String(item.id))), connection]
	await saveConnections(mail.accountId, mail.messageId, items)
	return connection
}

/**
 * Add one dropped attachment to a connected item.
 *
 * @param {object} block The block's `mailBlocks` entry.
 * @param {object} connection The connection.
 * @param {{accountId: number, messageId: number}} mail The open mail.
 * @param {object} attachment The dropped attachment.
 * @return {Promise<void>}
 */
export async function addAttachment(block, connection, mail, attachment) {
	const file = await saveAttachment(mail.messageId, attachment, connection.folder)
	await linkFiles(block, connection, [file])
	const items = (await loadConnections(mail.accountId, mail.messageId)).map((c) => (c === connection || (c.block === connection.block && String(c.id) === String(connection.id)) ? { ...c, files: [...(c.files || []), file] } : c))
	await saveConnections(mail.accountId, mail.messageId, items)
}

/**
 * Remove a connection: its file links, the files it saved, and its record.
 *
 * @param {object} block The block's `mailBlocks` entry, or null when it is no longer configured.
 * @param {object} connection The connection.
 * @param {{accountId: number, messageId: number}} mail The open mail.
 * @return {Promise<void>}
 */
export async function disconnect(block, connection, mail) {
	if (block?.unlink) {
		const ids = new Set((connection.files || []).map((f) => f.id))
		const links = resultsOf((await axios.get(fill(block.unlink.list, connection), { headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' } })).data)
		for (const link of links) {
			const origin = valueAt(link, block.unlink.fileId || 'originData.id')
			if (ids.has(Number(origin))) {
				await axios.delete(fill(block.unlink.remove, link), { headers: { 'OCS-APIRequest': 'true' } })
			}
		}
	}
	for (const file of connection.files || []) {
		await axios.delete(davUrl(file.path), { validateStatus: () => true })
	}
	const items = (await loadConnections(mail.accountId, mail.messageId)).filter((c) => !(c.block === connection.block && String(c.id) === String(connection.id)))
	await saveConnections(mail.accountId, mail.messageId, items)
}
