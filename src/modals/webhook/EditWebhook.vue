<script setup>
import { translate as t } from '@nextcloud/l10n'
import { navigationStore } from '../../store/store.js'
</script>

<template>
	<CnTabbedFormDialog
		ref="dialog"
		:tabs="dialogTabs"
		:item="webhookItem?.id ? webhookItem : null"
		entity-name="Webhook"
		:show-create-another="false"
		:disable-save="!isValid"
		@confirm="saveWebhook"
		@close="closeModal"
		@reset="resetForm">
		<!-- Settings Tab -->
		<template #tab-settings="{ loading: dialogLoading }">
			<NcTextField
				:disabled="dialogLoading"
				:label="t('openregister', 'Name') + ' *'"
				:placeholder="t('openregister', 'Enter webhook name')"
				:value="webhookItem?.name || ''"
				:error="!webhookItem?.name?.trim?.()"
				@update:value="updateName" />

			<NcTextField
				:disabled="dialogLoading"
				:label="t('openregister', 'URL') + ' *'"
				:placeholder="t('openregister', 'https://example.com/webhook')"
				:value="webhookItem?.url || ''"
				type="url"
				:error="!webhookItem?.url?.trim?.()"
				@update:value="updateUrl">
				<template #helper-text-message>
					<p>{{ t('openregister', 'The URL where webhook events will be sent') }}</p>
				</template>
			</NcTextField>

			<div class="selectField">
				<label>{{ t('openregister', 'HTTP Method') }}</label>
				<NcSelect
					v-model="selectedMethod"
					:disabled="dialogLoading"
					:options="httpMethodOptions"
					label="label"
					track-by="value"
					:label-outside="true"
					:placeholder="t('openregister', 'Select HTTP method')"
					@input="updateMethod">
					<template #option="{ label, description }">
						<div class="option-content">
							<span class="option-title">{{ label }}</span>
							<span v-if="description" class="option-description">{{ description }}</span>
						</div>
					</template>
				</NcSelect>
				<p class="field-hint">
					{{ t('openregister', 'HTTP method used to send webhook requests') }}
				</p>
			</div>

			<div class="checkboxField">
				<NcCheckboxRadioSwitch
					:disabled="dialogLoading"
					:checked="webhookItem?.enabled !== false"
					@update:checked="updateEnabled">
					{{ t('openregister', 'Enabled') }}
				</NcCheckboxRadioSwitch>
				<p class="field-hint">
					{{ t('openregister', 'Enable or disable this webhook') }}
				</p>
			</div>
		</template>

		<!-- Events Tab -->
		<template #tab-events="{ loading: dialogLoading }">
			<div class="selectField">
				<label>{{ t('openregister', 'Event') }}</label>
				<NcSelect
					v-model="selectedEvent"
					:disabled="dialogLoading"
					:options="eventOptions"
					label="label"
					track-by="value"
					:label-outside="true"
					:filterable="true"
					:placeholder="t('openregister', 'Select event to listen to...')"
					@search-change="searchEvents"
					@input="updateEvent">
					<template #option="{ label, description, category, type }">
						<div class="option-content">
							<span class="option-title">{{ label }}</span>
							<span v-if="description" class="option-description">{{ description }}</span>
							<span class="option-meta">
								{{ category }} • {{ type === 'before' ? t('openregister', 'Before') : t('openregister', 'After') }}
							</span>
						</div>
					</template>
					<template #no-options>
						<span v-if="loadingEvents">{{ t('openregister', 'Loading events...') }}</span>
						<span v-else>{{ t('openregister', 'No events found') }}</span>
					</template>
				</NcSelect>
				<p class="field-hint">
					{{ t('openregister', 'Select the event this webhook should listen to') }}
				</p>
			</div>

			<div v-if="selectedEvent" class="selectField">
				<label>{{ t('openregister', 'Event Property for Payload') }}</label>
				<NcSelect
					v-model="selectedEventProperty"
					:disabled="dialogLoading"
					:options="eventPropertyOptions"
					label="label"
					track-by="value"
					:label-outside="true"
					:placeholder="t('openregister', 'Select property to send as payload')"
					@input="updateEventProperty">
					<template #option="{ label, description }">
						<div class="option-content">
							<span class="option-title">{{ label }}</span>
							<span v-if="description" class="option-description">{{ description }}</span>
						</div>
					</template>
				</NcSelect>
				<p class="field-hint">
					{{ t('openregister', 'Select which property from the event should be used as the webhook payload data') }}
				</p>
			</div>
		</template>

		<!-- Configuration Tab -->
		<template #tab-configuration="{ loading: dialogLoading }">
			<div class="checkboxField">
				<NcCheckboxRadioSwitch
					:disabled="dialogLoading"
					:checked="configuration.sendCloudEvent !== false"
					@update:checked="updateSendCloudEvent">
					{{ t('openregister', 'Send as CloudEvent') }}
				</NcCheckboxRadioSwitch>
				<p class="field-hint">
					{{ t('openregister', 'Wrap webhook payload in CloudEvents format for better interoperability') }}
				</p>
			</div>

			<div class="checkboxField">
				<NcCheckboxRadioSwitch
					:disabled="dialogLoading"
					:checked="configuration.waitForResponse === true"
					@update:checked="updateWaitForResponse">
					{{ t('openregister', 'Wait for Response') }}
				</NcCheckboxRadioSwitch>
				<p class="field-hint">
					{{ t('openregister', 'Wait for webhook response before continuing (required for request/response flows)') }}
				</p>
			</div>

			<div class="selectField">
				<label>{{ t('openregister', 'Retry Policy') }}</label>
				<NcSelect
					v-model="selectedRetryPolicy"
					:disabled="dialogLoading"
					:options="retryPolicyOptions"
					label="label"
					track-by="value"
					:label-outside="true"
					:placeholder="t('openregister', 'Select retry policy')"
					@input="updateRetryPolicy">
					<template #option="{ label, description }">
						<div class="option-content">
							<span class="option-title">{{ label }}</span>
							<span v-if="description" class="option-description">{{ description }}</span>
						</div>
					</template>
				</NcSelect>
				<p class="field-hint">
					{{ t('openregister', 'How to handle retries for failed webhook deliveries') }}
				</p>
			</div>

			<NcTextField
				:disabled="dialogLoading"
				:label="t('openregister', 'Max Retries')"
				:placeholder="t('openregister', '3')"
				:value="webhookItem?.maxRetries?.toString() || '3'"
				type="number"
				min="0"
				max="10"
				@update:value="updateMaxRetries">
				<template #helper-text-message>
					<p>{{ t('openregister', 'Maximum number of retry attempts for failed deliveries') }}</p>
				</template>
			</NcTextField>

			<NcTextField
				:disabled="dialogLoading"
				:label="t('openregister', 'Timeout (seconds)')"
				:placeholder="t('openregister', '30')"
				:value="webhookItem?.timeout?.toString() || '30'"
				type="number"
				min="1"
				max="300"
				@update:value="updateTimeout">
				<template #helper-text-message>
					<p>{{ t('openregister', 'Request timeout in seconds') }}</p>
				</template>
			</NcTextField>
		</template>

		<!-- Advanced Tab -->
		<template #tab-advanced="{ loading: dialogLoading }">
			<NcTextField
				:disabled="dialogLoading"
				:label="t('openregister', 'Secret')"
				:placeholder="t('openregister', 'Optional webhook secret for signature verification')"
				:value="webhookItem?.secret || ''"
				type="password"
				@update:value="updateSecret">
				<template #helper-text-message>
					<p>{{ t('openregister', 'Secret key for HMAC signature generation (optional)') }}</p>
				</template>
			</NcTextField>

			<div class="selectField">
				<label>{{ t('openregister', 'Headers') }}</label>
				<NcTextArea
					:disabled="dialogLoading"
					:value="headersText"
					:placeholder="t('openregister', 'X-Custom-Header: value\nAuthorization: Bearer token')"
					rows="4"
					@update:value="updateHeaders" />
				<p class="field-hint">
					{{ t('openregister', 'Custom HTTP headers (one per line, format: Header-Name: value)') }}
				</p>
			</div>

			<div class="selectField">
				<label>{{ t('openregister', 'Filters') }}</label>
				<NcTextArea
					:disabled="dialogLoading"
					:value="filtersText"
					:placeholder="t('openregister', 'objectType: object\naction: created')"
					rows="4"
					@update:value="updateFilters" />
				<p class="field-hint">
					{{ t('openregister', 'Filter webhook triggers by payload properties (one per line, format: key: value)') }}
				</p>
			</div>
		</template>
	</CnTabbedFormDialog>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

import {
	CnTabbedFormDialog,
} from '@conduction/nextcloud-vue'

import {
	NcCheckboxRadioSwitch,
	NcSelect,
	NcTextField,
	NcTextArea,
} from '@nextcloud/vue'

import Cog from 'vue-material-design-icons/Cog.vue'
import Database from 'vue-material-design-icons/Database.vue'
import Tune from 'vue-material-design-icons/Tune.vue'
import Webhook from 'vue-material-design-icons/Webhook.vue'

export default {
	name: 'EditWebhook',
	components: {
		CnTabbedFormDialog,
		NcCheckboxRadioSwitch,
		NcSelect,
		NcTextField,
		NcTextArea,
	},
	data() {
		return {
			webhookItem: null,
			selectedMethod: null,
			selectedEvent: null,
			selectedEventProperty: null,
			selectedRetryPolicy: null,
			configuration: {
				sendCloudEvent: true,
				waitForResponse: false,
				eventProperty: null,
				responseMapping: {},
			},
			availableEvents: [],
			eventOptions: [],
			loadingEvents: false,
			httpMethodOptions: [
				{ value: 'GET', label: 'GET', description: 'HTTP GET method' },
				{ value: 'POST', label: 'POST', description: 'Standard HTTP POST method' },
				{ value: 'PUT', label: 'PUT', description: 'HTTP PUT method' },
				{ value: 'PATCH', label: 'PATCH', description: 'HTTP PATCH method' },
				{ value: 'DELETE', label: 'DELETE', description: 'HTTP DELETE method' },
			],
			retryPolicyOptions: [
				{ value: 'exponential', label: t('openregister', 'Exponential'), description: t('openregister', 'Delays double with each attempt (2, 4, 8 minutes...)') },
				{ value: 'linear', label: t('openregister', 'Linear'), description: t('openregister', 'Delays increase linearly (5, 10, 15 minutes...)') },
				{ value: 'fixed', label: t('openregister', 'Fixed'), description: t('openregister', 'Constant delay between retries (5 minutes)') },
			],
		}
	},
	computed: {
		navigationStore() {
			return navigationStore
		},
		isValid() {
			return Boolean(this.webhookItem?.name?.trim() && this.webhookItem?.url?.trim())
		},
		dialogTabs() {
			return [
				{ id: 'settings', title: t('openregister', 'Settings'), icon: Cog },
				{ id: 'events', title: t('openregister', 'Events'), icon: Webhook },
				{ id: 'configuration', title: t('openregister', 'Configuration'), icon: Database },
				{ id: 'advanced', title: t('openregister', 'Advanced'), icon: Tune },
			]
		},
		eventPropertyOptions() {
			if (!this.selectedEvent) {
				return []
			}

			const event = this.availableEvents.find(e => e.class === this.selectedEvent)
			if (!event || !event.properties) {
				return []
			}

			return event.properties.map(prop => ({
				value: prop,
				label: prop,
			}))
		},
		headersText() {
			if (!this.webhookItem?.headers || typeof this.webhookItem.headers !== 'object') {
				return ''
			}
			return Object.entries(this.webhookItem.headers)
				.map(([key, value]) => `${key}: ${value}`)
				.join('\n')
		},
		filtersText() {
			if (!this.webhookItem?.filters || typeof this.webhookItem.filters !== 'object') {
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
	async created() {
		await this.loadAvailableEvents()
		this.initializeWebhook()
	},
	methods: {
		initializeWebhook() {
			const transferData = navigationStore.getTransferData()
			if (transferData && transferData.webhook) {
				this.webhookItem = { ...transferData.webhook }
				this.loadExistingSelections()
			} else {
				this.resetForm()
			}
		},
		resetForm() {
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
					eventProperty: null,
					responseMapping: {},
				},
			}
			this.selectedMethod = this.httpMethodOptions.find(m => m.value === 'POST') || this.httpMethodOptions[0]
			this.selectedRetryPolicy = this.retryPolicyOptions[0]
			this.selectedEvent = null
			this.selectedEventProperty = null
			this.configuration = {
				sendCloudEvent: true,
				waitForResponse: false,
				eventProperty: null,
				responseMapping: {},
			}
		},
		updateName(value) {
			if (!this.webhookItem) {
				this.webhookItem = {}
			}
			this.webhookItem.name = value
		},
		updateUrl(value) {
			if (!this.webhookItem) {
				this.webhookItem = {}
			}
			this.webhookItem.url = value
		},
		updateMethod(value) {
			if (!this.webhookItem) {
				this.webhookItem = {}
			}
			this.webhookItem.method = value ? value.value : 'POST'
			this.selectedMethod = value
		},
		updateEnabled(value) {
			if (!this.webhookItem) {
				this.webhookItem = {}
			}
			this.webhookItem.enabled = value
		},
		updateEvent(value) {
			if (!this.webhookItem) {
				this.webhookItem = {}
			}
			const eventClass = value ? (value.value || value) : null
			this.webhookItem.events = eventClass ? [eventClass] : []
			this.selectedEvent = eventClass
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
		updateRetryPolicy(value) {
			if (!this.webhookItem) {
				this.webhookItem = {}
			}
			this.webhookItem.retryPolicy = value ? value.value : 'exponential'
			this.selectedRetryPolicy = value
		},
		updateMaxRetries(value) {
			if (!this.webhookItem) {
				this.webhookItem = {}
			}
			this.webhookItem.maxRetries = parseInt(value) || 3
		},
		updateTimeout(value) {
			if (!this.webhookItem) {
				this.webhookItem = {}
			}
			this.webhookItem.timeout = parseInt(value) || 30
		},
		updateSecret(value) {
			if (!this.webhookItem) {
				this.webhookItem = {}
			}
			this.webhookItem.secret = value || null
		},
		updateHeaders(value) {
			if (!this.webhookItem) {
				this.webhookItem = {}
			}
			const headers = {}
			if (value && value.trim()) {
				value.split('\n').forEach(line => {
					const [key, ...valueParts] = line.split(':')
					if (key && valueParts.length > 0) {
						headers[key.trim()] = valueParts.join(':').trim()
					}
				})
			}
			this.webhookItem.headers = headers
		},
		updateFilters(value) {
			if (!this.webhookItem) {
				this.webhookItem = {}
			}
			const filters = {}
			if (value && value.trim()) {
				value.split('\n').forEach(line => {
					const [key, ...valueParts] = line.split(':')
					if (key && valueParts.length > 0) {
						const val = valueParts.join(':').trim()
						if (val.includes(',')) {
							filters[key.trim()] = val.split(',').map(v => v.trim())
						} else {
							filters[key.trim()] = val
						}
					}
				})
			}
			this.webhookItem.filters = filters
		},
		async loadAvailableEvents() {
			this.loadingEvents = true
			try {
				const response = await axios.get(
					generateUrl('/apps/openregister/api/webhooks/events'),
				)

				if (response.data.events) {
					this.availableEvents = response.data.events
					this.eventOptions = response.data.events.map(event => ({
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
		searchEvents(_query) {
			// NcSelect handles filtering internally.
		},
		loadExistingSelections() {
			const item = this.webhookItem
			if (item) {
				if (item.method) {
					this.selectedMethod = this.httpMethodOptions.find(
						m => m.value === item.method,
					) || this.httpMethodOptions.find(m => m.value === 'POST') || this.httpMethodOptions[0]
				}

				if (item.retryPolicy) {
					this.selectedRetryPolicy = this.retryPolicyOptions.find(
						p => p.value === item.retryPolicy,
					) || this.retryPolicyOptions[0]
				}

				if (item.events && Array.isArray(item.events) && item.events.length > 0) {
					this.selectedEvent = item.events[0]
				}

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
		closeModal() {
			navigationStore.setModal(false)
		},
		async saveWebhook() {
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

				let response
				if (this.webhookItem.id) {
					response = await axios.put(
						generateUrl(`/apps/openregister/api/webhooks/${this.webhookItem.id}`),
						payload,
					)
				} else {
					response = await axios.post(
						generateUrl('/apps/openregister/api/webhooks'),
						payload,
					)
				}

				if (response.data) {
					this.$root.$emit('webhooks-updated')
					this.$refs.dialog.setResult({ success: true })
				}
			} catch (error) {
				console.error('Failed to save webhook:', error)
				this.$refs.dialog.setResult({
					error: error.response?.data?.error || t('openregister', 'Failed to save webhook'),
				})
			}
		},
	},
}
</script>

<style scoped>
.field-hint {
	font-size: 0.875rem;
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.selectField,
.checkboxField {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.selectField label {
	font-weight: 500;
	color: var(--color-text-maxcontrast);
}

/* Dropdown option styles */
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
