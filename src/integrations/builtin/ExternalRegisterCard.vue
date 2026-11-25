<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  ExternalRegisterCard — one record of a basisregistratie (BAG, BRK, WOZ, …)
  next to the object that names it (external-register-view-leaf, task 2.2).

  It reads the leaf endpoint by the host's key value
  (`GET /api/external-register/{register}/{schema}/{key}`) and shows the
  record's fields, when it was fetched and from which source, with a refresh
  that bypasses the cache. Every way the lookup can fail to show a record has
  its own sentence (ExternalRegisterDegrade): only `not-found` says the
  register holds nothing; the others say why we could not look, so a blank
  card is never read as "there is no such record".

  @spec openspec/changes/external-register-view-leaf/tasks.md#2-leaf
-->
<template>
	<div class="external-register-card" data-testid="external-register-card">
		<h3 v-if="title && !chromeless" class="external-register-card__title">
			{{ title }}
		</h3>

		<NcLoadingIcon v-if="loading" :size="24" data-testid="external-register-loading" />

		<template v-else>
			<p
				v-if="message"
				class="external-register-card__message"
				:data-state="state"
				data-testid="external-register-message">
				{{ message }}
			</p>

			<dl v-if="fields.length" class="external-register-card__fields" data-testid="external-register-fields">
				<template v-for="field in fields" :key="field.name">
					<dt>{{ field.name }}</dt>
					<dd>{{ field.value }}</dd>
				</template>
			</dl>

			<div class="external-register-card__footer">
				<span
					v-if="fetchedAt"
					class="external-register-card__fetched"
					data-testid="external-register-fetched">
					{{ t('openregister', 'Fetched {time} from {source}', { time: fetchedLabel, source: source || schema }) }}
				</span>
				<NcButton
					v-if="String(keyValue) !== ''"
					variant="tertiary"
					:disabled="loading"
					data-testid="external-register-refresh"
					@click="load(true)">
					{{ t('openregister', 'Refresh') }}
				</NcButton>
			</div>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { externalRegisterFields, externalRegisterMessage, externalRegisterUrl } from './externalRegisterState.js'

export default {
	name: 'ExternalRegisterCard',

	components: { NcButton, NcLoadingIcon },

	props: {
		/** The register of the sourced schema (the placement's, else the seeded one). */
		register: { type: String, default: 'external-registers' },
		/** The sourced schema, e.g. `bag-adres`. */
		schema: { type: String, required: true },
		/**
		 * The key value from the host object, resolved by the host from a
		 * token such as `@object.bagId` (`key` itself is reserved in Vue).
		 */
		keyValue: { type: [String, Number], default: '' },
		/** The record fields to show, in order; all scalar fields when empty. */
		display: { type: Array, default: () => [] },
		/** The card title the host passes. */
		title: { type: String, default: '' },
		/** True when the host draws the card and its title. */
		chromeless: { type: Boolean, default: false },
	},

	data() {
		return {
			loading: false,
			state: '',
			adminActionable: false,
			record: null,
			fetchedAt: '',
			source: '',
		}
	},

	computed: {
		/**
		 * The sentence for the current state, or '' when the record shows.
		 *
		 * @return {string}
		 */
		message() {
			return externalRegisterMessage(this.state, t)
		},

		/**
		 * The record's fields to show.
		 *
		 * @return {Array<{name: string, value: string}>}
		 */
		fields() {
			return externalRegisterFields(this.record, this.display)
		},

		/**
		 * When the record was fetched, in the viewer's locale.
		 *
		 * @return {string}
		 */
		fetchedLabel() {
			const at = new Date(this.fetchedAt)
			return Number.isNaN(at.getTime()) ? String(this.fetchedAt) : at.toLocaleString()
		},
	},

	watch: {
		keyValue() {
			this.load(false)
		},
	},

	mounted() {
		this.load(false)
	},

	methods: {
		t,

		/**
		 * Read the record by the host's key; a key-less host reads nothing.
		 *
		 * @param {boolean} refresh Whether to bypass the cache.
		 * @return {Promise<void>}
		 */
		async load(refresh) {
			const url = externalRegisterUrl(this.register, this.schema, this.keyValue, refresh)
			if (url === null) {
				this.apply({ state: 'no-key' })
				return
			}

			this.loading = true
			try {
				const response = await axios.get(generateUrl(url))
				this.apply(response.data || {})
			} catch (e) {
				// A 404 is a schema the caller may not read, or none: the same answer.
				this.apply({ state: e?.response?.status === 404 ? 'refused' : 'unreachable' })
			} finally {
				this.loading = false
			}
		},

		/**
		 * Take an answer of the leaf endpoint.
		 *
		 * @param {object} data `{state, adminActionable, record, fetchedAt, source}`.
		 * @return {void}
		 */
		apply(data) {
			this.state = String(data.state || 'unreachable')
			this.adminActionable = data.adminActionable === true
			this.record = data.record ?? null
			this.fetchedAt = data.fetchedAt || ''
			this.source = data.source || ''
		},
	},
}
</script>

<style scoped>
.external-register-card__title {
	margin: 0 0 8px;
}

.external-register-card__message {
	color: var(--color-text-maxcontrast);
}

.external-register-card__fields {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 4px 12px;
	margin: 0 0 8px;
}

.external-register-card__fields dt {
	font-weight: bold;
}

.external-register-card__fields dd {
	margin: 0;
	overflow-wrap: anywhere;
}

.external-register-card__footer {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
}

.external-register-card__fetched {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}
</style>
