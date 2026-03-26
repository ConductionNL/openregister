/**
 * API wrapper for linked entity endpoints (mail).
 *
 * Uses the generic linked entity API instead of email-specific endpoints.
 *
 * @package OpenRegister
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const TIMEOUT = 10000

/**
 * Get objects linked to a specific email message via reverse lookup.
 *
 * @param {number} accountId The mail account ID.
 * @param {number} messageId The mail message ID.
 * @param {AbortSignal} [signal] Optional abort signal.
 * @return {Promise<object>} The response data with results and total.
 */
export async function fetchLinkedObjects(accountId, messageId, signal) {
	const entityId = `${accountId}/${messageId}`
	const url = generateUrl('/apps/openregister/api/linked/mail/{entityId}', {
		entityId,
	})
	const response = await axios.get(url, { timeout: TIMEOUT, signal })
	return response.data
}

/**
 * Get objects linked to emails from a specific sender.
 *
 * Note: Sender-based lookup is not directly supported by the generic API.
 * This falls back to the legacy endpoint until sender-based reverse lookup is implemented.
 *
 * @param {string} sender The sender email address.
 * @param {AbortSignal} [signal] Optional abort signal.
 * @return {Promise<object>} The response data with results and total.
 */
export async function fetchSenderObjects(sender, signal) {
	const url = generateUrl('/apps/openregister/api/emails/by-sender')
	const response = await axios.get(url, {
		params: { sender },
		timeout: TIMEOUT,
		signal,
	})
	return response.data
}

/**
 * Create a link between an email and an object.
 *
 * @param {object} params The link parameters (objectUuid, mailAccountId, mailMessageId).
 * @return {Promise<object>} The updated linked IDs.
 */
export async function createQuickLink(params) {
	const { objectUuid, mailAccountId, mailMessageId } = params
	const entityId = `${mailAccountId}/${mailMessageId}`
	const url = generateUrl('/apps/openregister/api/objects/{uuid}/_linked/mail', {
		uuid: objectUuid,
	})
	const response = await axios.post(url, { id: entityId }, { timeout: TIMEOUT })
	return response.data
}

/**
 * Remove a mail link from an object.
 *
 * @param {string} objectUuid The object UUID.
 * @param {string} entityId The mail entity ID (e.g., "1/6").
 * @return {Promise<object>} The response data.
 */
export async function deleteEmailLink(objectUuid, entityId) {
	const url = generateUrl('/apps/openregister/api/objects/{uuid}/_linked/mail/{entityId}', {
		uuid: objectUuid,
		entityId,
	})
	const response = await axios.delete(url, { timeout: TIMEOUT })
	return response.data
}

/**
 * Search for objects by query string.
 *
 * @param {string} query The search query.
 * @param {AbortSignal} [signal] Optional abort signal.
 * @return {Promise<object>} The search results.
 */
export async function searchObjects(query, signal) {
	const url = generateUrl('/apps/openregister/api/objects')
	const response = await axios.get(url, {
		params: { _search: query, _limit: 20 },
		timeout: TIMEOUT,
		signal,
	})
	return response.data
}
