<template>
	<div class="or-tab-actions">
		<div v-if="loading" class="or-tab-loading">
			{{ t('openregister', 'Loading…') }}
		</div>
		<div v-else-if="schemas.length === 0" class="or-tab-empty">
			{{
				t(
					'openregister',
					'There is nothing set up to connect this email to yet.',
				)
			}}
		</div>
		<div v-else>
			<div
				v-for="schema in schemas"
				:key="schema.id"
				class="or-action-block"
				:class="{ 'or-action-block--busy': isBusy(schema) }">
				<label class="or-action-label" :for="'or-action-search-' + schema.id">
					{{ schema.title }}
				</label>
				<div
					v-if="pickedObject(schema)"
					class="or-action-picked"
					role="status"
					:aria-label="t('openregister', 'Connecting…')">
					<div class="or-action-search">
						<Magnify :size="20" class="or-action-search__icon" />
						<input
							type="search"
							class="or-action-input"
							:value="searchTerms[schema.id]"
							:aria-label="schema.title"
							disabled>
					</div>
					<div class="or-action-result or-action-result--picked">
						<ObjectRow
							:kind="schema.title"
							:identifier="objectIdentifier(pickedObject(schema))"
							:name="String(objectName(pickedObject(schema)))"
							:status="objectStatus(pickedObject(schema))"
							:handler="nameOf(objectHandler(pickedObject(schema)))"
							:term="searchTerms[schema.id]"
							busy
							:trailing="t('openregister', 'Connecting')" />
					</div>
				</div>
				<div v-else-if="isBusy(schema)" class="or-action-connecting">
					<NcLoadingIcon :size="20" />
					<span>{{
						t('openregister', 'Creating {name}…', {
							name: itemName(schema),
						})
					}}</span>
				</div>
				<template v-else>
					<div class="or-action-search">
						<Magnify :size="20" class="or-action-search__icon" />
						<input
							:id="'or-action-search-' + schema.id"
							v-model="searchTerms[schema.id]"
							type="search"
							class="or-action-input"
							:placeholder="t('openregister', 'Number or words')"
							@input="debounceSearch(schema)"
							@focus="showResults(schema)" />
						<ul
							v-if="
								visibleResults[schema.id]
								&& (searchResults[schema.id] || []).length > 0
							"
							class="or-action-results"
							role="listbox"
							:aria-label="t('openregister', 'Search Results')">
							<li
								v-for="obj in searchResults[schema.id]"
								:key="obj.id"
								class="or-action-result"
								role="option"
								tabindex="0"
								:aria-selected="false"
								@click="linkObject(schema, obj)"
								@keydown.enter="linkObject(schema, obj)"
								@keydown.space.prevent="linkObject(schema, obj)">
								<ObjectRow
									:kind="schema.title"
									:identifier="objectIdentifier(obj)"
									:name="String(objectName(obj))"
									:status="objectStatus(obj)"
									:handler="nameOf(objectHandler(obj))"
									:term="searchTerms[schema.id]" />
							</li>
						</ul>
						<p
							v-else-if="noMatch(schema)"
							class="or-action-nomatch">
							{{
								t('openregister', 'No {name} matches “{term}”.', {
									name: itemName(schema),
									term: searchTerms[schema.id],
								})
							}}
						</p>
						<div v-if="searching[schema.id]" class="or-action-searching">
							{{ t('openregister', 'Searching...') }}
						</div>
					</div>
					<button
						v-if="hasCreateTemplate(schema)"
						type="button"
						class="or-action-create"
						@click="openCreate(schema)">
						<Plus :size="20" />
						{{
							t('openregister', 'New {name} from this mail', {
								name: itemName(schema),
							})
						}}
					</button>
				</template>
			</div>
		</div>

		<CreateConnectedObjectDialog
			:show="createDialog.show"
			:schema="createDialog.schema"
			:initialData="createDialog.data"
			:register="createDialog.schema ? registerCache[createDialog.schema.id] : null"
			:saving="createSaving"
			@cancel="closeCreate"
			@confirm="submitCreate" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
/**
 * @spec openspec/specs/mail-sidebar/spec.md#requirement-link-and-unlink-actions-from-the-sidebar
 */
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcLoadingIcon } from '@nextcloud/vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import CreateConnectedObjectDialog from '../../dialogs/mail/CreateConnectedObjectDialog.vue'
import ObjectRow from './ObjectRow.vue'
import { createDialogFor } from '../createDialogs.js'
import { displayNameOf } from '../displayNames.js'
import { objectHandler, objectIdentifier, objectStatus } from '../objectRow.js'
import { itemName } from '../sentenceName.js'

/**
 * Fetch the registers, to find the register of each schema.
 *
 * @return {Promise<object>} The axios response.
 */
function fetchRegisters() {
	return axios.get(generateUrl('/apps/openregister/api/registers'), {
		params: { _limit: 500 },
	})
}

/**
 * The per-schema search state the tab starts from: no terms, results,
 * running searches, shown results or pending debounce timers.
 *
 * @return {object} The state.
 */
function searchState() {
	return {
		searchTerms: {},
		searchResults: {},
		searching: {},
		visibleResults: {},
		debounceTimers: {},
	}
}

export default {
	name: 'ActionsTab',
	components: {
		NcLoadingIcon,
		CreateConnectedObjectDialog,
		Magnify,
		ObjectRow,
		Plus,
	},

	props: {
		accountId: { type: Number, default: null },
		messageId: { type: Number, default: null },
	},

	data() {
		return {
			schemas: [],
			loading: true,
			...searchState(),
			registerCache: {},
			creating: {},
			linking: {},
			// The words of the last finished search per schema, for "no match".
			searchedTerms: {},
			// Display names of handlers by user id, '' while being read.
			names: {},
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
		itemName,
		objectIdentifier,
		objectStatus,
		objectHandler,

		/**
		 * A handler's display name once read, else nothing yet.
		 *
		 * @param {string} uid The handler's user id.
		 * @return {string}
		 * @spec openspec/specs/mail-sidebar/spec.md
		 */
		nameOf(uid) {
			return uid ? (this.names[uid] || '') : ''
		},

		/**
		 * Read the display names of the handlers of these objects.
		 *
		 * @param {Array<object>} objects The objects shown.
		 * @spec openspec/specs/mail-sidebar/spec.md
		 */
		resolveNames(objects) {
			for (const uid of new Set((objects || []).map(objectHandler).filter(Boolean))) {
				if (this.names[uid] !== undefined) continue
				this.names[uid] = ''
				displayNameOf(uid).then((name) => {
					this.names[uid] = name
				})
			}
		},

		/**
		 * Search the first kind of item for a value found in the mail.
		 *
		 * @param {string} term The value to search for.
		 * @spec openspec/specs/mail-sidebar/spec.md
		 */
		searchFor(term) {
			const schema = this.schemas[0]
			if (!schema) return
			this.searchTerms[schema.id] = term
			this.visibleResults[schema.id] = true
			this.searchObjects(schema)
			this.$nextTick(() => document.getElementById('or-action-search-' + schema.id)?.focus())
		},

		/**
		 * The object being connected in this block, shown as the picked row
		 * while the connection is made; null when none.
		 *
		 * @param {object} schema The block's schema.
		 * @return {object|null}
		 * @spec openspec/specs/mail-sidebar/spec.md
		 */
		pickedObject(schema) {
			const linking = this.linking[schema.id]
			return linking && typeof linking === 'object' ? linking : null
		},

		/**
		 * Whether a finished search for the typed words found nothing.
		 *
		 * @param {object} schema The block's schema.
		 * @return {boolean}
		 * @spec openspec/specs/mail-sidebar/spec.md
		 */
		noMatch(schema) {
			const term = this.searchTerms[schema.id] || ''
			return term !== ''
				&& this.searchedTerms[schema.id] === term
				&& !this.searching[schema.id]
				&& (this.searchResults[schema.id] || []).length === 0
		},

		/**
		 * A schema block is busy while it is creating a new record or
		 * connecting an existing one — used to swap the search UI for a
		 * spinner so the user sees the work happening.
		 *
		 * @param {object} schema
		 */
		isBusy(schema) {
			return !!this.creating[schema.id] || !!this.linking[schema.id]
		},

		/**
		 * @param {object} obj
		 * @spec openspec/specs/mail-sidebar/spec.md
		 */
		objectName(obj) {
			return (
				obj['@self']?.name
				|| obj._name
				|| obj.title
				|| obj.name
				|| obj.naam
				|| obj.id
			)
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
					fetchRegisters(),
				])
				this.cacheRegisters(regResponse.data?.results || regResponse.data || [])
				this.showMailSchemas(allSchemas)
			} catch (err) {
				console.error('[ActionsTab] Failed to load schemas:', err)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Remember which register holds each schema.
		 *
		 * @param {Array<object>} registers The registers.
		 * @return {void}
		 */
		cacheRegisters(registers) {
			for (const reg of registers) {
				for (const schemaId of reg.schemas || []) {
					this.registerCache[schemaId] = reg
				}
			}
		},

		/**
		 * Offer the schemas that list mail among their linked types, and load
		 * the first results of each.
		 *
		 * @param {Array<object>} allSchemas Every schema.
		 * @return {void}
		 */
		showMailSchemas(allSchemas) {
			this.schemas = allSchemas.filter((s) => {
				const lt = s.configuration?.linkedTypes || []
				return lt.includes('mail')
			})
			for (const schema of this.schemas) {
				this.loadInitialResults(schema)
			}
		},

		/**
		 * The objects endpoint of a schema in a register.
		 *
		 * @param {object} register The register
		 * @param {object} schema The schema
		 * @return {string} The URL
		 * @spec openspec/specs/mail-sidebar/spec.md
		 */
		objectsUrl(register, schema) {
			return generateUrl(
				'/apps/openregister/api/objects/{register}/{schema}',
				{
					register: register.id,
					schema: schema.id,
				},
			)
		},

		/**
		 * Fetch up to 20 objects of a schema, narrowed by the given query, and
		 * keep them as the schema's results.
		 *
		 * @param {object} register The register
		 * @param {object} schema The schema
		 * @param {object} params Extra query parameters
		 * @return {Promise<void>}
		 * @spec openspec/specs/mail-sidebar/spec.md
		 */
		async fetchResults(register, schema, params) {
			const response = await axios.get(this.objectsUrl(register, schema), {
				params: { ...params, _limit: 20, _extend: 'status' },
				timeout: 10000,
			})
			const results = response.data?.results || response.data || []
			this.searchResults[schema.id] = results
			this.resolveNames(results)
		},

		/**
		 * @param {object} schema
		 * @spec openspec/specs/mail-sidebar/spec.md
		 */
		async loadInitialResults(schema) {
			const register = this.registerCache[schema.id]
			if (!register) return

			try {
				await this.fetchResults(register, schema, {})
			} catch (err) {
				console.error(
					'[ActionsTab] Initial load failed for',
					schema.title,
					err,
				)
			}
		},

		/**
		 * @param {object} schema
		 * @spec openspec/specs/mail-sidebar/spec.md
		 */
		showResults(schema) {
			this.visibleResults[schema.id] = true
		},

		/**
		 * @param {object} schema
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
		 * @param {object} schema
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

			this.searching[schema.id] = true
			this.visibleResults[schema.id] = true
			try {
				await this.fetchResults(register, schema, { _search: term })
				this.searchedTerms[schema.id] = term
			} catch (err) {
				console.error('[ActionsTab] Search failed:', err)
			} finally {
				this.searching[schema.id] = false
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
				const response = await axios.get(
					generateUrl('/apps/openregister/api/schemas'),
					{
						params: { _limit: limit, _page: page },
						timeout: 15000,
					},
				)
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
		 * @param {object} schema
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
			const url = generateUrl('/apps/mail/api/messages/{id}/body', {
				id: this.messageId,
			})
			const response = await axios.get(url, { timeout: 10000 })
			const envelope = response.data?.data || response.data
			this.envelopeCache[this.messageId] = envelope
			return envelope
		},

		/**
		 * Build the placeholder map available to mailObjectTemplate values.
		 *
		 * @param {object} envelope
		 * @spec openspec/specs/integration-email/spec.md
		 */
		buildPlaceholders(envelope) {
			const from = (envelope.from || [])[0] || {}
			const sentAt = envelope.dateInt
				? new Date(envelope.dateInt * 1000)
				: new Date()
			const due = new Date(sentAt.getTime() + 30 * 24 * 60 * 60 * 1000)
			const isoDate = (d) => d.toISOString().slice(0, 10)
			let preview = envelope.body || ''
			if (envelope.hasHtmlBody) {
				preview =
					new DOMParser().parseFromString(preview, 'text/html').body
						.textContent || ''
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
		 * @param {object} template
		 * @param {object} placeholders
		 * @spec openspec/specs/integration-email/spec.md
		 */
		applyTemplate(template, placeholders) {
			const data = {}
			for (const [field, value] of Object.entries(template)) {
				if (typeof value === 'string') {
					data[field] = value.replace(/\{\{(\w+)\}\}/g, (match, key) =>
						key in placeholders ? placeholders[key] : match,
					)
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
		 * @param {object} schema
		 * @spec openspec/specs/integration-email/spec.md
		 */
		async openCreate(schema) {
			if (!this.accountId || !this.messageId || this.creating[schema.id])
				return
			if (!this.registerCache[schema.id]) return

			this.creating[schema.id] = true
			try {
				const envelope = await this.fetchEnvelope()
				const data = this.applyTemplate(
					schema.configuration.mailObjectTemplate,
					this.buildPlaceholders(envelope),
				)
				const offered = createDialogFor(this.registerCache[schema.id], schema)
				if (offered) {
					this.creating[schema.id] = false
					await this.createWithOfferedDialog(offered, schema, data)
					return
				}
				this.createDialog = { show: true, schema, data }
			} catch (err) {
				showError(t('openregister', 'Could not read this email'))
				console.error('[ActionsTab] Open create failed:', err)
			} finally {
				this.creating[schema.id] = false
			}
		},

		/**
		 * Create through the dialog the schema's own app offers, then connect
		 * the mail to what it created. A cancel connects nothing.
		 *
		 * @param {object} offered The app's entry, with `open`.
		 * @param {object} schema The schema to create.
		 * @param {object} data The mail template filled from the open mail.
		 * @spec openspec/specs/mail-sidebar/spec.md
		 */
		async createWithOfferedDialog(offered, schema, data) {
			let created
			try {
				created = await offered.open({
					initialData: data,
					register: this.registerCache[schema.id],
					schema,
					mail: { accountId: this.accountId, messageId: this.messageId },
				})
			} catch {
				showError(t('openregister', 'Failed to create {name} from email', { name: itemName(schema) }))
				return
			}
			if (created && (created.id || created.uuid)) {
				await this.linkObject(schema, created)
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
		 * @param {object} data
		 * @spec openspec/specs/integration-email/spec.md
		 */
		async submitCreate(data) {
			const schema = this.createDialog.schema
			if (!schema) return
			const register = this.registerCache[schema.id]
			if (!register) return

			this.createSaving = true
			try {
				const response = await axios.post(
					this.objectsUrl(register, schema),
					data,
					{ timeout: 15000 },
				)
				await this.linkObject(schema, response.data)
				this.createDialog = { show: false, schema: null, data: {} }
			} catch (err) {
				const detail = err.response?.data?.error || err.response?.data || ''
				showError(
					t('openregister', 'Failed to create {name} from email', {
						name: itemName(schema),
					}),
				)
				console.error('[ActionsTab] Create from email failed:', detail, err)
			} finally {
				this.createSaving = false
			}
		},

		/**
		 * Record the connection in the email link table as well, which the
		 * object's own Email tab (the `email` integration) lists.
		 *
		 * @param {object} schema The schema of the object
		 * @param {string} objectUuid The object's uuid
		 * @return {Promise<void>}
		 * @spec openspec/specs/mail-sidebar/spec.md#requirement-link-and-unlink-actions-from-the-sidebar
		 */
		async recordEmailLink(schema, objectUuid) {
			const register = this.registerCache[schema.id]
			if (!register) return

			await axios.post(
				generateUrl(
					'/apps/openregister/api/objects/{register}/{schema}/{uuid}/emails',
					{ register: register.id, schema: schema.id, uuid: objectUuid },
				),
				{ mailAccountId: this.accountId, messageId: String(this.messageId) },
			)
		},

		/**
		 * @param {object} schema
		 * @param {object} obj
		 * @spec openspec/specs/mail-sidebar/spec.md#requirement-link-and-unlink-actions-from-the-sidebar
		 */
		async linkObject(schema, obj) {
			const objectUuid = obj.id || obj.uuid || obj._uuid
			if (!objectUuid || !this.accountId || !this.messageId) return

			const mailRef = `${this.accountId}/${this.messageId}`
			// The picked object itself, so the block shows it while connecting.
			this.linking[schema.id] = obj
			try {
				const url = generateUrl(
					'/apps/openregister/api/objects/{uuid}/_linked/mail',
					{
						uuid: objectUuid,
					},
				)
				await axios.post(url, { id: mailRef })
				await this.recordEmailLink(schema, objectUuid)
				showSuccess(
					t('openregister', 'Connected to {name}', {
						name: this.objectName(obj),
					}),
				)

				// Clear search and hide results
				this.searchTerms[schema.id] = ''
				this.visibleResults[schema.id] = false
				this.loadInitialResults(schema)

				this.$emit('linked', { schema, object: obj })
			} catch (err) {
				showError(t('openregister', 'Failed to connect'))
				console.error('[ActionsTab] Link failed:', err)
			} finally {
				this.linking[schema.id] = false
			}
		},
	},
}
</script>
