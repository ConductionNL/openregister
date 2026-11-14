/**
 * Follow and read state of the record the detail page shows
 * (record-star-follow-and-unread-on-screen): the props of its
 * CnFollowToggle and the read-state requests the page sends.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 * @spec openspec/changes/record-star-follow-and-unread-on-screen/tasks.md
 */

/**
 * The register, schema and id a record is addressed by, or null when one is missing.
 *
 * @param {object|null} item The record as the store holds it
 * @return {{register: string, schema: string, id: string}|null} The address
 * @spec openspec/changes/record-star-follow-and-unread-on-screen/tasks.md
 */
export function recordAddress(item) {
	if (!item) {
		return null
	}

	const self = item['@self'] || {}
	const register = self.register ?? item.register
	const schema = self.schema ?? item.schema
	const id = self.id ?? item.id ?? item.uuid
	if (!register || !schema || !id || typeof register === 'object' || typeof schema === 'object') {
		return null
	}

	return { register: String(register), schema: String(schema), id: String(id) }
}

/**
 * The CnFollowToggle props for a record, from its `@self` (D-1), or null
 * when the record has no address.
 *
 * `watcherCount` stays null when the server sent none, which hides the
 * count and the followers popover; `canManage` is true only for an explicit
 * `@self.can.manage === true` (D-6), so a missing answer hides the picker.
 *
 * @param {object|null} item The record as the store holds it
 * @return {object|null} The props
 * @spec openspec/changes/record-star-follow-and-unread-on-screen/tasks.md
 */
export function followProps(item) {
	const address = recordAddress(item)
	if (address === null) {
		return null
	}

	const self = item['@self'] || {}
	return {
		register: address.register,
		schema: address.schema,
		objectId: address.id,
		watching: self.watching === true,
		notify: typeof self.watchNotify === 'boolean' ? self.watchNotify : null,
		watcherCount: typeof self.watcherCount === 'number' ? self.watcherCount : null,
		canManage: self.can?.manage === true,
	}
}

/**
 * The read-state URL of a record.
 *
 * @param {{register: string, schema: string, id: string}} address The record's address
 * @return {string} The URL
 * @spec openspec/changes/record-star-follow-and-unread-on-screen/tasks.md
 */
export function readStateUrl(address) {
	const parts = [address.register, address.schema, address.id].map(encodeURIComponent)
	return `/index.php/apps/openregister/api/objects/${parts.join('/')}/read-state`
}

/**
 * Mark a record read (`PUT`) or unread (`DELETE`).
 *
 * A failure is reported as false rather than thrown: the read state is a
 * courtesy beside the record, and losing it must not break the page.
 *
 * @param {{register: string, schema: string, id: string}} address The record's address
 * @param {'PUT'|'DELETE'} method PUT marks it read, DELETE unread
 * @param {Function} fetchFn The fetch to send it with
 * @return {Promise<boolean>} Whether the server accepted it
 * @spec openspec/changes/record-star-follow-and-unread-on-screen/tasks.md
 */
export async function sendReadState(address, method, fetchFn = globalThis.fetch) {
	try {
		const response = await fetchFn(readStateUrl(address), {
			method,
			headers: {
				Accept: 'application/json',
				'OCS-APIRequest': 'true',
				requesttoken: globalThis.OC?.requestToken,
			},
		})
		return response.ok === true
	} catch {
		return false
	}
}

/**
 * The record page's tabs that carry an unread badge, with the
 * `@self.unreadCounts` keys each one adds up.
 */
const TAB_UNREAD_KEYS = {
	files: ['files'],
	syncs: ['syncs'],
	emails: ['emails', 'messages'],
	events: ['events'],
	contacts: ['contacts'],
	deck: ['deck'],
}

/**
 * The unread badge of every tab that has one, from the record's
 * `@self.unreadCounts` (design D-1, D-3). A tab whose keys hold no positive
 * whole count is left out, so it shows no badge; anything the server sends
 * that is not a count is ignored.
 *
 * @param {object|null} item The record as the store holds it
 * @return {{[key: string]: number}} Tab key to unread count
 * @spec openspec/changes/record-star-follow-and-unread-on-screen/specs/object-read-state/spec.md#requirement-opening-a-records-page-marks-it-read-and-badges-its-tabs-req-ors-005
 */
export function unreadBadges(item) {
	const counts = item?.['@self']?.unreadCounts
	if (!counts || typeof counts !== 'object' || Array.isArray(counts)) {
		return {}
	}

	const badges = {}
	for (const [tab, keys] of Object.entries(TAB_UNREAD_KEYS)) {
		const total = keys
			.map((key) => counts[key])
			.filter((count) => Number.isInteger(count) && count > 0)
			.reduce((sum, count) => sum + count, 0)
		if (total > 0) {
			badges[tab] = total
		}
	}

	return badges
}
