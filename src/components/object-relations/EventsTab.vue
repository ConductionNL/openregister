<!--
SPDX-FileCopyrightText: 2026 Conduction <info@conduction.nl>
SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div class="events-tab">
		<!-- Toolbar -->
		<div v-if="!loading && !calendarUnavailable" class="events-tab__toolbar">
			<NcButton variant="primary" @click="openCreateDialog">
				<template #icon>
					<CalendarPlus :size="20" />
				</template>
				{{ t('openregister', 'Create event') }}
			</NcButton>
			<NcButton variant="secondary" @click="openLinkDialog">
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
		<TabErrorState
			v-else-if="error"
			:name="t('openregister', 'Failed to load linked events')"
			:description="errorMessage" />

		<!-- Calendar app missing (HTTP 501 graceful degradation) -->
		<NcEmptyContent
			v-else-if="calendarUnavailable"
			:name="t('openregister', 'Calendar integration is not available')"
			:description="
				t(
					'openregister',
					'The Nextcloud Calendar app is not installed or enabled on this server.',
				)
			">
			<template #icon>
				<CalendarRemove :size="44" />
			</template>
		</NcEmptyContent>

		<!-- Empty state -->
		<NcEmptyContent
			v-else-if="items.length === 0"
			:name="t('openregister', 'No events linked to this object')"
			:description="
				t(
					'openregister',
					'Create a new event or link an existing one from any of your calendars.',
				)
			">
			<template #icon>
				<CalendarOutline :size="44" />
			</template>
		</NcEmptyContent>

		<!-- Linked events list -->
		<ul v-else class="events-tab__list">
			<li v-for="event in items" :key="event.id" class="events-tab__item">
				<div class="events-tab__icon">
					<CalendarOutline :size="20" />
				</div>
				<div class="events-tab__content">
					<div class="events-tab__summary">
						{{
							event.summary
							|| event.title
							|| t('openregister', '(no title)')
						}}
					</div>
					<div class="events-tab__meta">
						<span
							v-if="event.calendarDisplayName || event.calendarName"
							class="events-tab__calendar">
							{{ event.calendarDisplayName || event.calendarName }}
						</span>
						<span v-if="event.startsAt" class="events-tab__separator"
							>&middot;</span
						>
						<span v-if="event.startsAt" class="events-tab__date">
							{{ formatDate(event.startsAt) }}
						</span>
					</div>
				</div>
				<UnlinkButton
					:label="t('openregister', 'Unlink event')"
					@unlink="unlinkEvent(event)" />
			</li>
		</ul>
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import CalendarOutline from 'vue-material-design-icons/CalendarOutline.vue'
import CalendarPlus from 'vue-material-design-icons/CalendarPlus.vue'
import CalendarRemove from 'vue-material-design-icons/CalendarRemove.vue'
import LinkVariant from 'vue-material-design-icons/LinkVariant.vue'
import TabErrorState from '../shared/TabErrorState.vue'
import UnlinkButton from './UnlinkButton.vue'
import { useEventRelationsStore } from '../../store/modules/object-relations/events.js'
import { storeRelationTab } from './relationTab.js'

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
		TabErrorState,
		NcEmptyContent,
		NcLoadingIcon,
		NcButton,
		CalendarOutline,
		CalendarPlus,
		CalendarRemove,
		UnlinkButton,
		LinkVariant,
	},

	mixins: [storeRelationTab(useEventRelationsStore)],

	computed: {
		/**
		 * @spec exclude computed read of integration-availability flag from store, UI plumbing
		 */
		calendarUnavailable() {
			return this.store.calendarUnavailable
		},
	},

	methods: {
		/**
		 * @param {object} event
		 * @spec exclude store passthrough unlinking event + change emit; event-relations contract owned by integration-calendar capability
		 */
		unlinkEvent(event) {
			return this.unlink(event.id, 'events-changed')
		},

		/**
		 * @spec exclude emit UI handler surfacing create-event intent, UI plumbing
		 */
		openCreateDialog() {
			// Surface intent to the parent so it can mount a calendar-event modal
			// (the modal lives outside the tab to avoid pulling it into every detail view).
			this.$emit('create-event')
		},

		/**
		 * @spec exclude emit UI handler surfacing link-event intent, UI plumbing
		 */
		openLinkDialog() {
			this.$emit('link-event')
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../styles/components.scss' as *;

@include relation-tab-list('events-tab', 'summary', 'calendar');
</style>
