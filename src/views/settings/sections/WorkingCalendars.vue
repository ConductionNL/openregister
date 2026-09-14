<template>
	<SettingsSection
		id="working-calendars"
		:name="t('openregister', 'Working calendars')"
		:description="
			t(
				'openregister',
				'Every deadline in this instance is counted against one of these calendars: which weekdays are worked, how long a working day is, and which days are closed. Holidays are computed from rules rather than listed year by year, so a calendar does not quietly run out.',
			)
		">
		<div class="calendars">
			<div v-if="loading" class="calendars__hint">
				<NcLoadingIcon :size="20" />
				{{ t('openregister', 'Reading calendars …') }}
			</div>

			<div v-else-if="error" class="calendars__hint calendars__hint--bad">
				{{ error }}
			</div>

			<template v-else>
				<table class="calendars__table" data-testid="working-calendars-table">
					<thead>
						<tr>
							<th scope="col">{{ t('openregister', 'Calendar') }}</th>
							<th scope="col">{{ t('openregister', 'Working week') }}</th>
							<th scope="col">{{ t('openregister', 'Hours a day') }}</th>
							<th scope="col">{{ t('openregister', 'Rules') }}</th>
							<th scope="col">{{ t('openregister', 'Closure days') }}</th>
							<th scope="col">{{ t('openregister', 'Organisation') }}</th>
							<th scope="col">
								<span class="calendars__sr">{{
									t('openregister', 'Actions')
								}}</span>
							</th>
						</tr>
					</thead>
					<tbody>
						<tr
							v-for="calendar in calendars"
							:key="calendar.slug"
							:data-testid="'working-calendar-row-' + calendar.slug">
							<td>
								{{ calendar.title || calendar.slug }}
								<span class="calendars__slug">{{ calendar.slug }}</span>
							</td>
							<td>{{ weekLabel(calendar) }}</td>
							<td>{{ calendar.hoursPerWorkingDay }}</td>
							<td>{{ (calendar.rules || []).length }}</td>
							<td>{{ (calendar.exceptions || []).length }}</td>
							<td>{{ calendar.organisation || t('openregister', 'Shared') }}</td>
							<td class="calendars__actions">
								<NcButton
									variant="secondary"
									:data-testid="'working-calendar-edit-' + calendar.slug"
									@click="edit(calendar)">
									<template #icon>
										<Pencil :size="20" />
									</template>
									{{ t('openregister', 'Edit') }}
								</NcButton>
								<NcButton
									variant="tertiary"
									:disabled="busy === calendar.slug"
									:data-testid="'working-calendar-delete-' + calendar.slug"
									@click="remove(calendar)">
									<template #icon>
										<NcLoadingIcon v-if="busy === calendar.slug" :size="20" />
										<Delete v-else :size="20" />
									</template>
									{{ t('openregister', 'Delete') }}
								</NcButton>
							</td>
						</tr>
					</tbody>
				</table>

				<p
					v-if="outcome"
					class="calendars__hint"
					:class="
						outcome.ok ? 'calendars__hint--good' : 'calendars__hint--bad'
					"
					role="status"
					data-testid="working-calendars-outcome">
					{{ outcome.message }}
				</p>

				<NcButton
					variant="primary"
					data-testid="working-calendars-add"
					@click="edit(null)">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('openregister', 'Add a calendar') }}
				</NcButton>
			</template>
		</div>

		<EditWorkingCalendarModal
			:show="editing"
			:calendar="selected"
			@close="editing = false"
			@saved="onSaved" />
	</SettingsSection>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import SettingsSection from '../../../components/shared/SettingsSection.vue'
import EditWorkingCalendarModal from '../../../modals/settings/EditWorkingCalendarModal.vue'

/**
 * Working calendars section.
 *
 * The engine half of this has existed since flow-business-timers: a calendar
 * is an object in the `flow-timers` register and `WorkingCalendarService`
 * resolves it. What did not exist was anywhere to look at one. The register's
 * own note on the gap is blunt about the cost: five private implementations
 * across the fleet, four different definitions of a working day, and a grep
 * for a holiday settings screen that returned nothing.
 *
 * This panel reads and writes through the objects API, exactly as a script
 * would (design D-1). It keeps no store of its own, so there is no second
 * copy of a calendar to drift, and the validation it meets on save is the
 * validation the engine applies at arm time.
 */
export default {
	name: 'WorkingCalendars',

	components: {
		Delete,
		EditWorkingCalendarModal,
		NcButton,
		NcLoadingIcon,
		Pencil,
		Plus,
		SettingsSection,
	},

	data() {
		return {
			calendars: [],
			loading: true,
			error: '',
			busy: '',
			outcome: null,
			editing: false,
			selected: null,
		}
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read every working calendar through the objects API.
		 *
		 * @return {Promise<void>} Resolves when the list has been read.
		 *
		 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-the-objects-api-is-the-public-api-of-the-calendar
		 */
		async load() {
			this.loading = true
			this.error = ''

			try {
				const response = await axios.get(
					generateUrl(
						'/apps/openregister/api/objects/flow-timers/working-calendar',
					),
					{ params: { _limit: 100 } },
				)
				this.calendars = response.data?.results || []
			} catch (error) {
				this.error
					= error.response?.data?.error
					|| this.t('openregister', 'The calendars could not be read.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * A short reading of which weekdays are worked.
		 *
		 * @param {object} calendar One calendar.
		 * @return {string} The label.
		 *
		 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-working-calendars-are-administered-under-nextcloud-admin-settings
		 */
		weekLabel(calendar) {
			const names = [
				this.t('openregister', 'Mon'),
				this.t('openregister', 'Tue'),
				this.t('openregister', 'Wed'),
				this.t('openregister', 'Thu'),
				this.t('openregister', 'Fri'),
				this.t('openregister', 'Sat'),
				this.t('openregister', 'Sun'),
			]

			return (calendar.workingWeekdays || [])
				.map((iso) => names[iso - 1])
				.filter(Boolean)
				.join(', ')
		},

		/**
		 * Open the editor on a calendar, or on a new one.
		 *
		 * @param {object|null} calendar The calendar, or null to create.
		 * @return {void}
		 *
		 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-working-calendars-are-administered-under-nextcloud-admin-settings
		 */
		edit(calendar) {
			this.selected = calendar
			this.editing = true
		},

		/**
		 * Close the editor and re-read, so the table shows what was stored
		 * rather than what was typed.
		 *
		 * @return {Promise<void>} Resolves when the list has been re-read.
		 *
		 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-working-calendars-are-administered-under-nextcloud-admin-settings
		 */
		async onSaved() {
			this.editing = false
			this.outcome = {
				ok: true,
				message: this.t(
					'openregister',
					'Saved. Deadlines that are already running keep the dates they were given.',
				),
			}
			await this.load()
		},

		/**
		 * Delete a calendar, reporting the refusal when timers still use it.
		 *
		 * @param {object} calendar The calendar to delete.
		 * @return {Promise<void>} Resolves when the delete has been attempted.
		 *
		 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-only-an-administrator-writes-a-calendar-and-a-referenced-one-cannot-be-deleted
		 */
		async remove(calendar) {
			this.busy = calendar.slug
			this.outcome = null

			try {
				await axios.delete(
					generateUrl(
						'/apps/openregister/api/objects/flow-timers/working-calendar/'
							+ (calendar['@self']?.id || calendar['@self']?.uuid),
					),
				)
				this.outcome = {
					ok: true,
					message: this.t('openregister', 'The calendar was deleted.'),
				}
				await this.load()
			} catch (error) {
				// The refusal names the timers that keep this calendar alive.
				// Replacing it with "could not delete" would remove the only
				// information the reader needs to act.
				this.outcome = {
					ok: false,
					message:
						error.response?.data?.error
						|| this.t('openregister', 'The calendar could not be deleted.'),
				}
			} finally {
				this.busy = ''
			}
		},
	},
}
</script>

<style scoped>
.calendars {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
	align-items: flex-start;
}

.calendars__table {
	width: 100%;
	border-collapse: collapse;
}

.calendars__table th,
.calendars__table td {
	text-align: start;
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
	vertical-align: middle;
}

.calendars__slug {
	display: block;
	color: var(--color-text-maxcontrast);
	font-family: monospace;
	font-size: 0.85rem;
}

.calendars__actions {
	display: flex;
	gap: 0.5rem;
}

.calendars__hint {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.calendars__hint--bad {
	color: var(--color-error);
}

.calendars__hint--good {
	color: var(--color-success);
}

.calendars__sr {
	position: absolute;
	width: 1px;
	height: 1px;
	overflow: hidden;
	clip-path: inset(50%);
	white-space: nowrap;
}
</style>
