/**
 * Turns a stored webhook into the edit form's selections, and the form back
 * into the payload the webhook store saves.
 *
 * @spec exclude UI plumbing, mapping between a stored webhook and the edit form
 */

/**
 * The option whose value is the given value.
 *
 * @param {Array<object>} options The select options.
 * @param {string|number|null|undefined} value The value to find.
 * @return {object|undefined} The option, or undefined.
 *
 * @spec exclude UI plumbing, looks up a select option by value
 */
export function findOption(options, value) {
	return options.find((option) => option.value === value)
}

/** The delivery settings of a webhook, each with the value used when the form leaves it empty. */
const DELIVERY_DEFAULTS = [
	['maxRetries', () => 3],
	['timeout', () => 30],
	['retryPolicy', () => 'exponential'],
	['secret', () => null],
	['headers', () => ({})],
	['filters', () => ({})],
	['configuration', () => ({})],
]

/**
 * The delivery settings of an edited webhook, defaults filling the empty ones.
 *
 * @param {object} item The edited webhook.
 * @return {object} The settings, in payload order.
 */
function deliverySettings(item) {
	return Object.fromEntries(
		DELIVERY_DEFAULTS.map(([field, fallback]) => [
			field,
			item[field] || fallback(),
		]),
	)
}

/**
 * The payload that saves the edited webhook: the stored defaults fill what the
 * form leaves empty, the selected event is the only event, and an existing
 * webhook keeps its id.
 *
 * @param {object} item The edited webhook.
 * @param {string|null} selectedEvent The selected event class.
 * @return {object} The payload.
 *
 * @spec exclude UI plumbing, assembles the save payload from the edit form
 */
export function webhookPayload(item, selectedEvent) {
	const payload = {
		name: item.name,
		url: item.url,
		method: item.method,
		enabled: item.enabled !== false,
		events: selectedEvent ? [selectedEvent] : [],
		...deliverySettings(item),
		mapping: item.mapping ?? null,
	}
	if (item.id) {
		payload.id = item.id
	}
	return payload
}

/**
 * The POST option, else the first one.
 *
 * @param {Array<object>} methods The HTTP method options.
 * @return {object|undefined} The default method option.
 *
 * @spec exclude UI plumbing, the method a new or unmatched webhook shows
 */
export function defaultMethodOption(methods) {
	return findOption(methods, 'POST') || methods[0]
}

/**
 * The method and retry policy selections of a stored webhook, for each one it sets.
 *
 * @param {object} item The stored webhook.
 * @param {object} options The `methods` and `retryPolicies` select options.
 * @return {object} selectedMethod and selectedRetryPolicy, where set.
 */
function deliverySelections(item, options) {
	const selections = {}
	if (item.method) {
		selections.selectedMethod =
			findOption(options.methods, item.method)
			|| defaultMethodOption(options.methods)
	}
	if (item.retryPolicy) {
		selections.selectedRetryPolicy =
			findOption(options.retryPolicies, item.retryPolicy)
			|| options.retryPolicies[0]
	}
	return selections
}

/**
 * The event and configuration selections of a stored webhook, for each one it sets.
 *
 * @param {object} item The stored webhook.
 * @return {object} selectedEvent, configuration and selectedEventProperty, where set.
 */
function eventSelections(item) {
	const selections = {}
	if (Array.isArray(item.events) && item.events.length > 0) {
		selections.selectedEvent = item.events[0]
	}
	const configuration = item.configuration
	if (configuration) {
		selections.configuration = { ...configuration }
		if (configuration.eventProperty) {
			selections.selectedEventProperty = {
				value: configuration.eventProperty,
				label: configuration.eventProperty,
			}
		}
	}
	return selections
}

/**
 * The edit form's selections for a stored webhook. Only the selections the
 * webhook sets are returned, so the others keep their current value.
 *
 * @param {object} item The stored webhook.
 * @param {object} options The `mappings`, `methods` and `retryPolicies` select options.
 * @return {object} The selections, by form field.
 *
 * @spec exclude UI plumbing, maps a stored webhook onto the edit form
 */
export function storedSelections(item, options) {
	return {
		selectedMapping: findOption(options.mappings, item.mapping) || null,
		...deliverySelections(item, options),
		...eventSelections(item),
	}
}
