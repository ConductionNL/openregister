<template>
	<div v-if="visible" class="or-mail-link-dialog-overlay" @click.self="close">
		<div
			class="or-mail-link-dialog"
			role="dialog"
			:aria-label="t('openregister', 'Link to Object')"
			@keydown.escape="close">
			<div class="or-mail-link-dialog__header">
				<h3>{{ t('openregister', 'Link to Object') }}</h3>
				<button
					class="or-mail-link-dialog__close"
					:aria-label="t('openregister', 'Cancel')"
					@click="close">
					&times;
				</button>
			</div>
			<div class="or-mail-link-dialog__body">
				<input
					ref="searchInput"
					v-model="query"
					type="text"
					class="or-mail-link-dialog__search"
					:placeholder="t('openregister', 'Search by title or UUID...')"
					:aria-label="t('openregister', 'Search by title or UUID...')"
					@input="onSearchInput" />
				<div v-if="searching" class="or-mail-loading">
					<span class="icon-loading-small" />
				</div>
				<div v-else-if="searchResults.length === 0 && query.length > 0 && !searching" class="or-mail-empty">
					<p>{{ t('openregister', 'No objects found') }}</p>
					<p class="or-mail-hint">
						{{ t('openregister', 'Try searching by UUID or with different keywords') }}
					</p>
				</div>
				<ul v-else class="or-mail-link-dialog__results">
					<li
						v-for="result in searchResults"
						:key="result.id || result.uuid"
						class="or-mail-link-dialog__result"
						:class="{ 'or-mail-link-dialog__result--linked': isAlreadyLinked(result) }"
						tabindex="0"
						:aria-label="resultAriaLabel(result)"
						@click="selectResult(result)"
						@keydown.enter="selectResult(result)">
						<span class="or-mail-link-dialog__result-title">
							{{ result.title || result.uuid }}
						</span>
						<span v-if="result.schemaTitle" class="or-mail-link-dialog__result-meta">
							{{ result.schemaTitle }} - {{ result.registerTitle }}
						</span>
						<span v-if="isAlreadyLinked(result)" class="or-mail-link-dialog__already-linked">
							{{ t('openregister', 'Already linked') }}
						</span>
					</li>
				</ul>
			</div>
			<div v-if="selectedResult" class="or-mail-link-dialog__footer">
				<button class="or-mail-btn or-mail-btn--secondary" @click="close">
					{{ t('openregister', 'Cancel') }}
				</button>
				<button class="or-mail-btn or-mail-btn--primary" @click="confirmLink">
					{{ t('openregister', 'Link') }}
				</button>
			</div>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { searchObjects } from '../api/emailLinks.js'

export default {
	name: 'LinkObjectDialog',
	props: {
		visible: {
			type: Boolean,
			default: false,
		},
		linkedObjectUuids: {
			type: Array,
			default: () => [],
		},
	},
	data() {
		return {
			query: '',
			searchResults: [],
			searching: false,
			selectedResult: null,
			debounceTimer: null,
		}
	},
	watch: {
		visible(val) {
			if (val) {
				this.$nextTick(() => {
					if (this.$refs.searchInput) {
						this.$refs.searchInput.focus()
					}
				})
			} else {
				this.reset()
			}
		},
	},
	methods: {
		t,
		onSearchInput() {
			if (this.debounceTimer) {
				clearTimeout(this.debounceTimer)
			}
			this.selectedResult = null
			if (this.query.length < 2) {
				this.searchResults = []
				return
			}
			this.debounceTimer = setTimeout(() => this.doSearch(), 300)
		},
		async doSearch() {
			this.searching = true
			try {
				const data = await searchObjects(this.query)
				this.searchResults = (data.results || data || []).map((obj) => ({
					id: obj.id,
					uuid: obj.uuid,
					title: obj.title || obj.uuid,
					registerId: obj.register,
					registerTitle: obj.registerTitle || '',
					schemaId: obj.schema,
					schemaTitle: obj.schemaTitle || '',
				}))
			} catch {
				this.searchResults = []
			} finally {
				this.searching = false
			}
		},
		isAlreadyLinked(result) {
			return this.linkedObjectUuids.includes(result.uuid)
		},
		selectResult(result) {
			if (this.isAlreadyLinked(result)) {
				return
			}
			this.selectedResult = result
		},
		resultAriaLabel(result) {
			const title = result.title || result.uuid
			if (this.isAlreadyLinked(result)) {
				return `${title} - ${t('openregister', 'Already linked')}`
			}
			return title
		},
		confirmLink() {
			if (this.selectedResult) {
				this.$emit('link', this.selectedResult)
				this.close()
			}
		},
		close() {
			this.$emit('close')
		},
		reset() {
			this.query = ''
			this.searchResults = []
			this.searching = false
			this.selectedResult = null
		},
	},
}
</script>
