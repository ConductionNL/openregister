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
						@input="debounceSearch(schema)">
					<ul v-if="searchResults[schema.id] && searchResults[schema.id].length > 0" class="or-action-results">
						<li
							v-for="obj in searchResults[schema.id]"
							:key="obj.id"
							class="or-action-result"
							@click="linkObject(schema, obj)">
							<span class="or-action-result-name">{{ obj._name || obj.id }}</span>
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
			debounceTimers: {},
		}
	},
	async created() {
		await this.loadSchemas()
	},
	methods: {
		t,
		async loadSchemas() {
			this.loading = true
			try {
				const url = generateUrl('/apps/openregister/api/schemas')
				const response = await axios.get(url, { params: { _limit: 100 } })
				const results = response.data?.results || response.data || []
				this.schemas = results.filter((s) => {
					const lt = s.configuration?.linkedTypes || []
					return lt.includes('mail')
				})
			} catch (err) {
				console.error('[ActionsTab] Failed to load schemas:', err)
			} finally {
				this.loading = false
			}
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
			if (term.length < 2) {
				this.$set(this.searchResults, schema.id, [])
				return
			}

			this.$set(this.searching, schema.id, true)
			try {
				// Find register ID for this schema
				const regUrl = generateUrl('/apps/openregister/api/registers')
				const regResponse = await axios.get(regUrl, { params: { _limit: 100 } })
				const registers = regResponse.data?.results || regResponse.data || []
				const register = registers.find((r) => {
					const schemaIds = r.schemas || []
					return schemaIds.includes(schema.id)
				})

				if (!register) {
					this.$set(this.searchResults, schema.id, [])
					return
				}

				const url = generateUrl('/apps/openregister/api/objects/{register}/{schema}', {
					register: register.id,
					schema: schema.id,
				})
				const response = await axios.get(url, {
					params: { _search: term, _limit: 10 },
					timeout: 10000,
				})
				const results = response.data?.results || response.data || []
				this.$set(this.searchResults, schema.id, results)
			} catch (err) {
				console.error('[ActionsTab] Search failed:', err)
				this.$set(this.searchResults, schema.id, [])
			} finally {
				this.$set(this.searching, schema.id, false)
			}
		},
		async linkObject(schema, obj) {
			const objectUuid = obj.id || obj.uuid || obj._uuid
			if (!objectUuid || !this.accountId || !this.messageId) {
				return
			}

			const mailRef = `${this.accountId}/${this.messageId}`
			try {
				const url = generateUrl('/apps/openregister/api/objects/{uuid}/_linked/mail', {
					uuid: objectUuid,
				})
				await axios.post(url, { id: mailRef })
				showSuccess(t('openregister', 'Linked to {name}', { name: obj._name || objectUuid }))

				// Clear search
				this.$set(this.searchTerms, schema.id, '')
				this.$set(this.searchResults, schema.id, [])

				// Notify parent to refresh objects tab
				this.$emit('linked')
			} catch (err) {
				showError(t('openregister', 'Failed to link object'))
				console.error('[ActionsTab] Link failed:', err)
			}
		},
	},
}
</script>
