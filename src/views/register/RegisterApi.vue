<!--
  The API page of a register: every schema operation from the register's
  OpenAPI description, tried as the signed-in user, with a curl and a fetch
  sample for the same call, and a link to the GraphQL explorer.

  Calls go through @nextcloud/axios to this instance only; the page loads no
  script from outside it (REQ-APIX-001).

  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  @spec openspec/changes/api-explorer-in-the-app/specs/graphql-api/spec.md#requirement-req-apix-001-the-api-can-be-tried-from-inside-the-app
-->
<template>
	<NcAppContent>
		<div class="register-api">
			<div class="register-api__header">
				<h2>{{ t('openregister', 'Register API') }}</h2>
				<p class="register-api__intro">
					{{
						t(
							'openregister',
							'Each operation of this register can be tried here. A call runs with the rights of the signed-in user and works on real data.',
						)
					}}
				</p>
				<a class="register-api__explorer" :href="explorerUrl">
					{{ t('openregister', 'GraphQL explorer') }}
				</a>
			</div>

			<NcLoadingIcon v-if="loading" :size="32" />
			<NcNoteCard v-else-if="loadError" type="error">
				{{ t('openregister', 'The API description could not be loaded.') }}
			</NcNoteCard>
			<NcEmptyContent
				v-else-if="groups.length === 0"
				:name="
					t('openregister', 'This register has no schema operations yet.')
				" />

			<section
				v-for="group in groups"
				:key="group.tag"
				class="register-api__group">
				<h3>{{ group.tag }}</h3>
				<details
					v-for="operation in group.operations"
					:key="operation.key"
					class="register-api__operation">
					<summary>
						<code class="register-api__method">{{
							operation.method
						}}</code>
						<code>{{ operation.path }}</code>
						<span class="register-api__summary">{{
							operation.summary
						}}</span>
					</summary>

					<div class="register-api__form">
						<NcTextField
							v-for="name in operation.pathParams"
							:key="name"
							v-model="pathValues[operation.key][name]"
							:label="name" />
						<NcTextArea
							v-if="operation.hasBody"
							v-model="bodies[operation.key]"
							:label="t('openregister', 'Request body (JSON)')"
							:rows="6" />
						<NcButton
							variant="primary"
							:disabled="
								!canRun(operation) || running[operation.key] === true
							"
							@click="run(operation)">
							{{ t('openregister', 'Send request') }}
						</NcButton>
					</div>

					<NcNoteCard v-if="bodyErrors[operation.key]" type="error">
						{{
							t('openregister', 'The request body is not valid JSON.')
						}}
					</NcNoteCard>

					<div v-if="results[operation.key]" class="register-api__result">
						<h4>
							{{
								t('openregister', 'Response: {status}', {
									status: results[operation.key].status,
								})
							}}
						</h4>
						<pre>{{ results[operation.key].body }}</pre>
					</div>

					<div class="register-api__samples">
						<h4>curl</h4>
						<pre>{{ samples(operation).curl }}</pre>
						<h4>JavaScript</h4>
						<pre>{{ samples(operation).fetch }}</pre>
					</div>
				</details>
			</section>
		</div>
	</NcAppContent>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	NcAppContent,
	NcButton,
	NcEmptyContent,
	NcLoadingIcon,
	NcNoteCard,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import { apiSamples } from '../../services/apiSamples.js'
import {
	isComplete,
	operationsFromOas,
	parseBody,
	serverUrl,
} from '../../services/registerApiOperations.js'

/** Where the API routes start, relative to the instance. */
const API_BASE = '/apps/openregister/api'

/**
 * The text a response shows: its JSON, indented, or its text as it came.
 *
 * @spec openspec/changes/api-explorer-in-the-app/specs/graphql-api/spec.md#requirement-req-apix-001-the-api-can-be-tried-from-inside-the-app
 * @param {*} data The response data.
 * @return {string} The text.
 */
export function responseText(data) {
	if (typeof data === 'string') {
		return data
	}
	return JSON.stringify(data ?? null, null, 2)
}

/**
 * An empty value per path parameter of every operation, keyed by operation.
 *
 * @spec openspec/changes/api-explorer-in-the-app/specs/graphql-api/spec.md#requirement-req-apix-001-the-api-can-be-tried-from-inside-the-app
 * @param {Array<object>} groups The operation groups.
 * @return {object} The values.
 */
export function emptyPathValues(groups) {
	const values = {}
	for (const group of groups) {
		for (const operation of group.operations) {
			values[operation.key] = Object.fromEntries(
				operation.pathParams.map((name) => [name, '']),
			)
		}
	}
	return values
}

export default {
	name: 'RegisterApi',

	components: {
		NcAppContent,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcTextArea,
		NcTextField,
	},

	data() {
		return {
			loading: true,
			loadError: false,
			oas: null,
			groups: [],
			// Per operation key: the path values, the typed body, the last result.
			pathValues: {},
			bodies: {},
			bodyErrors: {},
			results: {},
			running: {},
		}
	},

	computed: {
		/**
		 * @spec exclude UI plumbing — the register id from the route.
		 * @return {string} The id.
		 */
		registerId() {
			return String(this.$route?.params?.id ?? '')
		},

		/**
		 * @spec exclude UI plumbing — the bundled GraphQL explorer's address.
		 * @return {string} The URL.
		 */
		explorerUrl() {
			return generateUrl(API_BASE + '/graphql/explorer')
		},

		/**
		 * The base URL the samples address.
		 *
		 * @spec openspec/changes/api-explorer-in-the-app/specs/graphql-api/spec.md#requirement-req-apix-001-the-api-can-be-tried-from-inside-the-app
		 * @return {string} The URL.
		 */
		instanceUrl() {
			return serverUrl(
				this.oas,
				window.location.origin + generateUrl(API_BASE),
			)
		},
	},

	watch: {
		registerId() {
			this.load()
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read the register's OpenAPI description and list its operations.
		 *
		 * @spec openspec/changes/api-explorer-in-the-app/specs/graphql-api/spec.md#requirement-req-apix-001-the-api-can-be-tried-from-inside-the-app
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.loadError = false
			try {
				const { data } = await axios.get(
					generateUrl(API_BASE + '/registers/{id}/oas', {
						id: this.registerId,
					}),
				)
				this.oas = data
				this.groups = operationsFromOas(data)
				this.pathValues = emptyPathValues(this.groups)
			} catch {
				this.oas = null
				this.groups = []
				this.loadError = true
			} finally {
				this.loading = false
			}
		},

		/**
		 * @spec exclude UI plumbing — whether Run is enabled.
		 * @param {object} operation The operation.
		 * @return {boolean} True when every path parameter has a value.
		 */
		canRun(operation) {
			return isComplete(operation, this.pathValues[operation.key])
		},

		/**
		 * The curl and fetch samples of an operation with the values typed so far.
		 *
		 * @spec openspec/changes/api-explorer-in-the-app/specs/graphql-api/spec.md#requirement-req-apix-001-the-api-can-be-tried-from-inside-the-app
		 * @param {object} operation The operation.
		 * @return {{url: string, curl: string, fetch: string}} The samples.
		 */
		samples(operation) {
			const body = operation.hasBody
				? parseBody(this.bodies[operation.key])
				: { value: undefined }
			return apiSamples(
				{
					method: operation.method,
					path: operation.path,
					pathParams: this.pathValues[operation.key] ?? {},
					body: body.value,
				},
				this.instanceUrl,
			)
		},

		/**
		 * Run an operation as the signed-in user and keep its status and body.
		 * An error answer is a result too: its status and body are shown.
		 *
		 * @spec openspec/changes/api-explorer-in-the-app/specs/graphql-api/spec.md#requirement-req-apix-001-the-api-can-be-tried-from-inside-the-app
		 * @param {object} operation The operation.
		 * @return {Promise<void>}
		 */
		async run(operation) {
			const body = operation.hasBody
				? parseBody(this.bodies[operation.key])
				: { ok: true, value: undefined }
			this.bodyErrors = { ...this.bodyErrors, [operation.key]: !body.ok }
			if (!body.ok || !this.canRun(operation)) {
				return
			}
			this.running = { ...this.running, [operation.key]: true }
			const request = {
				method: operation.method.toLowerCase(),
				url: generateUrl(
					API_BASE + operation.path,
					this.pathValues[operation.key] ?? {},
				),
			}
			if (body.value !== undefined) {
				request.data = body.value
			}
			let result
			try {
				const response = await axios.request(request)
				result = {
					status: response.status,
					body: responseText(response.data),
				}
			} catch (error) {
				result = {
					status: error?.response?.status ?? '—',
					body: responseText(
						error?.response?.data ?? error?.message ?? '',
					),
				}
			} finally {
				this.running = { ...this.running, [operation.key]: false }
			}
			this.results = { ...this.results, [operation.key]: result }
		},
	},
}
</script>

<style scoped>
.register-api {
	padding: 16px 24px;
	max-width: 1100px;
}

.register-api__intro,
.register-api__summary {
	color: var(--color-text-maxcontrast);
}

.register-api__explorer {
	color: var(--color-primary-element);
	text-decoration: underline;
}

.register-api__group {
	margin-block-start: 24px;
}

.register-api__operation {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 8px 12px;
	margin-block-end: 8px;
}

.register-api__operation summary {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: baseline;
	cursor: pointer;
}

.register-api__method {
	font-weight: bold;
	min-width: 4em;
}

.register-api__form,
.register-api__result,
.register-api__samples {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-block-start: 12px;
}

.register-api pre {
	background: var(--color-background-dark);
	color: var(--color-main-text);
	padding: 8px;
	border-radius: var(--border-radius);
	overflow-x: auto;
	white-space: pre-wrap;
	overflow-wrap: anywhere;
}
</style>
