/**
 * Defaults of the EditWebhook form, as fresh objects so no two forms share one.
 *
 * @spec exclude Client-side form defaults.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

/**
 * The configuration a new or reset webhook form starts with.
 *
 * @return {object} A fresh configuration.
 */
export function defaultWebhookConfiguration() {
	return {
		sendCloudEvent: true,
		waitForResponse: false,
		allowPrivateTargets: false,
		eventProperty: null,
		responseMapping: {},
	}
}

/**
 * The webhook a new form starts with.
 *
 * @return {object} A fresh webhook item.
 */
export function defaultWebhookItem() {
	return {
		name: '',
		url: '',
		method: 'POST',
		enabled: true,
		events: [],
		maxRetries: 3,
		timeout: 30,
		retryPolicy: 'exponential',
		secret: null,
		headers: {},
		filters: {},
		configuration: defaultWebhookConfiguration(),
	}
}
