/**
 * The object vectorization endpoints the settings modals read and call.
 *
 * @spec exclude Thin API passthroughs for the object vectorization modals.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const SETTINGS_URL = '/apps/openregister/api/settings/objects/vectorize'

/**
 * The stored object vectorization settings, as the endpoint answers them.
 *
 * @return {Promise<object>} The response body
 */
export async function readVectorizeSettings() {
	const response = await axios.get(generateUrl(SETTINGS_URL))
	return response.data
}

/**
 * The views an object vectorization run can be limited to; none when they
 * cannot be read.
 *
 * @return {Promise<Array>} The views
 */
export async function readViews() {
	try {
		const response = await axios.get(generateUrl('/apps/openregister/api/views'))
		return response.data.results || []
	} catch (error) {
		console.error('Failed to load views:', error)
		return []
	}
}

/**
 * The number of objects a run over the queried views would cover.
 *
 * @param {object} params The stats query
 * @return {Promise<number>} The number of objects
 */
export async function countObjects(params) {
	const response = await axios.get(
		generateUrl('/apps/openregister/api/objects/vectorize/stats'),
		{ params },
	)
	return response.data.total_objects || 0
}

/**
 * Vectorize one batch of objects.
 *
 * @param {{views: Array|null, batchSize: number}} batch The views and the batch size
 * @return {Promise<object>} The response
 */
export function vectorizeObjects(batch) {
	return axios.post(
		generateUrl('/apps/openregister/api/objects/vectorize/batch'),
		batch,
	)
}

/**
 * Store the object vectorization settings.
 *
 * @param {object} config The settings
 * @return {Promise<object>} The response
 */
export function writeVectorizeSettings(config) {
	return axios.patch(generateUrl(SETTINGS_URL), config)
}
