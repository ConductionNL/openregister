<template>
	<NcDialog
		v-if="navigationStore.modal === 'editWebhook'"
		:name="
			webhookItem?.id
				? t('openregister', 'Edit Webhook')
				: t('openregister', 'Create Webhook')
		"
		size="large"
		:can-close="true"
		:open="true"
		@update:open="handleDialogClose">
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<div class="tabContainer">
			<AppTabs v-model="activeTab" content-class="mt-3" justified>
				<!-- Settings Tab -->
				<AppTab active>
					<template #title>
						<Cog :size="16" />
						<span>{{ t('openregister', 'Settings') }}</span>
					</template>

					<div class="form-editor">
						<NcTextField
							:label="t('openregister', 'Name') + ' *'"
							:placeholder="t('openregister', 'Enter webhook name')"
							:model-value="webhookItem?.name || ''"
							:error="!webhookItem?.name?.trim?.()"
							@update:modelValue="updateName" />

						<NcTextField
							:label="t('openregister', 'URL') + ' *'"
							:placeholder="
								t('openregister', 'https://example.com/webhook')
							"
							:model-value="webhookItem?.url || ''"
							type="url"
							:error="!webhookItem?.url?.trim?.()"
							@update:modelValue="updateUrl">
							<template #helper-text-message>
								<p>
									{{
										t(
											'openregister',
											'The URL where webhook events will be sent',
										)
									}}
								</p>
							</template>
						</NcTextField>

						<div class="selectField">
							<label class="dialog-label">{{
								t('openregister', 'HTTP Method')
							}}</label>
							<NcSelect
								v-model="selectedMethod"
								input-label="Selected Method"
								:options="httpMethodOptions"
								label="label"
								track-by="value"
								:label-outside="true"
								:placeholder="
									t('openregister', 'Select HTTP method')
								"
								@update:modelValue="updateMethod">
								<template #option="{ label, description }">
									<div class="option-content">
										<span class="option-title">{{ label }}</span>
										<span
											v-if="description"
											class="option-description"
											>{{ description }}</span
										>
									</div>
								</template>
							</NcSelect>
							<p class="field-hint">
								{{
									t(
										'openregister',
										'HTTP method used to send webhook requests',
									)
								}}
							</p>
						</div>

						<div class="checkboxField">
							<NcCheckboxRadioSwitch
								:model-value="webhookItem?.enabled !== false"
								@update:modelValue="updateEnabled">
								{{ t('openregister', 'Enabled') }}
							</NcCheckboxRadioSwitch>
							<p class="field-hint">
								{{
									t(
										'openregister',
										'Enable or disable this webhook',
									)
								}}
							</p>
						</div>
					</div>
				</AppTab>

				<!-- Events Tab -->
				<AppTab>
					<template #title>
						<Webhook :size="16" />
						<span>{{ t('openregister', 'Events') }}</span>
					</template>

					<div class="form-editor">
						<div class="selectField">
							<label class="dialog-label">{{
								t('openregister', 'Event')
							}}</label>
							<NcSelect
								v-model="selectedEvent"
								input-label="Selected Event"
								:options="eventOptions"
								label="label"
								track-by="value"
								:label-outside="true"
								:filterable="true"
								:placeholder="
									t('openregister', 'Select event to listen to...')
								"
								@search-change="searchEvents"
								@update:modelValue="updateEvent">
								<template
									#option="{ label, description, category, type }">
									<div class="option-content">
										<span class="option-title">{{ label }}</span>
										<span
											v-if="description"
											class="option-description"
											>{{ description }}</span
										>
										<span class="option-meta">
											{{ category }} •
											{{
												type === 'before'
													? t('openregister', 'Before')
													: t('openregister', 'After')
											}}
										</span>
									</div>
								</template>
								<template #no-options>
									<span v-if="loadingEvents">{{
										t('openregister', 'Loading events...')
									}}</span>
									<span v-else>{{
										t('openregister', 'No events found')
									}}</span>
								</template>
							</NcSelect>
							<p class="field-hint">
								{{
									t(
										'openregister',
										'Select the event this webhook should listen to',
									)
								}}
							</p>
						</div>

						<div v-if="selectedEvent" class="selectField">
							<label class="dialog-label">{{
								t('openregister', 'Event Property for Payload')
							}}</label>
							<NcSelect
								v-model="selectedEventProperty"
								input-label="Selected Event Property"
								:options="eventPropertyOptions"
								label="label"
								track-by="value"
								:label-outside="true"
								:placeholder="
									t(
										'openregister',
										'Select property to send as payload',
									)
								"
								@update:modelValue="updateEventProperty">
								<template #option="{ label, description }">
									<div class="option-content">
										<span class="option-title">{{ label }}</span>
										<span
											v-if="description"
											class="option-description"
											>{{ description }}</span
										>
									</div>
								</template>
							</NcSelect>
							<p class="field-hint">
								{{
									t(
										'openregister',
										'Select which property from the event should be used as the webhook payload data',
									)
								}}
							</p>
						</div>
					</div>
				</AppTab>

				<!-- Configuration Tab -->
				<AppTab>
					<template #title>
						<Database :size="16" />
						<span>{{ t('openregister', 'Configuration') }}</span>
					</template>

					<div class="form-editor">
						<div class="checkboxField">
							<NcCheckboxRadioSwitch
								:model-value="configuration.sendCloudEvent !== false"
								@update:modelValue="updateSendCloudEvent">
								{{ t('openregister', 'Send as CloudEvent') }}
							</NcCheckboxRadioSwitch>
							<p class="field-hint">
								{{
									t(
										'openregister',
										'Wrap webhook payload in CloudEvents format for better interoperability',
									)
								}}
							</p>
						</div>

						<div class="checkboxField">
							<NcCheckboxRadioSwitch
								:model-value="configuration.waitForResponse === true"
								@update:modelValue="updateWaitForResponse">
								{{ t('openregister', 'Wait for Response') }}
							</NcCheckboxRadioSwitch>
							<p class="field-hint">
								{{
									t(
										'openregister',
										'Wait for webhook response before continuing (required for request/response flows)',
									)
								}}
							</p>
						</div>

						<div class="checkboxField">
							<NcCheckboxRadioSwitch
								:model-value="
									configuration.allowPrivateTargets === true
								"
								@update:modelValue="updateAllowPrivateTargets">
								{{
									t(
										'openregister',
										'Allow private/loopback targets',
									)
								}}
							</NcCheckboxRadioSwitch>
							<p class="field-hint">
								{{
									t(
										'openregister',
										'Disable the SSRF guard for this webhook so it can deliver to private, loopback or link-local addresses (e.g. http://localhost:8000). Only enable this for local testing.',
									)
								}}
							</p>
						</div>

						<div class="selectField">
							<label class="dialog-label">{{
								t('openregister', 'Retry Policy')
							}}</label>
							<NcSelect
								v-model="selectedRetryPolicy"
								input-label="Selected Retry Policy"
								:options="retryPolicyOptions"
								label="label"
								track-by="value"
								:label-outside="true"
								:placeholder="
									t('openregister', 'Select retry policy')
								"
								@update:modelValue="updateRetryPolicy">
								<template #option="{ label, description }">
									<div class="option-content">
										<span class="option-title">{{ label }}</span>
										<span
											v-if="description"
											class="option-description"
											>{{ description }}</span
										>
									</div>
								</template>
							</NcSelect>
							<p class="field-hint">
								{{
									t(
										'openregister',
										'How to handle retries for failed webhook deliveries',
									)
								}}
							</p>
						</div>

						<NcTextField
							:label="t('openregister', 'Max Retries')"
							placeholder="3"
							:model-value="webhookItem?.maxRetries?.toString() || '3'"
							type="number"
							min="0"
							max="10"
							@update:modelValue="updateMaxRetries">
							<template #helper-text-message>
								<p>
									{{
										t(
											'openregister',
											'Maximum number of retry attempts for failed deliveries',
										)
									}}
								</p>
							</template>
						</NcTextField>

						<NcTextField
							:label="t('openregister', 'Timeout (seconds)')"
							placeholder="30"
							:model-value="webhookItem?.timeout?.toString() || '30'"
							type="number"
							min="1"
							max="300"
							@update:modelValue="updateTimeout">
							<template #helper-text-message>
								<p>
									{{
										t(
											'openregister',
											'Request timeout in seconds',
										)
									}}
								</p>
							</template>
						</NcTextField>
					</div>
				</AppTab>

				<!-- Advanced Tab -->
				<AppTab>
					<template #title>
						<Tune :size="16" />
						<span>{{ t('openregister', 'Advanced') }}</span>
					</template>

					<div class="form-editor">
						<NcTextField
							:label="t('openregister', 'Secret')"
							:placeholder="
								t(
									'openregister',
									'Optional webhook secret for signature verification',
								)
							"
							:model-value="webhookItem?.secret || ''"
							type="password"
							@update:modelValue="updateSecret">
							<template #helper-text-message>
								<p>
									{{
										t(
											'openregister',
											'Secret key for HMAC signature generation (optional)',
										)
									}}
								</p>
							</template>
						</NcTextField>

						<div class="selectField">
							<label class="dialog-label">{{
								t('openregister', 'Headers')
							}}</label>
							<NcTextArea
								:model-value="headersText"
								:placeholder="headersPlaceholder"
								rows="4"
								@update:modelValue="updateHeaders" />
							<p class="field-hint">
								{{
									t(
										'openregister',
										'Custom HTTP headers (one per line, format: Header-Name: value)',
									)
								}}
							</p>
						</div>

						<div class="selectField">
							<label class="dialog-label">{{
								t('openregister', 'Filters')
							}}</label>
							<NcTextArea
								:model-value="filtersText"
								:placeholder="filtersPlaceholder"
								rows="4"
								@update:modelValue="updateFilters" />
							<p class="field-hint">
								{{
									t(
										'openregister',
										'Filter webhook triggers by payload properties (one per line, format: key: value)',
									)
								}}
							</p>
						</div>
					</div>
				</AppTab>
			</AppTabs>
		</div>

		<template #actions>
			<NcButton @click="closeModal">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ t('openregister', 'Cancel') }}
			</NcButton>
			<NcButton
				:disabled="loading || !isValid"
				variant="primary"
				@click="saveWebhook">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<ContentSave v-else :size="20" />
				</template>
				{{ t('openregister', 'Save') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { showSuccess } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import { navigationStore, webhookStore } from '../../store/store.js'

import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDialog,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
	NcTextField,
	NcTextArea,
} from '@nextcloud/vue'

import AppTabs from '../../components/tabs/AppTabs.vue'
import AppTab from '../../components/tabs/AppTab.vue'

import Cancel from 'vue-material-design-icons/Cancel.vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import Database from 'vue-material-design-icons/Database.vue'
import Tune from 'vue-material-design-icons/Tune.vue'
import Webhook from 'vue-material-design-icons/Webhook.vue'

export default {
	name: 'EditWebhook',
	components: {
		NcDialog,
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcTextField,
		NcTextArea,
		AppTabs,
		AppTab,
		Cancel,
		ContentSave,
		Cog,
		Database,
		Tune,
		Webhook,
	},
	data() {
		return {
			loading: false,
			error: null,
			activeTab: 0,
			webhookItem: null,
			selectedMethod: null,
			selectedEvent: null,
			selectedEventProperty: null,
			selectedRetryPolicy: null,
			configuration: {
				sendCloudEvent: true,
				waitForResponse: false,
				allowPrivateTargets: false,
				eventProperty: null,
				responseMapping: {},
			},
			availableEvents: [],
			eventOptions: [],
			loadingEvents: false,
			httpMethodOptions: [
				{ value: 'GET', label: 'GET', description: 'HTTP GET method' },
				{
					value: 'POST',
					label: 'POST',
					description: 'Standard HTTP POST method',
				},
				{ value: 'PUT', label: 'PUT', description: 'HTTP PUT method' },
				{ value: 'PATCH', label: 'PATCH', description: 'HTTP PATCH method' },
				{
					value: 'DELETE',
					label: 'DELETE',
					description: 'HTTP DELETE method',
				},
			],
			retryPolicyOptions: [
				{
					value: 'exponential',
					label: t('openregister', 'Exponential'),
					description: t(
						'openregister',
						'Delays double with each attempt (2, 4, 8 minutes...)',
					),
				},
				{
					value: 'linear',
					label: t('openregister', 'Linear'),
					description: t(
						'openregister',
						'Delays increase linearly (5, 10, 15 minutes...)',
					),
				},
				{
					value: 'fixed',
					label: t('openregister', 'Fixed'),
					description: t(
						'openregister',
						'Constant delay between retries (5 minutes)',
					),
				},
			],
		}
	},
	computed: {
		/**
		 * @spec exclude UI accessor — exposes the navigation store to the template.
		 */
		navigationStore() {
			return navigationStore
		},
		isValid() {
			return Boolean(
				this.webhookItem?.name?.trim() && this.webhookItem?.url?.trim(),
			)
		},
		/**
		 * @spec exclude UI display helper — builds event-property select options for the selected event.
		 */
		eventPropertyOptions() {
			if (!this.selectedEvent) {
				return []
			}

			// Get properties from the selected event.
			const event = this.availableEvents.find(
				(e) => e.class === this.selectedEvent,
			)
			if (!event || !event.properties) {
				return []
			}

			return event.properties.map((prop) => ({
				value: prop,
				label: prop,
			}))
		},
		/**
		 * @spec exclude UI display helper — serializes headers object to editable text.
		 */
		headersText() {
			if (
				!this.webhookItem?.headers
				|| typeof this.webhookItem.headers !== 'object'
			) {
				return ''
			}
			return Object.entries(this.webhookItem.headers)
				.map(([key, value]) => `${key}: ${value}`)
				.join('\n')
		},
		// Placeholder strings are defined in script (not in the template attribute
		// expression) because a literal `\n` inside a Vue template expression is
		// compiled into an actual newline character inside a single-quoted JS
		// string in the render function output, producing an "Invalid or
		// unexpected token" SyntaxError that breaks the entire bundle.
		/**
		 * @spec exclude UI display helper — placeholder text for the headers field.
		 */
		headersPlaceholder() {
			return 'X-Custom-Header: value\nAuthorization: Bearer token'
		},
		/**
		 * @spec exclude UI display helper — placeholder text for the filters field.
		 */
		filtersPlaceholder() {
			return this.t('openregister', 'objectType: object\naction: created')
		},
		/**
		 * @spec exclude UI display helper — serializes filters object to editable text.
		 */
		filtersText() {
			if (
				!this.webhookItem?.filters
				|| typeof this.webhookItem.filters !== 'object'
			) {
				return ''
			}
			return Object.entries(this.webhookItem.filters)
				.map(([key, value]) => {
					if (Array.isArray(value)) {
						return `${key}: ${value.join(', ')}`
					}
					return `${key}: ${value}`
				})
				.join('\n')
		},
	},
	watch: {
		'navigationStore.modal'(newVal) {
			if (newVal === 'editWebhook') {
				// Modal opened, initialize webhook.
				this.initializeWebhook()
			}
		},
	},
	/**
	 * @spec exclude Vue lifecycle hook — loads events and initializes the webhook form.
	 */
	async created() {
		await this.loadAvailableEvents()
		this.initializeWebhook()
	},
	methods: {
		/**
		 * @spec openspec/specs/entity-management-modals/spec.md
		 */
		initializeWebhook() {
			// Get webhook item from navigation store transferData or initialize new one.
			const transferData = navigationStore.getTransferData()
			if (transferData && transferData.webhook) {
				this.webhookItem = { ...transferData.webhook }
				this.loadExistingSelections()
			} else {
				this.webhookItem = {
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
					configuration: {
						sendCloudEvent: true,
						waitForResponse: false,
						allowPrivateTargets: false,
						eventProperty: null,
						responseMapping: {},
					},
				}
				// Default to POST (find it in options since GET is now first).
				this.selectedMethod =
					this.httpMethodOptions.find((m) => m.value === 'POST')
					|| this.httpMethodOptions[0]
				this.selectedRetryPolicy = this.retryPolicyOptions[0] // 'exponential'
				this.selectedEvent = null
				this.selectedEventProperty = null
			}
		},
		/**
		 * @param value
		 * @spec exclude Form-field binding — sets the webhook name.
		 */
		updateName(value) {
			if (!this.webhookItem) {
				this.webhookItem = {}
			}
			this.webhookItem.name = value
		},
		/**
		 * @param value
		 * @spec exclude Form-field binding — sets the webhook URL.
		 */
		updateUrl(value) {
			if (!this.webhookItem) {
				this.webhookItem = {}
			}
			this.webhookItem.url = value
		},
		/**
		 * @param value
		 * @spec exclude Form-field binding — sets the HTTP method.
		 */
		updateMethod(value) {
			if (!this.webhookItem) {
				this.webhookItem = {}
			}
			this.webhookItem.method = value ? value.value : 'POST'
			this.selectedMethod = value
		},
		/**
		 * @param value
		 * @spec exclude Form-field binding — sets the enabled flag.
		 */
		updateEnabled(value) {
			if (!this.webhookItem) {
				this.webhookItem = {}
			}
			this.webhookItem.enabled = value
		},
		/**
		 * @param value
		 * @spec exclude Form-field binding — sets the subscribed event and resets event property.
		 */
		updateEvent(value) {
			if (!this.webhookItem) {
				this.webhookItem = {}
			}
			// Store as array with single event for backend compatibility.
			const eventClass = value ? value.value || value : null
			this.webhookItem.events = eventClass ? [eventClass] : []
			this.selectedEvent = eventClass
			// Reset event property when event changes.
			if (eventClass) {
				this.selectedEventProperty = null
				if (!this.webhookItem.configuration) {
					this.webhookItem.configuration = {}
				}
				this.webhookItem.configuration.eventProperty = null
			} else {
				this.selectedEvent = null
			}
		},
		/**
		 * @param value
		 * @spec exclude Form-field binding — sets the selected event property.
		 */
		updateEventProperty(value) {
			if (!this.webhookItem) {
				this.webhookItem = {}
			}
			if (!this.webhookItem.configuration) {
				this.webhookItem.configuration = {}
			}
			this.webhookItem.configuration.eventProperty = value ? value.value : null
			this.selectedEventProperty = value
		},
		/**
		 * @param value
		 * @spec exclude Form-field binding — sets the sendCloudEvent configuration flag.
		 */
		updateSendCloudEvent(value) {
			if (!this.webhookItem) {
				this.webhookItem = {}
			}
			if (!this.webhookItem.configuration) {
				this.webhookItem.configuration = {}
			}
			this.configuration.sendCloudEvent = value
			this.webhookItem.configuration.sendCloudEvent = value
		},
		/**
		 * @param value
		 * @spec exclude Form-field binding — sets the waitForResponse configuration flag.
		 */
		updateWaitForResponse(value) {
			if (!this.webhookItem) {
				this.webhookItem = {}
			}
			if (!this.webhookItem.configuration) {
				this.webhookItem.configuration = {}
			}
			this.configuration.waitForResponse = value
			this.webhookItem.configuration.waitForResponse = value
		},
		/**
		 * @param value
		 * @spec exclude Form-field binding — sets the allowPrivateTargets configuration flag.
		 */
		updateAllowPrivateTargets(value) {
			if (!this.webhookItem) {
				this.webhookItem = {}
			}
			if (!this.webhookItem.configuration) {
				this.webhookItem.configuration = {}
			}
			this.configuration.allowPrivateTargets = value
			this.webhookItem.configuration.allowPrivateTargets = value
		},
		/**
		 * @param value
		 * @spec exclude Form-field binding — sets the retry policy.
		 */
		updateRetryPolicy(value) {
			if (!this.webhookItem) {
				this.webhookItem = {}
			}
			this.webhookItem.retryPolicy = value ? value.value : 'exponential'
			this.selectedRetryPolicy = value
		},
		/**
		 * @param value
		 * @spec exclude Form-field binding — sets the max-retries count.
		 */
		updateMaxRetries(value) {
			if (!this.webhookItem) {
				this.webhookItem = {}
			}
			this.webhookItem.maxRetries = parseInt(value) || 3
		},
		/**
		 * @param value
		 * @spec exclude Form-field binding — sets the request timeout.
		 */
		updateTimeout(value) {
			if (!this.webhookItem) {
				this.webhookItem = {}
			}
			this.webhookItem.timeout = parseInt(value) || 30
		},
		/**
		 * @param value
		 * @spec exclude Form-field binding — sets the webhook secret.
		 */
		updateSecret(value) {
			if (!this.webhookItem) {
				this.webhookItem = {}
			}
			this.webhookItem.secret = value || null
		},
		/**
		 * @param value
		 * @spec exclude Form-field binding — parses header text into a headers object.
		 */
		updateHeaders(value) {
			if (!this.webhookItem) {
				this.webhookItem = {}
			}
			const headers = {}
			if (value && value.trim()) {
				value.split('\n').forEach((line) => {
					const [key, ...valueParts] = line.split(':')
					if (key && valueParts.length > 0) {
						headers[key.trim()] = valueParts.join(':').trim()
					}
				})
			}
			this.webhookItem.headers = headers
		},
		/**
		 * @param value
		 * @spec exclude Form-field binding — parses filter text into a filters object.
		 */
		updateFilters(value) {
			if (!this.webhookItem) {
				this.webhookItem = {}
			}
			const filters = {}
			if (value && value.trim()) {
				value.split('\n').forEach((line) => {
					const [key, ...valueParts] = line.split(':')
					if (key && valueParts.length > 0) {
						const val = valueParts.join(':').trim()
						// Support comma-separated values for arrays.
						if (val.includes(',')) {
							filters[key.trim()] = val.split(',').map((v) => v.trim())
						} else {
							filters[key.trim()] = val
						}
					}
				})
			}
			this.webhookItem.filters = filters
		},
		/**
		 * @spec exclude Modal data-load plumbing — fetches subscribable webhook events.
		 */
		async loadAvailableEvents() {
			this.loadingEvents = true
			try {
				const response = await axios.get(
					generateUrl('/apps/openregister/api/webhooks/events'),
				)

				if (response.data.events) {
					this.availableEvents = response.data.events
					this.eventOptions = response.data.events.map((event) => ({
						value: event.class,
						label: `${event.name} (${event.category})`,
						description: event.description,
						category: event.category,
						type: event.type,
						properties: event.properties,
					}))
				}
			} catch (error) {
				console.error('Failed to load available events:', error)
			} finally {
				this.loadingEvents = false
			}
		},
		/**
		 * @param _query
		 * @spec exclude UI event handler — no-op search hook (NcSelect filters internally).
		 */
		searchEvents(_query) {
			// Filter events based on search query.
			// The NcSelect component handles filtering internally.
			// Empty query is handled by the component itself.
		},
		/**
		 * @spec exclude Modal hydration plumbing — maps stored webhook values onto select inputs.
		 */
		loadExistingSelections() {
			const item = this.webhookItem
			if (item) {
				// Load method.
				if (item.method) {
					this.selectedMethod =
						this.httpMethodOptions.find((m) => m.value === item.method)
						|| this.httpMethodOptions.find((m) => m.value === 'POST')
						|| this.httpMethodOptions[0]
				}

				// Load retry policy.
				if (item.retryPolicy) {
					this.selectedRetryPolicy =
						this.retryPolicyOptions.find(
							(p) => p.value === item.retryPolicy,
						) || this.retryPolicyOptions[0]
				}

				// Load event (take first event if multiple exist for backward compatibility).
				if (
					item.events
					&& Array.isArray(item.events)
					&& item.events.length > 0
				) {
					const eventClass = item.events[0]
					this.selectedEvent = eventClass
				}

				// Load configuration.
				if (item.configuration) {
					this.configuration = { ...item.configuration }
					if (item.configuration.eventProperty) {
						this.selectedEventProperty = {
							value: item.configuration.eventProperty,
							label: item.configuration.eventProperty,
						}
					}
				}
			}
		},
		/**
		 * @spec exclude UI event handler — closes the modal on dialog dismiss.
		 */
		handleDialogClose() {
			this.closeModal()
		},
		/**
		 * @spec exclude Modal close plumbing — resets the webhook form and closes the modal.
		 */
		closeModal() {
			navigationStore.setModal(false)
			this.loading = false
			this.error = null
			this.webhookItem = null
			this.selectedMethod = null
			this.selectedEvent = null
			this.selectedEventProperty = null
			this.selectedRetryPolicy = null
			this.configuration = {
				sendCloudEvent: true,
				waitForResponse: false,
				allowPrivateTargets: false,
				eventProperty: null,
				responseMapping: {},
			}
		},
		/**
		 * @spec exclude Modal save plumbing — assembles the payload and persists the webhook.
		 */
		async saveWebhook() {
			this.loading = true
			this.error = null

			try {
				const payload = {
					name: this.webhookItem.name,
					url: this.webhookItem.url,
					method: this.webhookItem.method,
					enabled: this.webhookItem.enabled !== false,
					events: this.selectedEvent ? [this.selectedEvent] : [],
					maxRetries: this.webhookItem.maxRetries || 3,
					timeout: this.webhookItem.timeout || 30,
					retryPolicy: this.webhookItem.retryPolicy || 'exponential',
					secret: this.webhookItem.secret || null,
					headers: this.webhookItem.headers || {},
					filters: this.webhookItem.filters || {},
					configuration: this.webhookItem.configuration || {},
				}

				const isUpdate = Boolean(this.webhookItem.id)
				if (isUpdate) {
					payload.id = this.webhookItem.id
				}

				// The store refreshes webhookStore.webhookList after persisting, so
				// every mounted view picks the change up reactively — no page reload.
				const { data } = await webhookStore.saveWebhook(payload)

				if (data) {
					showSuccess(
						isUpdate
							? t('openregister', 'Webhook updated successfully')
							: t('openregister', 'Webhook created successfully'),
					)
					this.closeModal()
				}
			} catch (error) {
				console.error('Failed to save webhook:', error)
				this.error =
					error.response?.data?.error
					|| t('openregister', 'Failed to save webhook')
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.tabContainer {
	width: 100%;
}

.form-editor {
	display: flex;
	flex-direction: column;
	gap: 1rem;
	padding: 1rem 0;
}

.selectField {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.selectField label {
	font-weight: 500;
	color: var(--color-text-maxcontrast);
}

.checkboxField {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.field-hint {
	font-size: 0.875rem;
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.option-content {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
}

.option-title {
	font-weight: 500;
}

.option-description {
	font-size: 0.875rem;
	color: var(--color-text-maxcontrast);
	max-width: 100%;
	white-space: normal;
	word-break: break-word;
}

.option-meta {
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
</style>

<style>
/* Tab styling - must be unscoped to affect Bootstrap Vue components */
.nav-tabs .nav-link {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 0.5rem;
}

.nav-tabs .nav-link span {
	display: inline-flex;
	align-items: center;
}
</style>
