<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->
<template>
	<div class="mail-thread-tab">
		<div v-if="loading" class="mail-thread-tab__loading">
			<NcLoadingIcon :size="44" />
		</div>

		<TabErrorState
			v-else-if="error"
			:name="t('openregister', 'Failed to load the conversation')"
			:description="errorMessage" />

		<NcEmptyContent
			v-else-if="entries.length === 0"
			:name="t('openregister', 'This message is not part of a conversation')">
			<template #icon>
				<EmailOutline :size="44" />
			</template>
		</NcEmptyContent>

		<ol v-else class="mail-thread-tab__list">
			<li
				v-for="entry in entries"
				:key="entry.key"
				class="mail-thread-tab__item"
				:class="{
					'mail-thread-tab__item--current': entry.current,
					'mail-thread-tab__item--gap': entry.gap,
				}"
				:style="{ '--mail-thread-depth': entry.depth }"
				:aria-current="entry.current ? 'true' : null">
				<span v-if="entry.gap" class="mail-thread-tab__entry">
					<span class="mail-thread-tab__subject">{{ t('openregister', 'Not available') }}</span>
					<span class="mail-thread-tab__meta">{{ entry.messageId }}</span>
				</span>
				<span v-else-if="entry.current" class="mail-thread-tab__entry">
					<span class="mail-thread-tab__subject">{{ entry.subject }}</span>
					<span class="mail-thread-tab__meta">{{ entry.meta }}</span>
				</span>
				<a
					v-else
					:href="entry.href"
					class="mail-thread-tab__entry mail-thread-tab__link">
					<span class="mail-thread-tab__subject">{{ entry.subject }}</span>
					<span class="mail-thread-tab__meta">{{ entry.meta }}</span>
				</a>
			</li>
		</ol>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import EmailOutline from 'vue-material-design-icons/EmailOutline.vue'
import TabErrorState from '../shared/TabErrorState.vue'
import { fileTab } from './fileTabFetch.js'

/**
 * How deep each message sits: one below the message it answers, when that
 * message is in the thread; at the top otherwise.
 *
 * @param {Array<object>} thread The thread in date order
 * @return {Map<string, number>} The depth by message id
 */
export function depthsOf(thread) {
	const parents = new Map(thread.map((node) => [node.messageId, node.parent]))
	const depths = new Map()
	const depthOf = (messageId, seen) => {
		if (depths.has(messageId)) {
			return depths.get(messageId)
		}
		const parent = parents.get(messageId)
		const depth = parent && parents.has(parent) && !seen.has(parent)
			? depthOf(parent, new Set([...seen, messageId])) + 1
			: 0
		depths.set(messageId, depth)
		return depth
	}
	for (const node of thread) {
		depthOf(node.messageId, new Set([node.messageId]))
	}
	return depths
}

/**
 * The Files sidebar tab showing the conversation a collected `.eml` file
 * belongs to: every message in date order, the shown one marked, each other
 * readable one opening on click.
 *
 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md#requirement-a-message-is-read-with-the-messages-around-it-req-rth-011
 */
export default {
	name: 'MailThreadTab',

	components: {
		TabErrorState,
		NcEmptyContent,
		NcLoadingIcon,
		EmailOutline,
	},

	mixins: [fileTab('fetchThread')],

	data() {
		return {
			thread: [],
			position: null,
		}
	},

	computed: {
		/**
		 * The thread as the list renders it.
		 *
		 * @return {Array<object>} One entry per message or gap
		 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md#requirement-a-message-is-read-with-the-messages-around-it-req-rth-011
		 */
		entries() {
			const depths = depthsOf(this.thread)
			return this.thread.map((node, index) => ({
				key: node.messageId || `gap-${index}`,
				messageId: node.messageId,
				subject: node.subject,
				meta: [node.from, this.formatDate(node.sentAt)].filter(Boolean).join(' · '),
				gap: node.gap === true,
				current: index === this.position,
				depth: depths.get(node.messageId) || 0,
				href: node.fileId ? generateUrl('/f/{fileId}', { fileId: node.fileId }) : null,
			}))
		},
	},

	methods: {
		t,

		/**
		 * Load the thread of the shown file.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md#requirement-a-message-is-read-with-the-messages-around-it-req-rth-011
		 */
		async fetchThread() {
			this.thread = []
			this.position = null
			this.loading = true
			this.error = false
			this.errorMessage = ''
			try {
				const url = generateUrl('/apps/openregister/api/files/{fileId}/thread', { fileId: this.fileId })
				const response = await axios.get(url)
				this.thread = Array.isArray(response.data?.thread) ? response.data.thread : []
				this.position = Number.isInteger(response.data?.position) ? response.data.position : null
			} catch (err) {
				this.error = true
				this.errorMessage = err.response?.data?.error || err.message
			} finally {
				this.loading = false
			}
		},

		/**
		 * A sent date in the reader's locale.
		 *
		 * @param {string|null} sentAt The ISO date
		 * @return {string} The date, or an empty string without one
		 * @spec exclude date display helper, UI plumbing
		 */
		formatDate(sentAt) {
			if (!sentAt) {
				return ''
			}
			const date = new Date(sentAt)
			return Number.isNaN(date.getTime()) ? '' : date.toLocaleString()
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../styles/components.scss' as *;

.mail-thread-tab {
	padding: 10px;
}

.mail-thread-tab__loading {
	display: flex;
	justify-content: center;
	align-items: center;
	min-height: 100px;
}

.mail-thread-tab__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.mail-thread-tab__item {
	margin: 0;
	padding: 0 0 0 calc(var(--mail-thread-depth, 0) * 16px);
}

.mail-thread-tab__entry {
	display: block;
	padding: 8px 12px;
	border-radius: var(--border-radius-large, 6px);
	color: var(--color-main-text);
	text-decoration: none;
}

.mail-thread-tab__item--current .mail-thread-tab__entry {
	background-color: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
}

.mail-thread-tab__item--gap .mail-thread-tab__entry {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.mail-thread-tab__link:hover,
.mail-thread-tab__link:focus {
	background-color: var(--color-background-hover);
	outline: 2px solid var(--color-primary-element);
	outline-offset: -2px;
}

.mail-thread-tab__subject {
	display: block;
	font-weight: bold;
	margin-bottom: 2px;
	overflow-wrap: anywhere;
}

.mail-thread-tab__meta {
	display: block;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
	overflow-wrap: anywhere;
}

.mail-thread-tab__item--current .mail-thread-tab__meta {
	color: inherit;
}

@include material-design-icon-block;
</style>
