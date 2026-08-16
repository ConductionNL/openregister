import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'

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
			if (!soft) {
				this.loading = true
			}
			this.error = null

			try {
				const response = await axios.get(
					generateUrl('/apps/openregister/api/webhooks'),
				)

				const data = response.data.results || []
				this.setWebhookList(data)

				return { response, data }
			} catch (error) {
				console.error('Error fetching webhooks:', error)
				this.error = error.message
				throw error
			} finally {
				if (!soft) {
					this.loading = false
				}
			}
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

			this.loading = true
			this.error = null

			const isNewWebhook = !webhookItem.id
			// `id` addresses the resource in the URL, it is not part of the body —
			// keep the request shape identical for create and update.
			const { id, ...body } = webhookItem

			try {
				const response = isNewWebhook
					? await axios.post(
							generateUrl('/apps/openregister/api/webhooks'),
							body,
						)
					: await axios.put(
							generateUrl(`/apps/openregister/api/webhooks/${id}`),
							body,
						)

				const data = response.data
				this.setWebhookItem(data)
				await this.refreshWebhookList(true)

				return { response, data }
			} catch (error) {
				console.error('Error saving webhook:', error)
				this.error = error.message
				throw error
			} finally {
				this.loading = false
			}
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

			try {
				const response = await axios.delete(
					generateUrl(`/apps/openregister/api/webhooks/${webhookId}`),
				)

				await this.refreshWebhookList(true)
				this.setWebhookItem(null)

				return { response }
			} catch (error) {
				console.error('Error deleting webhook:', error)
				this.error = error.message
				throw error
			}
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

			try {
				const response = await axios.put(
					generateUrl(`/apps/openregister/api/webhooks/${webhook.id}`),
					{ enabled: !webhook.enabled },
				)

				await this.refreshWebhookList(true)

				return { response }
			} catch (error) {
				console.error('Error toggling webhook:', error)
				this.error = error.message
				throw error
			}
		},
	},
})
