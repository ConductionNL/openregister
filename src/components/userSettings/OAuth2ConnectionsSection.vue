<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Connected accounts, the person-facing half of the OAuth2 credential broker.

 It sits beside CnCredentials rather than inside it. The wallet component lives in
 @conduction/nextcloud-vue, a different repository, and connecting an account is
 broker behaviour whose status vocabulary belongs to this change. Shipping it here
 first means the server that backs it exists before the library that renders it;
 once the shape settles it can move into the library.

 The panel shows an account handle, its granted scopes and its expiry, and never a
 token. There is nothing to hide in the markup, because a token never reaches the
 browser at all: the whole point of the broker is that the secret stays server-side
 and OpenRegister makes the outbound call.

 @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-a-person-can-connect-see-and-repair-a-connection-from-personal-settings
-->
<template>
	<NcSettingsSection
		:name="t('openregister', 'Connected accounts')"
		:description="
			t(
				'openregister',
				'Connect a social or analytics account once. OpenRegister keeps the token and refreshes it, so apps never hold it.',
			)
		"
		data-testid="oauth2-connections-section">
		<NcNoteCard v-if="error" type="error" data-testid="oauth2-connections-error">
			{{ error }}
		</NcNoteCard>

		<NcLoadingIcon v-if="loading" :size="32" />

		<template v-else>
			<p
				v-if="connections.length === 0"
				class="oauth2-connections__empty"
				data-testid="oauth2-connections-empty">
				{{ t('openregister', 'No accounts are connected yet.') }}
			</p>

			<ul
				v-else
				class="oauth2-connections__list"
				data-testid="oauth2-connections-list">
				<li
					v-for="connection in connections"
					:key="connection.id"
					class="oauth2-connections__item"
					:data-testid="`oauth2-connection-${connection.id}`">
					<div class="oauth2-connections__identity">
						<span class="oauth2-connections__name">{{
							connection.name
						}}</span>
						<span class="oauth2-connections__handle">{{
							handleOf(connection)
						}}</span>
						<span class="oauth2-connections__scopes">{{
							scopesOf(connection)
						}}</span>
						<span class="oauth2-connections__expiry">{{
							expiryOf(connection)
						}}</span>
					</div>

					<span
						class="oauth2-connections__chip"
						:class="`oauth2-connections__chip--${connection.status}`"
						:data-testid="`oauth2-status-${connection.id}`">
						{{ statusLabel(connection.status) }}
					</span>

					<NcButton
						v-if="connection.status !== 'disabled'"
						variant="secondary"
						:disabled="busy"
						:data-testid="`oauth2-reconnect-${connection.id}`"
						@click="reconnect(connection)">
						{{ t('openregister', 'Reconnect') }}
					</NcButton>

					<NcButton
						variant="tertiary"
						:disabled="busy"
						:data-testid="`oauth2-disconnect-${connection.id}`"
						@click="disconnect(connection)">
						{{ t('openregister', 'Disconnect') }}
					</NcButton>
				</li>
			</ul>

			<div class="oauth2-connections__connect">
				<NcSelect
					v-model="chosenProvider"
					:options="providers"
					label="title"
					:inputLabel="t('openregister', 'Provider')"
					:disabled="busy"
					data-testid="oauth2-provider-select" />

				<NcTextField
					v-if="needsInstanceHost"
					v-model="instanceBaseUrl"
					:label="t('openregister', 'Server address')"
					placeholder="https://mastodon.example"
					:disabled="busy"
					data-testid="oauth2-instance-input" />

				<NcButton
					variant="primary"
					:disabled="busy || !chosenProvider"
					data-testid="oauth2-connect-button"
					@click="connect">
					{{ t('openregister', 'Connect account') }}
				</NcButton>
			</div>
		</template>
	</NcSettingsSection>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
	NcSettingsSection,
	NcTextField,
} from '@nextcloud/vue'

export default {
	name: 'OAuth2ConnectionsSection',
	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcSettingsSection,
		NcTextField,
	},

	data() {
		return {
			loading: true,
			busy: false,
			error: '',
			connections: [],
			providers: [],
			chosenProvider: null,
			instanceBaseUrl: '',
		}
	},

	computed: {
		/**
		 * Whether the chosen provider's API host belongs to the account rather than
		 * to the provider, in which case the person has to say which server.
		 *
		 * @return {boolean} True when a server address is required.
		 *
		 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-a-person-can-connect-see-and-repair-a-connection-from-personal-settings
		 */
		needsInstanceHost() {
			return Boolean(this.chosenProvider?.requiresInstanceBaseUrl)
		},
	},

	async mounted() {
		await this.load()
	},

	methods: {
		t,

		/**
		 * Load the caller's connections and the providers that can be connected.
		 *
		 * @return {Promise<void>} Resolves once both are loaded.
		 *
		 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-a-person-can-connect-see-and-repair-a-connection-from-personal-settings
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const [credentials, providers] = await Promise.all([
					axios.get(generateUrl('/apps/openregister/api/credentials')),
					axios.get(
						generateUrl('/apps/openregister/api/credentials/providers'),
					),
				])
				this.connections = (credentials.data?.results ?? []).filter(
					(entry) => entry.kind === 'oauth2-token-set',
				)
				this.providers = (providers.data?.results ?? []).filter(
					(entry) => entry.kind === 'oauth2-token-set',
				)
			} catch {
				this.error = t(
					'openregister',
					'Could not load your connected accounts.',
				)
			}
			this.loading = false
		},

		/**
		 * Start a connection for the chosen provider.
		 *
		 * @return {Promise<void>} Resolves once the browser has been sent onward.
		 *
		 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-a-person-can-connect-see-and-repair-a-connection-from-personal-settings
		 */
		async connect() {
			await this.startFlow({
				provider: this.chosenProvider?.identifier,
				instanceBaseUrl: this.instanceBaseUrl,
			})
		},

		/**
		 * Re-run the flow onto an existing connection, keeping its credential id.
		 *
		 * @param {object} connection The connection to repair.
		 *
		 * @return {Promise<void>} Resolves once the browser has been sent onward.
		 *
		 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-a-person-can-connect-see-and-repair-a-connection-from-personal-settings
		 */
		async reconnect(connection) {
			await this.startFlow({
				provider: connection.provider,
				instanceBaseUrl: connection.instanceBaseUrl ?? '',
				credentialId: connection.id,
			})
		},

		/**
		 * Revoke a connection upstream where possible and disable it locally.
		 *
		 * @param {object} connection The connection to disconnect.
		 *
		 * @return {Promise<void>} Resolves once the list has been reloaded.
		 *
		 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-a-person-can-connect-see-and-repair-a-connection-from-personal-settings
		 */
		async disconnect(connection) {
			this.busy = true
			this.error = ''
			try {
				await axios.delete(
					generateUrl('/apps/openregister/api/credentials/oauth2/{id}', {
						id: connection.id,
					}),
				)
				await this.load()
			} catch {
				this.error = t('openregister', 'Could not disconnect this account.')
			}
			this.busy = false
		},

		/**
		 * Ask the server for an authorization URL and follow it.
		 *
		 * @param {object} payload The start parameters.
		 *
		 * @return {Promise<void>} Resolves once the browser has been sent onward.
		 *
		 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-a-person-can-connect-see-and-repair-a-connection-from-personal-settings
		 */
		async startFlow(payload) {
			this.busy = true
			this.error = ''
			try {
				const response = await axios.post(
					generateUrl('/apps/openregister/api/credentials/oauth2/start'),
					{ ...payload, returnUrl: window.location.pathname },
				)
				this.navigateTo(response.data.authorizationUrl)
			} catch {
				this.error = t(
					'openregister',
					'Could not start the connection. Check the provider and try again.',
				)
				this.busy = false
			}
		},

		/**
		 * Send the browser to the provider's consent screen.
		 *
		 * A method of its own because it is the one irreversible step in the flow, and
		 * naming it makes the point where control leaves this page explicit rather
		 * than buried in an assignment.
		 *
		 * @param {string} url The authorization URL the server built.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-a-person-can-connect-see-and-repair-a-connection-from-personal-settings
		 */
		navigateTo(url) {
			window.location.href = url
		},

		/**
		 * The account handle to show, falling back to a placeholder while pending.
		 *
		 * @param {object} connection The connection.
		 *
		 * @return {string} The handle.
		 *
		 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-a-person-can-connect-see-and-repair-a-connection-from-personal-settings
		 */
		handleOf(connection) {
			return (
				connection.account?.handle || t('openregister', 'Not connected yet')
			)
		},

		/**
		 * The granted scopes, as a readable list.
		 *
		 * @param {object} connection The connection.
		 *
		 * @return {string} The scopes.
		 *
		 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-a-person-can-connect-see-and-repair-a-connection-from-personal-settings
		 */
		scopesOf(connection) {
			return (connection.scopes ?? []).join(', ')
		},

		/**
		 * The expiry, as a locale date, or an empty string when there is none.
		 *
		 * @param {object} connection The connection.
		 *
		 * @return {string} The expiry.
		 *
		 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-a-person-can-connect-see-and-repair-a-connection-from-personal-settings
		 */
		expiryOf(connection) {
			if (!connection.expiresAt) {
				return ''
			}
			return t('openregister', 'Expires {date}', {
				date: new Date(connection.expiresAt).toLocaleString(),
			})
		},

		/**
		 * The label for a connection status.
		 *
		 * @param {string} status The stored status.
		 *
		 * @return {string} The label.
		 *
		 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-a-person-can-connect-see-and-repair-a-connection-from-personal-settings
		 */
		statusLabel(status) {
			const labels = {
				pending: t('openregister', 'Pending'),
				active: t('openregister', 'Active'),
				expired: t('openregister', 'Expired'),
				relink_needed: t('openregister', 'Relink needed'),
				disabled: t('openregister', 'Disabled'),
			}
			return labels[status] ?? status
		},
	},
}
</script>

<style scoped>
.oauth2-connections__list {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-bottom: 16px;
}

.oauth2-connections__item {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.oauth2-connections__identity {
	display: flex;
	flex-direction: column;
	flex: 1 1 auto;
	min-width: 0;
}

.oauth2-connections__name {
	font-weight: bold;
}

.oauth2-connections__handle,
.oauth2-connections__scopes,
.oauth2-connections__expiry {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.oauth2-connections__chip {
	padding: 2px 10px;
	border-radius: var(--border-radius-pill);
	background-color: var(--color-background-dark);
	font-size: 0.85em;
	white-space: nowrap;
}

.oauth2-connections__chip--active {
	background-color: var(--color-success);
	color: var(--color-primary-text);
}

.oauth2-connections__chip--expired,
.oauth2-connections__chip--relink_needed {
	background-color: var(--color-warning);
	color: var(--color-main-text);
}

.oauth2-connections__chip--disabled {
	background-color: var(--color-background-darker);
	color: var(--color-text-maxcontrast);
}

.oauth2-connections__connect {
	display: flex;
	align-items: flex-end;
	gap: 8px;
	flex-wrap: wrap;
}

.oauth2-connections__empty {
	color: var(--color-text-maxcontrast);
	margin-bottom: 16px;
}
</style>
