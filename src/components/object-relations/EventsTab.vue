<!--
SPDX-FileCopyrightText: 2026 Conduction <info@conduction.nl>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="events-tab">
		<!-- Toolbar -->
		<div v-if="!loading && !calendarUnavailable" class="events-tab__toolbar">
			<NcButton type="primary" @click="openCreateDialog">
				<template #icon>
					<CalendarPlus :size="20" />
				</template>
				{{ t('openregister', 'Create event') }}
			</NcButton>
			<NcButton type="secondary" @click="openLinkDialog">
				<template #icon>
					<LinkVariant :size="20" />
				</template>
				{{ t('openregister', 'Link existing event') }}
			</NcButton>
		</div>

		<!-- Loading state -->
		<div v-if="loading" class="events-tab__loading">
			<NcLoadingIcon :size="44" />
		</div>

		<!-- Error state -->
		<NcEmptyContent v-else-if="error"
			:name="t('openregister', 'Failed to load linked events')"
			:description="errorMessage">
			<template #icon>
				<AlertCircleOutline :size="44" />
			</template>
		</NcEmptyContent>

		<!-- Calendar app missing (HTTP 501 graceful degradation) -->
		<NcEmptyContent v-else-if="calendarUnavailable"
			:name="t('openregister', 'Calendar integration is not available')"
			:description="t('openregister', 'The Nextcloud Calendar app is not installed or enabled on this server.')">
			<template #icon>
				<CalendarRemove :size="44" />
			</template>
		</NcEmptyContent>

		<!-- Empty state -->
		<NcEmptyContent v-else-if="events.length === 0"
			:name="t('openregister', 'No events linked to this object')"
			:description="t('openregister', 'Create a new event or link an existing one from any of your calendars.')">
			<template #icon>
				<CalendarOutline :size="44" />
			</template>
		</NcEmptyContent>

		<!-- Linked events list -->
		<ul v-else class="events-tab__list">
			<li v-for="event in events"
				:key="event.id"
				class="events-tab__item">
				<div class="events-tab__icon">
					<CalendarOutline :size="20" />
				</div>
				<div class="events-tab__content">
					<div class="events-tab__summary">
						{{ event.summary || event.title || t('openregister', '(no title)') }}
					</div>
					<div class="events-tab__meta">
						<span v-if="event.calendarDisplayName || event.calendarName" class="events-tab__calendar">
							{{ event.calendarDisplayName || event.calendarName }}
						</span>
						<span v-if="event.startsAt" class="events-tab__separator">&middot;</span>
						<span v-if="event.startsAt" class="events-tab__date">
							{{ formatDate(event.startsAt) }}
						</span>
					</div>
				</div>
				<NcButton type="tertiary"
					:aria-label="t('openregister', 'Unlink event')"
					@click="unlinkEvent(event)">
					<template #icon>
						<CloseCircleOutline :size="20" />
					</template>
				</NcButton>
			</li>
		</ul>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import CalendarOutline from 'vue-material-design-icons/CalendarOutline.vue'
import CalendarPlus from 'vue-material-design-icons/CalendarPlus.vue'
import CalendarRemove from 'vue-material-design-icons/CalendarRemove.vue'
import CloseCircleOutline from 'vue-material-design-icons/CloseCircleOutline.vue'
import LinkVariant from 'vue-material-design-icons/LinkVariant.vue'
import { useEventRelationsStore } from '../../store/modules/object-relations/events.js'

/**
 * EventsTab — display + manage calendar events linked to an OpenRegister object.
 *
 * Backed by the per-object endpoint `GET /api/objects/{register}/{schema}/{id}/events`
 * (CalendarEventsController). Falls back to a "Calendar not available" empty state
 * when the backend returns HTTP 501 (Calendar app not installed). Supports
 * "Create event" (POST …/events) and "Link existing event" (POST …/events/link).
 * Unlink is wired through `DELETE …/events/{eventId}` and emits
 * `events-changed` so the parent can refresh its counters.
 *
 * Spec: openspec/changes/nextcloud-entity-relations/specs/event-relations/spec.md
 */
export default {
	name: 'EventsTab',

	components: {
		NcEmptyContent,
		NcLoadingIcon,
		NcButton,
		AlertCircleOutline,
		CalendarOutline,
		CalendarPlus,
		CalendarRemove,
		CloseCircleOutline,
		LinkVariant,
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
			error: false,
			errorMessage: '',
			store: useEventRelationsStore(),
		}
	},

	computed: {
		key() {
			return `${this.register}:${this.schema}:${this.objectId}`
		},
		events() {
			return this.store.byObject[this.key] || []
		},
		loading() {
			return !!this.store.loading[this.key]
		},
		calendarUnavailable() {
			return this.store.calendarUnavailable
		},
	},

	watch: {
		objectId: {
			immediate: true,
			handler(newId) {
				if (newId) {
					this.fetchEvents()
				}
			},
		},
	},

	methods: {
		t,

		async fetchEvents() {
			this.error = false
			this.errorMessage = ''
			try {
				await this.store.fetch(this.register, this.schema, this.objectId)
			} catch (err) {
				this.error = true
				this.errorMessage = err.response?.data?.error || err.message || ''
			}
		},

		async unlinkEvent(event) {
			try {
				await this.store.unlink(this.register, this.schema, this.objectId, event.id)
				this.$emit('events-changed', this.events.length)
			} catch (err) {
				this.error = true
				this.errorMessage = err.response?.data?.error || err.message || ''
			}
		},

		openCreateDialog() {
			// Surface intent to the parent so it can mount a calendar-event modal
			// (the modal lives outside the tab to avoid pulling it into every detail view).
			this.$emit('create-event')
		},

		openLinkDialog() {
			this.$emit('link-event')
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
.events-tab__toolbar {
	display: flex;
	gap: 8px;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
}

.events-tab__loading {
	display: flex;
	justify-content: center;
	padding: 2em 0;
}

.events-tab__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.events-tab__item {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
}

.events-tab__item:last-child {
	border-bottom: none;
}

.events-tab__icon {
	flex-shrink: 0;
	color: var(--color-text-maxcontrast);
}

.events-tab__content {
	flex-grow: 1;
	min-width: 0;
}

.events-tab__summary {
	font-weight: 500;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.events-tab__meta {
	display: flex;
	gap: 6px;
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.events-tab__calendar {
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}
</style>
