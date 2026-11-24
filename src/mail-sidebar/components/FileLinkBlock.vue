<template>
	<div class="or-action-block" :class="{ 'or-action-block--busy': busy }">
		<label class="or-action-label" :for="inputId">
			{{ block.title }}
		</label>
		<div v-if="busy" class="or-action-connecting" role="status">
			<NcLoadingIcon :size="20" />
			<span>{{ t('openregister', 'Connecting…') }}</span>
		</div>
		<div v-else class="or-action-search">
			<Magnify :size="20" class="or-action-search__icon" />
			<input
				:id="inputId"
				v-model="term"
				type="search"
				class="or-action-input"
				:placeholder="t('openregister', 'Number or words')"
				@input="debounceSearch" />
			<ul
				v-if="results.length > 0"
				class="or-action-results"
				role="listbox"
				:aria-label="t('openregister', 'Search Results')">
				<li
					v-for="item in results"
					:key="item.id"
					class="or-action-result"
					role="option"
					tabindex="0"
					:aria-selected="false"
					@click="connect(item)"
					@keydown.enter="connect(item)"
					@keydown.space.prevent="connect(item)">
					<ObjectRow
						:kind="block.title"
						:identifier="item.identifier"
						:name="item.title"
						:status="item.status"
						:handler="item.handler"
						:term="term" />
				</li>
			</ul>
			<p v-else-if="searchedTerm && searchedTerm === term && !searching" class="or-action-nomatch">
				{{ t('openregister', 'No {name} matches “{term}”.', { name: block.name || block.title, term }) }}
			</p>
			<div v-if="searching" class="or-action-searching">
				{{ t('openregister', 'Searching...') }}
			</div>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcLoadingIcon } from '@nextcloud/vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import ObjectRow from './ObjectRow.vue'
import { connectMail, searchItems } from '../fileLinks.js'

/**
 * A Mail-panel block over another app's items, connected through files
 * (`mailBlocks` entry with `type: "fileLink"`): search the items, and on a
 * pick save the mail and its attachments to Files and link them to the item.
 *
 * @spec openspec/specs/mail-sidebar/spec.md
 */
export default {
	name: 'FileLinkBlock',
	components: { Magnify, NcLoadingIcon, ObjectRow },
	props: {
		block: { type: Object, required: true },
		accountId: { type: Number, default: null },
		messageId: { type: Number, default: null },
	},
	emits: ['linked'],
	data() {
		return { term: '', results: [], searching: false, searchedTerm: '', busy: false, timer: null }
	},
	computed: {
		inputId() {
			return 'or-action-search-' + this.block.key
		},
	},
	watch: {
		messageId() {
			this.term = ''
			this.results = []
			this.searchedTerm = ''
		},
	},
	methods: {
		t,
		/**
		 * Search after a short pause in typing.
		 *
		 * @return {void}
		 */
		debounceSearch() {
			clearTimeout(this.timer)
			this.timer = setTimeout(() => this.search(), 300)
		},
		/**
		 * Search the block's items for the typed words.
		 *
		 * @return {Promise<void>}
		 */
		async search() {
			const term = this.term.trim()
			if (term === '') {
				this.results = []
				return
			}
			this.searching = true
			try {
				this.results = await searchItems(this.block, term)
			} catch (err) {
				this.results = []
				console.error('[FileLinkBlock] Search failed:', err)
			} finally {
				this.searchedTerm = this.term
				this.searching = false
			}
		},
		/**
		 * Connect the open mail to an item.
		 *
		 * @param {object} item The picked item.
		 * @return {Promise<void>}
		 */
		async connect(item) {
			if (!this.accountId || !this.messageId || this.busy) return
			this.busy = true
			this.results = []
			try {
				const response = await axios.get(generateUrl('/apps/mail/api/messages/{id}/body', { id: this.messageId }))
				const message = response.data?.data || response.data || {}
				const attachments = (message.attachments || []).map((a) => ({ attachmentId: a.id, fileName: a.fileName }))
				const connection = await connectMail(this.block, item, { accountId: this.accountId, messageId: this.messageId, subject: message.subject || '', attachments })
				this.term = ''
				this.$emit('linked', { kind: connection.kind, identifier: connection.identifier, name: connection.title })
			} catch (err) {
				showError(t('openregister', 'Could not connect the mail to {name}', { name: item.title }))
				console.error('[FileLinkBlock] Connect failed:', err)
			} finally {
				this.busy = false
			}
		},
	},
}
</script>
