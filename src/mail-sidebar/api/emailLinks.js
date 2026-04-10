/**
 * API wrapper for email link endpoints.
 *
 * @package OpenRegister
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const TIMEOUT = 10000

/**
 * Get objects linked to a specific email message.
 *
 * @param {number} accountId The mail account ID.
 * @param {number} messageId The mail message ID.
 * @param {AbortSignal} [signal] Optional abort signal.
 * @return {Promise<object>} The response data with results and total.
 */
export async function fetchLinkedObjects(accountId, messageId, signal) {
	const url = generateUrl('/apps/openregister/api/emails/by-message/{accountId}/{messageId}', {
		accountId,
		messageId,
	})
	const response = await axios.get(url, { timeout: TIMEOUT, signal })
	return response.data
}

/**
 * Get objects linked to emails from a specific sender.
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
 * Create a quick link between an email and an object.
 *
 * @param {object} params The link parameters.
 * @return {Promise<object>} The created link data.
 */
export async function createQuickLink(params) {
	const url = generateUrl('/apps/openregister/api/emails/quick-link')
	const response = await axios.post(url, params, { timeout: TIMEOUT })
	return response.data
}

/**
 * Delete an email link.
 *
 * @param {number} linkId The link ID to delete.
 * @return {Promise<object>} The response data.
 */
export async function deleteEmailLink(linkId) {
	const url = generateUrl('/apps/openregister/api/emails/{linkId}', { linkId })
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
