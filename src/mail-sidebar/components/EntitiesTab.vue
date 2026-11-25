<template>
	<div class="or-tab-entities">
		<div v-if="loading" class="or-tab-loading">
			{{ t('openregister', 'Loading entities...') }}
		</div>
		<div v-else-if="entities.length === 0" class="or-tab-empty">
			{{ t('openregister', 'No entities detected for this email.') }}
		</div>
		<ul v-else class="or-entity-list">
			<li
				v-for="entity in entities"
				:key="entity.id"
				class="or-entity-item">
				<component :is="iconFor(entity)" :size="18" class="or-entity-icon" />
				<span class="or-entity-value">{{ entity.value }}</span>
				<span class="or-entity-kind">{{ kindLabel(entity) }}</span>
				<button
					type="button"
					class="or-entity-search"
					:aria-label="t('openregister', 'Search for {value}', { value: entity.value })"
					@click="$emit('search', entity.value)">
					{{ t('openregister', 'Search') }}
				</button>
			</li>
		</ul>
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
import AccountOutline from 'vue-material-design-icons/AccountOutline.vue'
import BankOutline from 'vue-material-design-icons/BankOutline.vue'
import EmailOutline from 'vue-material-design-icons/EmailOutline.vue'
import LinkVariant from 'vue-material-design-icons/LinkVariant.vue'
import MapMarkerOutline from 'vue-material-design-icons/MapMarkerOutline.vue'
import PhoneOutline from 'vue-material-design-icons/PhoneOutline.vue'
import Pound from 'vue-material-design-icons/Pound.vue'
import { mailMessageTab } from '../composables/mailMessageTab.js'

// Grounded extraction patterns — kept conservative to avoid false positives.
const EMAIL_RE = /[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/g
const IBAN_RE = /\b[A-Z]{2}\d{2}(?:[ ]?[A-Z0-9]{4}){2,8}\b/g
const URL_RE = /\bhttps?:\/\/[^\s<>"')]+/gi
// Phone: optional +/00 country code, then 9–13 digits with spaces/dashes/parens.
/**
 * Fetch the body of a mail message.
 *
 * @param {number|string} messageId The message id
 * @return {Promise<object>} The message envelope
 */
async function fetchMessageBody(messageId) {
	const url = generateUrl('/apps/mail/api/messages/{id}/body', {
		id: messageId,
	})
	const response = await axios.get(url, { timeout: 10000 })
	return response.data?.data || response.data || {}
}

// A Dutch street address: a street name ending in a usual suffix, a house
// number, and optionally ", Place".
const ADDRESS_RE = /\b[A-Z][a-z]+(?:straat|laan|weg|plein|gracht|kade|dijk|singel|park|hof|steeg|pad|dreef|markt)\s\d+[a-z]?\b(?:,\s?[A-Z][a-z]+)?/g

const ICONS = {
	PERSON: AccountOutline,
	ADDRESS: MapMarkerOutline,
	EMAIL: EmailOutline,
	PHONE: PhoneOutline,
	IBAN: BankOutline,
	URL: LinkVariant,
}

const PHONE_RE = /(?:(?:\+|00)\d{1,3}[\s-]?)?(?:\(0\)|0)?(?:[\s-]?\d){8,12}\d/g

export default {
	name: 'EntitiesTab',
	components: { AccountOutline, BankOutline, EmailOutline, LinkVariant, MapMarkerOutline, PhoneOutline, Pound },
	mixins: [mailMessageTab('loadEntities')],
	emits: ['search'],

	data() {
		return {
			entities: [],
			loading: false,
		}
	},

	methods: {
		t,
		/**
		 * What a found value is, in a word ("sender", "address").
		 *
		 * @param {object} entity The found value.
		 * @return {string}
		 * @spec openspec/specs/mail-sidebar/spec.md
		 */
		kindLabel(entity) {
			if (entity.role === 'sender') return t('openregister', 'sender')
			const labels = {
				PERSON: t('openregister', 'person'),
				ADDRESS: t('openregister', 'address'),
				EMAIL: t('openregister', 'email address'),
				PHONE: t('openregister', 'phone number'),
				// An IBAN is called IBAN in every language: not wrapped, see CLAUDE.md (untranslatable strings).
				IBAN: 'IBAN',
				URL: t('openregister', 'link'),
			}
			return labels[entity.type] || t('openregister', 'other')
		},

		/**
		 * The icon of a found value, by its kind.
		 *
		 * @param {object} entity The found value.
		 * @return {object}
		 * @spec openspec/specs/mail-sidebar/spec.md
		 */
		iconFor(entity) {
			return ICONS[entity.type] || Pound
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
				const envelope = await fetchMessageBody(this.messageId)
				this.entities = this.extractEntities(envelope)
			} catch (err) {
				this.showLoadFailure(err)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Log a failed load and show no entities.
		 *
		 * @param {Error} err What went wrong
		 * @return {void}
		 */
		showLoadFailure(err) {
			console.error('[EntitiesTab] Load failed:', err)
			this.entities = []
		},

		/**
		 * Build the deduplicated entity list from one message envelope.
		 *
		 * @param {object} envelope
		 * @spec openspec/specs/mail-sidebar/spec.md
		 */
		extractEntities(envelope) {
			const seen = new Set()
			const out = []
			const add = (type, value, role) => {
				const clean = (value || '').trim()
				if (!clean) return
				const key = `${type}:${clean.toLowerCase()}`
				if (seen.has(key)) return
				seen.add(key)
				out.push(role ? { id: key, type, value: clean, role } : { id: key, type, value: clean })
			}

			// The sender first: the board lists who wrote before what they wrote.
			for (const p of envelope.from || []) {
				if (p?.label && p.label !== p.email) add('PERSON', p.label, 'sender')
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
			for (const m of body.match(ADDRESS_RE) || []) add('ADDRESS', m)
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
