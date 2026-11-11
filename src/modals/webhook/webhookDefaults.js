/**
 * Defaults of the EditWebhook form, as fresh objects so no two forms share one.
 *
 * @spec exclude Client-side form defaults.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { t } from '@nextcloud/l10n'

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

/**
 * The form's empty select choices, before a webhook is loaded into it.
 *
 * @return {object} The unselected choices.
 */
export function emptyWebhookSelections() {
	return {
		selectedMethod: null,
		selectedEvent: null,
		selectedEventProperty: null,
		selectedRetryPolicy: null,
		selectedMapping: null,
	}
}

/**
 * The form's idle mapping and preview state: no preview, nothing loading.
 *
 * @return {object} The idle state.
 */
export function idleMappingPreview() {
	return {
		mappingOptions: [],
		loadingMappings: false,
		preview: null,
		previewError: null,
		previewing: false,
	}
}

/**
 * The form's empty event catalogue, before the events are fetched.
 *
 * @return {object} The empty catalogue.
 */
export function emptyEventCatalogue() {
	return {
		availableEvents: [],
		eventOptions: [],
		loadingEvents: false,
	}
}

/**
 * One HTTP method choice, labelled by the method itself.
 *
 * @param {string} method The HTTP method
 * @param {string} description What the method does
 * @return {object} The option
 */
function httpMethodOption(method, description) {
	return { value: method, label: method, description }
}

/**
 * The HTTP methods a webhook can call its target with.
 *
 * @return {Array<object>} The method options.
 */
export function httpMethodOptions() {
	return [
		httpMethodOption('GET', 'HTTP GET method'),
		httpMethodOption('POST', 'Standard HTTP POST method'),
		httpMethodOption('PUT', 'HTTP PUT method'),
		httpMethodOption('PATCH', 'HTTP PATCH method'),
		httpMethodOption('DELETE', 'HTTP DELETE method'),
	]
}

/**
 * The retry policies, keyed by value, with their translated labels.
 *
 * @return {object} Label per policy.
 */
function retryPolicyLabels() {
	return {
		exponential: t('openregister', 'Exponential'),
		linear: t('openregister', 'Linear'),
		fixed: t('openregister', 'Fixed'),
	}
}

/**
 * The retry policies, keyed by value, with their translated descriptions.
 *
 * @return {object} Description per policy.
 */
function retryPolicyDescriptions() {
	return {
		exponential: t(
			'openregister',
			'Delays double with each attempt (2, 4, 8 minutes...)',
		),
		linear: t('openregister', 'Delays increase linearly (5, 10, 15 minutes...)'),
		fixed: t('openregister', 'Constant delay between retries (5 minutes)'),
	}
}

/**
 * The retry policies a failed delivery can follow, exponential first.
 *
 * @return {Array<object>} The policy options.
 */
export function retryPolicyOptions() {
	const labels = retryPolicyLabels()
	const descriptions = retryPolicyDescriptions()
	return Object.keys(labels).map((value) => ({
		value,
		label: labels[value],
		description: descriptions[value],
	}))
}
