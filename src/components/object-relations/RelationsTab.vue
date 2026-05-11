<!--
SPDX-FileCopyrightText: 2026 Conduction <info@conduction.nl>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="relations-tab">
		<!-- Type filter chips -->
		<div v-if="!loading && relations.length > 0" class="relations-tab__filters">
			<NcCheckboxRadioSwitch v-for="type in availableTypes"
				:key="type"
				:checked="selectedTypes.includes(type)"
				type="button"
				@update:checked="toggleType(type)">
				{{ typeLabels[type] || type }}
			</NcCheckboxRadioSwitch>
		</div>

		<!-- Loading state -->
		<div v-if="loading" class="relations-tab__loading">
			<NcLoadingIcon :size="44" />
		</div>

		<!-- Error state -->
		<NcEmptyContent v-else-if="error"
			:name="t('openregister', 'Failed to load related entities')"
			:description="errorMessage">
			<template #icon>
				<AlertCircleOutline :size="44" />
			</template>
		</NcEmptyContent>

		<!-- Empty state -->
		<NcEmptyContent v-else-if="visibleRelations.length === 0"
			:name="t('openregister', 'No related entities')"
			:description="t('openregister', 'Linked emails, calendar events, contacts and Deck cards will appear here.')">
			<template #icon>
				<LinkVariantOff :size="44" />
			</template>
		</NcEmptyContent>

		<!-- Timeline -->
		<ol v-else class="relations-tab__timeline">
			<li v-for="entry in visibleRelations"
				:key="entry.id"
				class="relations-tab__entry"
				:class="`relations-tab__entry--${entry.type}`">
				<div class="relations-tab__icon">
					<EmailOutline v-if="entry.type === 'email'" :size="20" />
					<CalendarOutline v-else-if="entry.type === 'event'" :size="20" />
					<AccountOutline v-else-if="entry.type === 'contact'" :size="20" />
					<TableLargePlus v-else-if="entry.type === 'deck'" :size="20" />
					<LinkVariant v-else :size="20" />
				</div>
				<div class="relations-tab__body">
					<div class="relations-tab__title">
						{{ entry.title || t('openregister', '(no title)') }}
					</div>
					<div class="relations-tab__meta">
						<span class="relations-tab__type-badge">{{ typeLabels[entry.type] || entry.type }}</span>
						<span v-if="entry.subtitle" class="relations-tab__separator">&middot;</span>
						<span v-if="entry.subtitle" class="relations-tab__subtitle">{{ entry.subtitle }}</span>
						<span v-if="entry.timestamp" class="relations-tab__separator">&middot;</span>
						<span v-if="entry.timestamp" class="relations-tab__date">{{ formatDate(entry.timestamp) }}</span>
					</div>
				</div>
			</li>
		</ol>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import NcCheckboxRadioSwitch from '@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch.js'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import LinkVariant from 'vue-material-design-icons/LinkVariant.vue'
import LinkVariantOff from 'vue-material-design-icons/LinkVariantOff.vue'
import EmailOutline from 'vue-material-design-icons/EmailOutline.vue'
import CalendarOutline from 'vue-material-design-icons/CalendarOutline.vue'
import AccountOutline from 'vue-material-design-icons/AccountOutline.vue'
import TableLargePlus from 'vue-material-design-icons/TableLargePlus.vue'

/**
 * RelationsTab — unified timeline of every entity linked to an object.
 *
 * Reads `GET /api/objects/{register}/{schema}/{id}/relations` (RelationsController),
 * which aggregates emails / calendar events / contacts / Deck cards into a single
 * payload. The query parameter `view=timeline` requests a flat, time-sorted shape;
 * `types=…` narrows the response to a subset.
 *
 * The component normalises whichever shape the backend returns into a single
 * `relations[]` array of `{ id, type, title, subtitle, timestamp }` records, then
 * filters client-side by type so the chip toggles do not require a refetch.
 *
 * Spec: openspec/changes/nextcloud-entity-relations/specs/unified-relations-api/spec.md
 */
export default {
	name: 'RelationsTab',

	components: {
		NcEmptyContent,
		NcLoadingIcon,
		NcCheckboxRadioSwitch,
		AlertCircleOutline,
		LinkVariant,
		LinkVariantOff,
		EmailOutline,
		CalendarOutline,
		AccountOutline,
		TableLargePlus,
	},

	props: {
		register: {
			type: [String, Number],
			required: true,
		},
		schema: {
			type: [String, Number],
			required: true,
		},
		objectId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			loading: false,
			error: false,
			errorMessage: '',
			relations: [],
			selectedTypes: ['email', 'event', 'contact', 'deck'],
			typeLabels: {
				email: t('openregister', 'Emails'),
				event: t('openregister', 'Events'),
				contact: t('openregister', 'Contacts'),
				deck: t('openregister', 'Deck'),
			},
		}
	},

	computed: {
		availableTypes() {
			const set = new Set()
			for (const r of this.relations) {
				if (r.type) {
					set.add(r.type)
				}
			}

			return Array.from(set)
		},

		visibleRelations() {
			if (this.selectedTypes.length === 0) {
				return this.relations
			}

			return this.relations.filter(r => this.selectedTypes.includes(r.type))
		},
	},

	watch: {
		objectId: {
			immediate: true,
			handler(newId) {
				if (newId) {
					this.fetchRelations()
				}
			},
		},
	},

	methods: {
		t,

		async fetchRelations() {
			this.loading = true
			this.error = false
			this.errorMessage = ''

			const url = generateUrl('/apps/openregister/api/objects/{register}/{schema}/{id}/relations', {
				register: this.register,
				schema: this.schema,
				id: this.objectId,
			})

			try {
				const response = await axios.get(url, { params: { view: 'timeline' } })
				this.relations = this.normaliseResponse(response.data)
			} catch (err) {
				this.error = true
				this.errorMessage = err.response?.data?.error || err.message || ''
			} finally {
				this.loading = false
			}
		},

		normaliseResponse(data) {
			// The unified endpoint may return either a flat timeline array
			// (`view=timeline`) or a typed envelope (`{ emails: [...], events: [...] }`).
			// Normalise both into a single `[ { id, type, title, subtitle, timestamp } ]`.
			if (Array.isArray(data)) {
				return data.map(this.normaliseEntry)
			}

			if (Array.isArray(data?.results)) {
				return data.results.map(this.normaliseEntry)
			}

			const out = []
			const buckets = {
				email: data?.emails || [],
				event: data?.events || [],
				contact: data?.contacts || [],
				deck: data?.deck || data?.deckCards || [],
			}

			for (const [type, items] of Object.entries(buckets)) {
				for (const item of items) {
					out.push(this.normaliseEntry({ ...item, type }))
				}
			}

			out.sort((a, b) => {
				const aT = a.timestamp ? new Date(a.timestamp).getTime() : 0
				const bT = b.timestamp ? new Date(b.timestamp).getTime() : 0
				return bT - aT
			})

			return out
		},

		normaliseEntry(raw) {
			const type = raw.type || raw.entityType || 'unknown'
			let title = raw.title || raw.subject || raw.summary || raw.displayName || raw.name || ''
			let subtitle = raw.subtitle || ''
			const timestamp = raw.timestamp
				|| raw.receivedAt
				|| raw.startsAt
				|| raw.createdAt
				|| raw.updatedAt
				|| null

			if (type === 'email' && !subtitle) {
				subtitle = raw.fromEmail || raw.from || ''
			}

			if (type === 'contact' && !title) {
				title = raw.fullName || raw.email || ''
			}

			return {
				id: raw.id || raw.uid || raw.uuid || `${type}-${Math.random()}`,
				type,
				title,
				subtitle,
				timestamp,
			}
		},

		toggleType(type) {
			if (this.selectedTypes.includes(type)) {
				this.selectedTypes = this.selectedTypes.filter(t => t !== type)
			} else {
				this.selectedTypes = [...this.selectedTypes, type]
			}
		},

		formatDate(value) {
			if (!value) {
				return ''
			}

			try {
				const d = new Date(value)
				if (Number.isNaN(d.getTime())) {
					return value
				}

				return d.toLocaleString()
			} catch (e) {
				return value
			}
		},
	},
}
</script>

<style scoped>
.relations-tab__loading {
	display: flex;
	justify-content: center;
	padding: 2em 0;
}

.relations-tab__filters {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
}

.relations-tab__timeline {
	list-style: none;
	margin: 0;
	padding: 0;
}

.relations-tab__entry {
	display: flex;
	align-items: flex-start;
	gap: 12px;
	padding: 10px 12px;
	border-bottom: 1px solid var(--color-border);
}

.relations-tab__entry:last-child {
	border-bottom: none;
}

.relations-tab__icon {
	flex-shrink: 0;
	margin-top: 2px;
	color: var(--color-text-maxcontrast);
}

.relations-tab__body {
	flex-grow: 1;
	min-width: 0;
}

.relations-tab__title {
	font-weight: 500;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.relations-tab__meta {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.relations-tab__type-badge {
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	padding: 0 6px;
	text-transform: capitalize;
}
</style>
