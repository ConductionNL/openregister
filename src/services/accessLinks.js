/**
 * Access links: the owner side (mint, list, switch off, revoke) and the
 * holder side (open, comment, upload) of `/api/access-links` and
 * `/api/public/links/{anchor}`.
 *
 * The helpers that decide what a screen shows are pure, so they are tested
 * without a server.
 *
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** The capabilities a link can declare, in the order a form offers them. */
export const CAPABILITIES = ['read', 'comment', 'upload']

/** The header the holder's password rides in, so it never lands in a URL. */
export const PASSWORD_HEADER = 'X-OpenRegister-Link-Password'

/**
 * The state a link is in, for the owner's list.
 *
 * @param {object} link A link as `GET /api/access-links` returns it.
 * @param {Date} now The moment to judge expiry against.
 * @return {'revoked'|'off'|'expired'|'active'} The state.
 */
export function linkState(link, now = new Date()) {
	if (link.revokedAt) {
		return 'revoked'
	}
	if (link.disabled) {
		return 'off'
	}
	if (link.expiresAt && new Date(link.expiresAt).getTime() <= now.getTime()) {
		return 'expired'
	}
	return 'active'
}

/**
 * The links over one subject, newest first. Revoked links are left out: they
 * can never answer again, and the owner has nothing left to do with them.
 *
 * @param {Array<object>} links Every link the caller minted.
 * @param {string} subjectType `object`, `view` or `file`.
 * @param {string} subjectId The subject id.
 * @return {Array<object>} The matching links.
 */
export function linksForSubject(links, subjectType, subjectId) {
	return (links || [])
		.filter(
			(link) =>
				link.subjectType === subjectType
				&& String(link.subjectId) === String(subjectId)
				&& !link.revokedAt,
		)
		.sort((a, b) =>
			String(b.created || '').localeCompare(String(a.created || '')),
		)
}

/**
 * The body for `POST /api/access-links` from what the form holds.
 *
 * The expiry is a date picked in the form; the link stays open through that
 * whole day, so it is sent as the end of that day in the reader's time zone.
 *
 * @param {object} form The form values.
 * @param {string} form.subjectType The subject type.
 * @param {string} form.subjectId The subject id.
 * @param {Array<string>} form.capabilities The capabilities ticked.
 * @param {string} form.expiresOn The expiry date, `YYYY-MM-DD`.
 * @param {string} [form.password] An optional password.
 * @param {string} [form.label] An optional label.
 * @return {object} The request body.
 */
export function mintPayload({
	subjectType,
	subjectId,
	capabilities,
	expiresOn,
	password,
	label,
}) {
	const [year, month, day] = String(expiresOn || '')
		.split('-')
		.map(Number)
	const expiry = new Date(year, (month || 1) - 1, day || 1, 23, 59, 59)
	const body = {
		subjectType,
		subjectId: String(subjectId),
		capabilities: CAPABILITIES.filter((capability) =>
			(capabilities || []).includes(capability),
		),
		expiresAt: expiry.toISOString(),
	}
	if (password) {
		body.password = password
	}
	if (label) {
		body.label = label
	}
	return body
}

/**
 * Every link the caller minted.
 *
 * @return {Promise<Array<object>>} The links.
 */
export async function listAccessLinks() {
	const response = await axios.get(
		generateUrl('/apps/openregister/api/access-links'),
	)
	return response.data?.results || []
}

/**
 * Mint a link.
 *
 * @param {object} body The body from {@link mintPayload}.
 * @return {Promise<object>} The minted link, with `url` and `pageUrl`.
 */
export async function mintAccessLink(body) {
	const response = await axios.post(
		generateUrl('/apps/openregister/api/access-links'),
		body,
	)
	return response.data
}

/**
 * Switch a link off, or back on.
 *
 * @param {number} id The link id.
 * @param {boolean} disabled True to switch it off.
 * @return {Promise<object>} The updated link.
 */
export async function setAccessLinkDisabled(id, disabled) {
	const response = await axios.put(
		generateUrl('/apps/openregister/api/access-links/{id}', { id }),
		{ disabled },
	)
	return response.data
}

/**
 * Revoke a link for good.
 *
 * @param {number} id The link id.
 * @return {Promise<void>}
 */
export async function revokeAccessLink(id) {
	await axios.delete(
		generateUrl('/apps/openregister/api/access-links/{id}', { id }),
	)
}

/**
 * The holder-side API URL for one anchor.
 *
 * @param {string} anchor The link anchor.
 * @param {string} [suffix] `''`, `/comments` or `/files`.
 * @return {string} The URL.
 */
export function publicLinkUrl(anchor, suffix = '') {
	return (
		generateUrl('/apps/openregister/api/public/links/{anchor}', { anchor })
		+ suffix
	)
}

/**
 * Request options that carry the holder's password, if any.
 *
 * @param {string} password The password the holder typed.
 * @return {object} Axios options.
 */
export function passwordOptions(password) {
	return password ? { headers: { [PASSWORD_HEADER]: password } } : {}
}

/**
 * A value as a person reads it: text, never JSON. Lists are joined, nested
 * objects become "field: value" pairs, and metadata keys stay out.
 *
 * @param {unknown} value The value.
 * @return {string} The printable value.
 */
export function printable(value) {
	if (value === null || value === undefined) {
		return ''
	}
	if (Array.isArray(value)) {
		return value
			.map(printable)
			.filter((item) => item !== '')
			.join(', ')
	}
	if (typeof value === 'object') {
		return Object.entries(value)
			.filter(([key]) => !isMetadataKey(key))
			.map(([key, item]) => `${key}: ${printable(item)}`)
			.join('; ')
	}
	if (typeof value === 'boolean') {
		return value ? '✓' : '✗'
	}
	return String(value)
}

/**
 * Whether a key is record metadata rather than a field of the schema.
 *
 * @param {string} key The key.
 * @return {boolean} True for metadata.
 */
function isMetadataKey(key) {
	return key.startsWith('@') || key.startsWith('_') || key === 'id'
}

/**
 * The fields of a record for the holder's page. The server already served
 * only what the schema lets an anonymous reader see (AccessLinkReader runs
 * filterReadableProperties as nobody); this leaves out the metadata and turns
 * every value into text.
 *
 * @param {object} subject The subject as the link serves it.
 * @return {Array<{key: string, value: string}>} Field and printable value.
 */
export function readableFields(subject) {
	if (!subject || typeof subject !== 'object') {
		return []
	}
	return Object.entries(subject)
		.filter(([key]) => !isMetadataKey(key))
		.map(([key, value]) => ({ key, value: printable(value) }))
}

/**
 * Read a picked file as base64, for the link upload.
 *
 * @param {File} file The file.
 * @return {Promise<string>} The base64 content, without the data URL prefix.
 */
export function fileAsBase64(file) {
	return new Promise((resolve, reject) => {
		const reader = new FileReader()
		reader.onload = () =>
			resolve(String(reader.result).replace(/^data:[^,]*,/, ''))
		reader.onerror = () => reject(reader.error)
		reader.readAsDataURL(file)
	})
}
