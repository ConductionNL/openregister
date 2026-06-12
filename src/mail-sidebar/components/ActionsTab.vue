<template>
	<div class="or-tab-actions">
		<div v-if="loading" class="or-tab-loading">
			{{ t('openregister', 'Loading schemas...') }}
		</div>
		<div v-else-if="schemas.length === 0" class="or-tab-empty">
			{{ t('openregister', 'No schemas configured for mail linking.') }}
		</div>
		<div v-else>
			<div
				v-for="schema in schemas"
				:key="schema.id"
				class="or-action-block">
				<label class="or-action-label">
					{{ t('openregister', 'Link to {name}', { name: schema.title }) }}
				</label>
				<div class="or-action-search">
					<input
						v-model="searchTerms[schema.id]"
						type="text"
						class="or-action-input"
						:placeholder="t('openregister', 'Search {name}...', { name: schema.title })"
						@input="debounceSearch(schema)"
						@focus="showResults(schema)">
					<ul v-if="visibleResults[schema.id] && (searchResults[schema.id] || []).length > 0" class="or-action-results">
						<li
							v-for="obj in searchResults[schema.id]"
							:key="obj.id"
							class="or-action-result"
							@click="linkObject(schema, obj)">
							<span class="or-action-result-name">{{ objectName(obj) }}</span>
						</li>
					</ul>
					<div v-if="searching[schema.id]" class="or-action-searching">
						{{ t('openregister', 'Searching...') }}
					</div>
				</div>
				<button
					v-if="hasCreateTemplate(schema)"
					type="button"
					class="or-action-create"
					:disabled="!!creating[schema.id]"
					@click="createFromEmail(schema)">
					{{ creating[schema.id]
						? t('openregister', 'Creating...')
						: t('openregister', 'New {name} from this email', { name: schema.title }) }}
				</button>
			</div>
		</div>
	</div>
</template>

<script>
/**
 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-50
 */
import { translate as t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'

export default {
	name: 'ActionsTab',
	props: {
		accountId: { type: Number, default: null },
		messageId: { type: Number, default: null },
	},
	data() {
		return {
			schemas: [],
			loading: true,
			searchTerms: {},
			searchResults: {},
			searching: {},
			visibleResults: {},
			debounceTimers: {},
			registerCache: {},
			creating: {},
			envelopeCache: {},
		}
	},
	async created() {
		await this.loadSchemas()
	},
	methods: {
		t,
		/**
		 * @spec openspec/changes/retrofit-2026-05-25-fe-misc/tasks.md#task-1
		 */
		objectName(obj) {
			return obj['@self']?.name
				|| obj._name
				|| obj.title
				|| obj.name
				|| obj.naam
				|| obj.id
		},
		/**
		 * @spec openspec/changes/retrofit-2026-05-25-fe-misc/tasks.md#task-1
		 */
		async loadSchemas() {
			this.loading = true
			try {
				// Load schemas (paged — instances can hold many hundreds of
				// schemas and the API result order is not by id, so a single
				// first page would miss mail-linked schemas) and registers.
				const [allSchemas, regResponse] = await Promise.all([
					this.fetchAllSchemas(),
					axios.get(generateUrl('/apps/openregister/api/registers'), { params: { _limit: 500 } }),
				])

				const registers = regResponse.data?.results || regResponse.data || []

				// Cache register lookups
				for (const reg of registers) {
					for (const schemaId of (reg.schemas || [])) {
						this.registerCache[schemaId] = reg
					}
				}

				// Filter to schemas with mail in linkedTypes
				this.schemas = allSchemas.filter((s) => {
					const lt = s.configuration?.linkedTypes || []
					return lt.includes('mail')
				})

				// Load initial results for each schema
				for (const schema of this.schemas) {
					this.loadInitialResults(schema)
				}
			} catch (err) {
				console.error('[ActionsTab] Failed to load schemas:', err)
			} finally {
				this.loading = false
			}
		},
		/**
		 * @spec openspec/changes/retrofit-2026-05-25-fe-misc/tasks.md#task-1
		 */
		async loadInitialResults(schema) {
			const register = this.registerCache[schema.id]
			if (!register) return

			try {
				const url = generateUrl('/apps/openregister/api/objects/{register}/{schema}', {
					register: register.id,
					schema: schema.id,
				})
				const response = await axios.get(url, {
					params: { _limit: 20 },
					timeout: 10000,
				})
				const results = response.data?.results || response.data || []
				this.$set(this.searchResults, schema.id, results)
			} catch (err) {
				console.error('[ActionsTab] Initial load failed for', schema.title, err)
			}
		},
		/**
		 * @spec openspec/changes/retrofit-2026-05-25-fe-misc/tasks.md#task-1
		 */
		showResults(schema) {
			this.$set(this.visibleResults, schema.id, true)
		},
		/**
		 * @spec openspec/changes/retrofit-2026-05-25-fe-misc/tasks.md#task-1
		 */
		debounceSearch(schema) {
			if (this.debounceTimers[schema.id]) {
				clearTimeout(this.debounceTimers[schema.id])
			}
			this.debounceTimers[schema.id] = setTimeout(() => {
				this.searchObjects(schema)
			}, 300)
		},
		/**
		 * @spec openspec/changes/retrofit-2026-05-25-fe-misc/tasks.md#task-1
		 */
		async searchObjects(schema) {
			const term = this.searchTerms[schema.id] || ''
			const register = this.registerCache[schema.id]
			if (!register) return

			// If empty, reload initial results
			if (term.length === 0) {
				this.loadInitialResults(schema)
				return
			}

			this.$set(this.searching, schema.id, true)
			this.$set(this.visibleResults, schema.id, true)
			try {
				const url = generateUrl('/apps/openregister/api/objects/{register}/{schema}', {
					register: register.id,
					schema: schema.id,
				})
				const response = await axios.get(url, {
					params: { _search: term, _limit: 20 },
					timeout: 10000,
				})
				const results = response.data?.results || response.data || []
				this.$set(this.searchResults, schema.id, results)
			} catch (err) {
				console.error('[ActionsTab] Search failed:', err)
			} finally {
				this.$set(this.searching, schema.id, false)
			}
		},
		/**
		 * Fetch every schema page by page (capped at 10 pages of 500).
		 *
		 * @spec openspec/changes/integration-email/tasks.md
		 */
		async fetchAllSchemas() {
			const limit = 500
			const all = []
			for (let page = 1; page <= 10; page++) {
				const response = await axios.get(generateUrl('/apps/openregister/api/schemas'), {
					params: { _limit: limit, _page: page },
					timeout: 15000,
				})
				const results = response.data?.results || response.data || []
				all.push(...results)
				if (results.length < limit) break
			}
			return all
		},
		/**
		 * Schemas opt into create-from-email by declaring a field template in
		 * `configuration.mailObjectTemplate` (e.g. pipelinq lead, shillinq invoice).
		 *
		 * @spec openspec/changes/integration-email/tasks.md
		 */
		hasCreateTemplate(schema) {
			const tpl = schema.configuration?.mailObjectTemplate
			return tpl && typeof tpl === 'object' && Object.keys(tpl).length > 0
		},
		/**
		 * @spec openspec/changes/integration-email/tasks.md
		 */
		async fetchEnvelope() {
			if (this.envelopeCache[this.messageId]) {
				return this.envelopeCache[this.messageId]
			}
			const url = generateUrl('/apps/mail/api/messages/{id}/body', { id: this.messageId })
			const response = await axios.get(url, { timeout: 10000 })
			const envelope = response.data?.data || response.data
			this.$set(this.envelopeCache, this.messageId, envelope)
			return envelope
		},
		/**
		 * Build the placeholder map available to mailObjectTemplate values.
		 *
		 * @spec openspec/changes/integration-email/tasks.md
		 */
		buildPlaceholders(envelope) {
			const from = (envelope.from || [])[0] || {}
			const sentAt = envelope.dateInt ? new Date(envelope.dateInt * 1000) : new Date()
			const due = new Date(sentAt.getTime() + (30 * 24 * 60 * 60 * 1000))
			const isoDate = (d) => d.toISOString().slice(0, 10)
			let preview = envelope.body || ''
			if (envelope.hasHtmlBody) {
				preview = new DOMParser().parseFromString(preview, 'text/html').body.textContent || ''
			}
			preview = preview.trim().slice(0, 600)
			return {
				subject: envelope.subject || '',
				sender: from.email || '',
				senderName: from.label || from.email || '',
				date: isoDate(sentAt),
				date30: isoDate(due),
				datetime: sentAt.toISOString(),
				preview,
				messageId: String(this.messageId),
				mailRef: `${this.accountId}/${this.messageId}`,
			}
		},
		/**
		 * Substitute {{placeholder}} tokens in string template values; pass
		 * non-string values (numbers, booleans) through untouched.
		 *
		 * @spec openspec/changes/integration-email/tasks.md
		 */
		applyTemplate(template, placeholders) {
			const data = {}
			for (const [field, value] of Object.entries(template)) {
				if (typeof value === 'string') {
					data[field] = value.replace(/\{\{(\w+)\}\}/g, (match, key) => (
						key in placeholders ? placeholders[key] : match
					))
				} else {
					data[field] = value
				}
			}
			return data
		},
		/**
		 * Create a new object from the current email via the schema's
		 * mailObjectTemplate, then link the email to it.
		 *
		 * @spec openspec/changes/integration-email/tasks.md
		 */
		async createFromEmail(schema) {
			if (!this.accountId || !this.messageId || this.creating[schema.id]) return
			const register = this.registerCache[schema.id]
			if (!register) return

			this.$set(this.creating, schema.id, true)
			try {
				const envelope = await this.fetchEnvelope()
				const data = this.applyTemplate(
					schema.configuration.mailObjectTemplate,
					this.buildPlaceholders(envelope),
				)
				const url = generateUrl('/apps/openregister/api/objects/{register}/{schema}', {
					register: register.id,
					schema: schema.id,
				})
				const response = await axios.post(url, data, { timeout: 15000 })
				const created = response.data
				await this.linkObject(schema, created)
			} catch (err) {
				const detail = err.response?.data?.error || err.response?.data || ''
				showError(t('openregister', 'Failed to create {name} from email', { name: schema.title }))
				console.error('[ActionsTab] Create from email failed:', detail, err)
			} finally {
				this.$set(this.creating, schema.id, false)
			}
		},
		/**
		 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-50
		 */
		async linkObject(schema, obj) {
			const objectUuid = obj.id || obj.uuid || obj._uuid
			if (!objectUuid || !this.accountId || !this.messageId) return

			const mailRef = `${this.accountId}/${this.messageId}`
			try {
				const url = generateUrl('/apps/openregister/api/objects/{uuid}/_linked/mail', {
					uuid: objectUuid,
				})
				await axios.post(url, { id: mailRef })
				showSuccess(t('openregister', 'Linked to {name}', { name: this.objectName(obj) }))

				// Clear search and hide results
				this.$set(this.searchTerms, schema.id, '')
				this.$set(this.visibleResults, schema.id, false)
				this.loadInitialResults(schema)

				this.$emit('linked')
			} catch (err) {
				showError(t('openregister', 'Failed to link object'))
				console.error('[ActionsTab] Link failed:', err)
			}
		},
	},
}
</script>
