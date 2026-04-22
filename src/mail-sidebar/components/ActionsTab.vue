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
			</div>
		</div>
	</div>
</template>

<script>
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
		}
	},
	async created() {
		await this.loadSchemas()
	},
	methods: {
		t,
		objectName(obj) {
			return obj['@self']?.name
				|| obj._name
				|| obj.title
				|| obj.name
				|| obj.naam
				|| obj.id
		},
		async loadSchemas() {
			this.loading = true
			try {
				// Load schemas and registers in parallel
				const [schemaResponse, regResponse] = await Promise.all([
					axios.get(generateUrl('/apps/openregister/api/schemas'), { params: { _limit: 100 } }),
					axios.get(generateUrl('/apps/openregister/api/registers'), { params: { _limit: 100 } }),
				])

				const allSchemas = schemaResponse.data?.results || schemaResponse.data || []
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
		showResults(schema) {
			this.$set(this.visibleResults, schema.id, true)
		},
		debounceSearch(schema) {
			if (this.debounceTimers[schema.id]) {
				clearTimeout(this.debounceTimers[schema.id])
			}
			this.debounceTimers[schema.id] = setTimeout(() => {
				this.searchObjects(schema)
			}, 300)
		},
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
