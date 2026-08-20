<template>
	<div class="or-tab-entities">
		<div v-if="loading" class="or-tab-loading">
			{{ t('openregister', 'Loading entities...') }}
		</div>
		<div v-else-if="entities.length === 0" class="or-tab-empty">
			{{ t('openregister', 'No entities detected for this email.') }}
		</div>
		<div v-else>
			<div
				v-for="(group, type) in groupedEntities"
				:key="type"
				class="or-entity-group">
				<h4 class="or-entity-group-title">
					{{ formatType(type) }}
				</h4>
				<ul class="or-entity-list">
					<li
						v-for="entity in group"
						:key="entity.id"
						class="or-entity-item">
						<span class="or-entity-value">{{ entity.value }}</span>
						<span v-if="entity.confidence" class="or-entity-confidence">
							{{ Math.round(entity.confidence * 100) }}%
						</span>
					</li>
				</ul>
			</div>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
/**
 * Entities tab — surfaces the entities found *in this email*: the people and
 * addresses on the envelope (from / to / cc) plus email addresses, phone
 * numbers, IBANs and links detected in the message body. Everything is
 * extracted from the message itself (via Mail's
 * /apps/mail/api/messages/{id}/body) so the list always reflects the open
 * email — never global PII from other messages.
 *
 * @spec openspec/specs/mail-sidebar/spec.md
 */
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

// Grounded extraction patterns — kept conservative to avoid false positives.
const EMAIL_RE = /[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/g
const IBAN_RE = /\b[A-Z]{2}\d{2}(?:[ ]?[A-Z0-9]{4}){2,8}\b/g
const URL_RE = /\bhttps?:\/\/[^\s<>"')]+/gi
// Phone: optional +/00 country code, then 9–13 digits with spaces/dashes/parens.
const PHONE_RE = /(?:(?:\+|00)\d{1,3}[\s-]?)?(?:\(0\)|0)?(?:[\s-]?\d){8,12}\d/g

export default {
	name: 'EntitiesTab',
	props: {
		accountId: { type: Number, default: null },
		messageId: { type: Number, default: null },
	},

	data() {
		return {
			entities: [],
			loading: false,
		}
	},

	computed: {
		/**
		 * @spec openspec/specs/mail-sidebar/spec.md
		 */
		groupedEntities() {
			const groups = {}
			for (const entity of this.entities) {
				const type = entity.type || 'unknown'
				if (!groups[type]) {
					groups[type] = []
				}
				groups[type].push(entity)
			}
			return groups
		},
	},

	watch: {
		/**
		 * @spec openspec/specs/mail-sidebar/spec.md
		 */
		messageId() {
			this.loadEntities()
		},
	},

	created() {
		this.loadEntities()
	},

	methods: {
		t,
		/**
		 * @param type
		 * @spec openspec/specs/mail-sidebar/spec.md
		 */
		formatType(type) {
			const labels = {
				PERSON: t('openregister', 'People'),
				EMAIL: t('openregister', 'Email Addresses'),
				PHONE: t('openregister', 'Phone Numbers'),
				IBAN: t('openregister', 'IBANs'),
				URL: t('openregister', 'Links'),
				unknown: t('openregister', 'Other'),
			}
			return labels[type] || type
		},

		/**
		 * Extract the entities present in the current email — envelope people
		 * and addresses plus body-level emails / phones / IBANs / links.
		 *
		 * @spec openspec/specs/mail-sidebar/spec.md
		 */
		async loadEntities() {
			if (!this.messageId) {
				this.entities = []
				return
			}

			this.loading = true
			try {
				const url = generateUrl('/apps/mail/api/messages/{id}/body', {
					id: this.messageId,
				})
				const response = await axios.get(url, { timeout: 10000 })
				const envelope = response.data?.data || response.data || {}
				this.entities = this.extractEntities(envelope)
			} catch (err) {
				console.error('[EntitiesTab] Load failed:', err)
				this.entities = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Build the deduplicated entity list from one message envelope.
		 *
		 * @param envelope
		 * @spec openspec/specs/mail-sidebar/spec.md
		 */
		extractEntities(envelope) {
			const seen = new Set()
			const out = []
			const add = (type, value) => {
				const clean = (value || '').trim()
				if (!clean) return
				const key = `${type}:${clean.toLowerCase()}`
				if (seen.has(key)) return
				seen.add(key)
				out.push({ id: key, type, value: clean })
			}

			// Envelope participants — people (display names) and their addresses.
			const participants = [
				...(envelope.from || []),
				...(envelope.to || []),
				...(envelope.cc || []),
			]
			for (const p of participants) {
				if (p?.email) add('EMAIL', p.email)
				if (p?.label && p.label !== p.email) add('PERSON', p.label)
			}

			// Body — plain text (strip HTML when needed).
			let body = envelope.body || ''
			if (envelope.hasHtmlBody) {
				body =
					new DOMParser().parseFromString(body, 'text/html').body
						.textContent || ''
			}
			for (const m of body.match(EMAIL_RE) || []) add('EMAIL', m)
			for (const m of body.match(IBAN_RE) || [])
				add('IBAN', m.replace(/\s+/g, ''))
			for (const m of body.match(URL_RE) || []) add('URL', m)
			for (const m of body.match(PHONE_RE) || []) {
				// Require at least 9 digits to weed out times / short numbers.
				if (m.replace(/\D/g, '').length >= 9) add('PHONE', m.trim())
			}

			return out
		},
	},
}
</script>
