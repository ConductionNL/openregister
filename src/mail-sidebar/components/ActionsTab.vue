<template>
	<div class="or-tab-actions">
		<div v-if="loading" class="or-tab-loading">
			{{ t('openregister', 'Loading…') }}
		</div>
		<div v-else-if="schemas.length === 0" class="or-tab-empty">
			{{ t('openregister', 'There is nothing set up to connect this email to yet.') }}
		</div>
		<div v-else>
			<div
				v-for="schema in schemas"
				:key="schema.id"
				class="or-action-block"
				:class="{ 'or-action-block--busy': isBusy(schema) }">
				<label class="or-action-label">
					{{ t('openregister', 'Connect to {name}', { name: schema.title }) }}
				</label>
				<div v-if="isBusy(schema)" class="or-action-connecting">
					<NcLoadingIcon :size="20" />
					<span>{{ creating[schema.id]
						? t('openregister', 'Creating {name}…', { name: schema.title })
						: t('openregister', 'Connecting…') }}</span>
				</div>
				<template v-else>
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
						@click="openCreate(schema)">
						{{ t('openregister', 'New {name} from this email', { name: schema.title }) }}
					</button>
				</template>
			</div>
		</div>

		<CreateConnectedObjectDialog
			:show="createDialog.show"
			:schema="createDialog.schema"
			:initial-data="createDialog.data"
			:saving="createSaving"
			@cancel="closeCreate"
			@confirm="submitCreate" />
	</div>
</template>

<script>
/**
 * @spec openspec/specs/mail-sidebar/spec.md#requirement-link-and-unlink-actions-from-the-sidebar
 */
import { translate as t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import CreateConnectedObjectDialog from '../dialogs/CreateConnectedObjectDialog.vue'

export default {
	name: 'ActionsTab',
	components: {
		NcLoadingIcon,
		CreateConnectedObjectDialog,
	},
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
			linking: {},
			envelopeCache: {},
			// Create-object dialog state.
			createDialog: { show: false, schema: null, data: {} },
			createSaving: false,
		}
	},
	async created() {
		await this.loadSchemas()
	},
	methods: {
		t,
		/**
		 * A schema block is busy while it is creating a new record or
		 * connecting an existing one — used to swap the search UI for a
		 * spinner so the user sees the work happening.
		 */
		isBusy(schema) {
			return !!this.creating[schema.id] || !!this.linking[schema.id]
		},
		/**
		 * @spec openspec/specs/mail-sidebar/spec.md
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
		 * @spec openspec/specs/mail-sidebar/spec.md
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
		 * @spec openspec/specs/mail-sidebar/spec.md
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
		 * @spec openspec/specs/mail-sidebar/spec.md
		 */
		showResults(schema) {
			this.$set(this.visibleResults, schema.id, true)
		},
		/**
		 * @spec openspec/specs/mail-sidebar/spec.md
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
		 * @spec openspec/specs/mail-sidebar/spec.md
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
		 * @spec openspec/specs/integration-email/spec.md
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
		 * @spec openspec/specs/integration-email/spec.md
		 */
		hasCreateTemplate(schema) {
			const tpl = schema.configuration?.mailObjectTemplate
			return tpl && typeof tpl === 'object' && Object.keys(tpl).length > 0
		},
		/**
		 * @spec openspec/specs/integration-email/spec.md
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
		 * @spec openspec/specs/integration-email/spec.md
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
		 * @spec openspec/specs/integration-email/spec.md
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
		 * Open the create-object dialog, prefilled from the schema's
		 * mailObjectTemplate applied to the current email.
		 *
		 * @spec openspec/specs/integration-email/spec.md
		 */
		async openCreate(schema) {
			if (!this.accountId || !this.messageId || this.creating[schema.id]) return
			if (!this.registerCache[schema.id]) return

			this.$set(this.creating, schema.id, true)
			try {
				const envelope = await this.fetchEnvelope()
				const data = this.applyTemplate(
					schema.configuration.mailObjectTemplate,
					this.buildPlaceholders(envelope),
				)
				this.createDialog = { show: true, schema, data }
			} catch (err) {
				showError(t('openregister', 'Could not read this email'))
				console.error('[ActionsTab] Open create failed:', err)
			} finally {
				this.$set(this.creating, schema.id, false)
			}
		},
		/**
		 * Dismiss the create-object dialog.
		 *
		 * @spec openspec/specs/integration-email/spec.md
		 */
		closeCreate() {
			if (this.createSaving) return
			this.createDialog = { show: false, schema: null, data: {} }
		},
		/**
		 * Create the object from the (possibly edited) dialog data, then
		 * connect the email to it.
		 *
		 * @spec openspec/specs/integration-email/spec.md
		 */
		async submitCreate(data) {
			const schema = this.createDialog.schema
			if (!schema) return
			const register = this.registerCache[schema.id]
			if (!register) return

			this.createSaving = true
			try {
				const url = generateUrl('/apps/openregister/api/objects/{register}/{schema}', {
					register: register.id,
					schema: schema.id,
				})
				const response = await axios.post(url, data, { timeout: 15000 })
				await this.linkObject(schema, response.data)
				this.createDialog = { show: false, schema: null, data: {} }
			} catch (err) {
				const detail = err.response?.data?.error || err.response?.data || ''
				showError(t('openregister', 'Failed to create {name} from email', { name: schema.title }))
				console.error('[ActionsTab] Create from email failed:', detail, err)
			} finally {
				this.createSaving = false
			}
		},
		/**
		 * @spec openspec/specs/mail-sidebar/spec.md#requirement-link-and-unlink-actions-from-the-sidebar
		 */
		async linkObject(schema, obj) {
			const objectUuid = obj.id || obj.uuid || obj._uuid
			if (!objectUuid || !this.accountId || !this.messageId) return

			const mailRef = `${this.accountId}/${this.messageId}`
			this.$set(this.linking, schema.id, true)
			try {
				const url = generateUrl('/apps/openregister/api/objects/{uuid}/_linked/mail', {
					uuid: objectUuid,
				})
				await axios.post(url, { id: mailRef })
				showSuccess(t('openregister', 'Connected to {name}', { name: this.objectName(obj) }))

				// Clear search and hide results
				this.$set(this.searchTerms, schema.id, '')
				this.$set(this.visibleResults, schema.id, false)
				this.loadInitialResults(schema)

				this.$emit('linked')
			} catch (err) {
				showError(t('openregister', 'Failed to connect'))
				console.error('[ActionsTab] Link failed:', err)
			} finally {
				this.$set(this.linking, schema.id, false)
			}
		},
	},
}
</script>
