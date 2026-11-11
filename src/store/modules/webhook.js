import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'
import { itemRequest } from './itemRequest.js'

/**
 * Create a webhook, or update it when it has an id. The id addresses the
 * resource in the URL and is left out of the body, so create and update send
 * the same shape.
 *
 * @param {object} webhookItem The webhook.
 * @return {Promise<object>} The axios response.
 * @spec exclude Thin API passthrough — POST/PUT /api/webhooks.
 */
function sendWebhook(webhookItem) {
	const { id, ...body } = webhookItem
	return id
		? axios.put(generateUrl(`/apps/openregister/api/webhooks/${id}`), body)
		: axios.post(generateUrl('/apps/openregister/api/webhooks'), body)
}

export const useWebhookStore = defineStore('webhook', {
	state: () => ({
		webhookItem: null,
		webhookList: [],
		loading: false,
		error: null,
	}),
	getters: {
		getWebhookItem: (state) => state.webhookItem,
		isLoading: (state) => state.loading,
		getError: (state) => state.error,
	},
	actions: {
		/**
		 * Set the active webhook item.
		 *
		 * @param {object|null} webhookItem - The webhook to make active
		 * @spec exclude Client state mutator — stores the active webhook. No backend contract.
		 */
		setWebhookItem(webhookItem) {
			this.webhookItem = webhookItem || null
		},
		/**
		 * Set the webhook list.
		 *
		 * @param {Array} webhookList - Array of webhook objects
		 * @spec exclude Client state mutator — stores the webhook list. No backend contract.
		 */
		setWebhookList(webhookList) {
			this.webhookList = webhookList || []
		},
		/**
		 * Refresh the webhook list from the API
		 *
		 * @param {boolean} soft - If true, don't show loading state (default: false)
		 * @return {Promise} Promise with response and data
		 *
		 * @spec exclude Thin API passthrough — GET /api/webhooks list; observable contract owned by webhook-payload-mapping.
		 */
		/* istanbul ignore next */
		async refreshWebhookList(soft = false) {
			return itemRequest(this, {
				clearsError: true,
				soft,
				log: (error) => console.error('Error fetching webhooks:', error),
				run: async () => {
					const response = await axios.get(
						generateUrl('/apps/openregister/api/webhooks'),
					)
					const data = response.data.results || []
					this.setWebhookList(data)
					return { response, data }
				},
			})
		},

		/**
		 * Create or update a webhook, then refresh the list so every mounted view
		 * reflects the change. Callers must not reload the page for this.
		 *
		 * @param {object} webhookItem - webhook payload; an `id` selects update over create
		 * @return {Promise} Promise with response and data
		 *
		 * @spec exclude Thin API passthrough — POST/PUT /api/webhooks; observable contract owned by webhook-payload-mapping.
		 */
		async saveWebhook(webhookItem) {
			if (!webhookItem) {
				throw new Error('No webhook to save')
			}
			return itemRequest(this, {
				clearsError: true,
				log: (error) => console.error('Error saving webhook:', error),
				run: async () => {
					const response = await sendWebhook(webhookItem)
					const data = response.data
					this.setWebhookItem(data)
					await this.refreshWebhookList(true)
					return { response, data }
				},
			})
		},

		/**
		 * Delete a webhook, then refresh the list.
		 *
		 * @param {number} webhookId - webhook id
		 * @return {Promise} Promise with the response
		 *
		 * @spec exclude Thin API passthrough — DELETE /api/webhooks/{id}; observable contract owned by webhook-payload-mapping.
		 */
		async deleteWebhook(webhookId) {
			if (!webhookId) {
				throw new Error('No webhook to delete')
			}
			this.error = null
			return itemRequest(this, {
				soft: true,
				log: (error) => console.error('Error deleting webhook:', error),
				run: async () => {
					const response = await axios.delete(
						generateUrl(`/apps/openregister/api/webhooks/${webhookId}`),
					)
					await this.refreshWebhookList(true)
					this.setWebhookItem(null)
					return { response }
				},
			})
		},

		/**
		 * Flip a webhook's enabled flag, then refresh the list.
		 *
		 * @param {object} webhook - the webhook to toggle
		 * @return {Promise} Promise with the response
		 *
		 * @spec exclude Thin API passthrough — PUT /api/webhooks/{id}; observable contract owned by webhook-payload-mapping.
		 */
		async toggleWebhookEnabled(webhook) {
			if (!webhook?.id) {
				throw new Error('No webhook to toggle')
			}
			this.error = null
			return itemRequest(this, {
				soft: true,
				log: (error) => console.error('Error toggling webhook:', error),
				run: async () => {
					const response = await axios.put(
						generateUrl(`/apps/openregister/api/webhooks/${webhook.id}`),
						{ enabled: !webhook.enabled },
					)
					await this.refreshWebhookList(true)
					return { response }
				},
			})
		},
	},
})
