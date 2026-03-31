/* eslint-disable no-console */
// @ts-expect-error — createCrudStore is JS-only; types will follow later
import { createCrudStore } from '@conduction/nextcloud-vue'

export const useEndpointStore = createCrudStore('endpoint', {
	endpoint: 'endpoints',
	features: { viewMode: true },
	extend: {
		actions: {
			/**
			 * Test an endpoint by executing it
			 * @param {object} item - The endpoint item to test
			 * @param {object} testData - Optional test data to send
			 * @return {Promise} Promise with test results
			 */
			async testEndpoint(item, testData = {}) {
				if (!item.id) {
					throw new Error('Endpoint ID is required for testing')
				}
				const response = await fetch(`${this._options.baseApiUrl}/${item.id}/test`, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify({ data: testData }),
				})
				const data = await response.json()
				if (!response.ok) {
					throw new Error(data.error || data.message || 'Failed to test endpoint')
				}
				return data
			},
		},
	},
})
