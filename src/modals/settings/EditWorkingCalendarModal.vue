<template>
	<NcDialog
		v-if="show"
		:name="dialogName"
		size="large"
		:noClose="saving"
		@closing="$emit('close')">
		<div class="calendar-form" data-testid="working-calendar-form">
			<NcNoteCard v-if="error" type="error" :text="error" />

			<!-- The notice is not decoration. Until
			     calendar-change-recomputes-timers lands, editing a rule moves
			     nothing that is already armed, and an administrator who
			     assumes otherwise has silently mis-set every running deadline. -->
			<NcNoteCard
				type="warning"
				:text="
					t(
						'openregister',
						'Deadlines that are already running keep the dates they were given. A change here applies to deadlines started after you save.',
					)
				" />

			<section class="calendar-form__block">
				<h4>{{ t('openregister', 'Identity') }}</h4>
				<NcTextField
					v-model="form.slug"
					:disabled="!isNew"
					:label="t('openregister', 'Slug')"
					:helperText="
						t(
							'openregister',
							'The stable name a timer refers to. It cannot change once deadlines point at it.',
						)
					"
					data-testid="working-calendar-slug" />
				<NcTextField
					v-model="form.title"
					:label="t('openregister', 'Title')"
					data-testid="working-calendar-title" />
				<NcTextField
					v-model="form.organisation"
					:label="t('openregister', 'Organisation')"
					:helperText="
						t(
							'openregister',
							'Leave empty for a calendar every organisation may use.',
						)
					"
					data-testid="working-calendar-organisation" />
			</section>

			<section class="calendar-form__block">
				<h4>{{ t('openregister', 'Working week') }}</h4>
				<div class="calendar-form__weekdays">
					<NcCheckboxRadioSwitch
						v-for="weekday in weekdayOptions"
						:key="weekday.iso"
						:modelValue="form.workingWeekdays.includes(weekday.iso)"
						:data-testid="'working-calendar-weekday-' + weekday.iso"
						@update:modelValue="toggleWeekday(weekday.iso)">
						{{ weekday.label }}
					</NcCheckboxRadioSwitch>
				</div>
				<NcTextField
					v-model="form.hoursPerWorkingDay"
					type="number"
					:label="t('openregister', 'Hours in a working day')"
					:helperText="
						t(
							'openregister',
							'Needed to convert a term in hours into a term in working days.',
						)
					"
					data-testid="working-calendar-hours" />
			</section>

			<section class="calendar-form__block">
				<h4>{{ t('openregister', 'Rules') }}</h4>
				<p class="calendar-form__hint">
					{{
						t(
							'openregister',
							'Rules are computed every year, so the calendar never runs out. A calendar made only of single dates is refused.',
						)
					}}
				</p>

				<div
					v-for="(rule, index) in form.rules"
					:key="'rule-' + index"
					class="calendar-form__row"
					data-testid="working-calendar-rule">
					<NcSelect
						:modelValue="kindOption(rule.kind)"
						:options="kindOptions"
						:inputLabel="t('openregister', 'Kind')"
						:clearable="false"
						label="label"
						@update:modelValue="setRuleKind(index, $event)" />
					<NcTextField
						v-model="rule.name"
						:label="t('openregister', 'Name')" />
					<NcTextField
						v-if="rule.kind === 'easter'"
						v-model="rule.offset"
						type="number"
						:label="t('openregister', 'Days from Easter Sunday')" />
					<template v-else>
						<NcTextField
							v-model="rule.month"
							type="number"
							:label="t('openregister', 'Month')" />
						<NcTextField
							v-model="rule.day"
							type="number"
							:label="t('openregister', 'Day')" />
						<NcSelect
							:modelValue="weekdayOption(rule.shiftWeekday)"
							:options="shiftWeekdayOptions"
							:inputLabel="t('openregister', 'Moves when it falls on')"
							label="label"
							@update:modelValue="setShiftWeekday(index, $event)" />
						<NcTextField
							v-if="rule.shiftWeekday"
							v-model="rule.shiftDays"
							type="number"
							:label="t('openregister', 'Moves by days')" />
					</template>
					<NcButton
						variant="tertiary"
						:ariaLabel="t('openregister', 'Remove this rule')"
						@click="removeRule(index)">
						<template #icon>
							<Delete :size="20" />
						</template>
					</NcButton>
				</div>

				<NcButton
					variant="secondary"
					data-testid="working-calendar-add-rule"
					@click="addRule">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('openregister', 'Add a rule') }}
				</NcButton>
			</section>

			<section class="calendar-form__block">
				<h4>{{ t('openregister', 'Single closure days') }}</h4>
				<p class="calendar-form__hint">
					{{
						t(
							'openregister',
							'One date that is closed this year only, such as a local anniversary.',
						)
					}}
				</p>

				<div
					v-for="(exception, index) in form.exceptions"
					:key="'exception-' + index"
					class="calendar-form__row"
					data-testid="working-calendar-exception">
					<NcTextField
						v-model="exception.date"
						type="date"
						:label="t('openregister', 'Date')" />
					<NcTextField
						v-model="exception.name"
						:label="t('openregister', 'Why it is closed')" />
					<NcButton
						variant="tertiary"
						:ariaLabel="t('openregister', 'Remove this closure day')"
						@click="removeException(index)">
						<template #icon>
							<Delete :size="20" />
						</template>
					</NcButton>
				</div>

				<NcButton
					variant="secondary"
					data-testid="working-calendar-add-exception"
					@click="addException">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('openregister', 'Add a closure day') }}
				</NcButton>
			</section>

			<section class="calendar-form__block">
				<h4>{{ t('openregister', 'Preview a year') }}</h4>
				<p class="calendar-form__hint">
					{{
						t(
							'openregister',
							'Runs the rules above without saving, so you can read the dates before you commit to them.',
						)
					}}
				</p>
				<div class="calendar-form__row">
					<NcTextField
						v-model="previewYear"
						type="number"
						:label="t('openregister', 'Year')"
						data-testid="working-calendar-preview-year" />
					<NcButton
						variant="secondary"
						:disabled="previewing"
						data-testid="working-calendar-preview"
						@click="preview">
						<template #icon>
							<NcLoadingIcon v-if="previewing" :size="20" />
							<Calendar v-else :size="20" />
						</template>
						{{ t('openregister', 'Preview') }}
					</NcButton>
				</div>

				<p
					v-if="previewError"
					class="calendar-form__hint calendar-form__hint--bad"
					role="status">
					{{ previewError }}
				</p>

				<ul
					v-else-if="previewDates.length"
					class="calendar-form__preview"
					data-testid="working-calendar-preview-dates">
					<li v-for="entry in previewDates" :key="entry.date">
						<span class="calendar-form__date">{{ entry.date }}</span>
						<span>{{ entry.name }}</span>
					</li>
				</ul>
			</section>
		</div>

		<template #actions>
			<NcButton :disabled="saving" @click="$emit('close')">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ t('openregister', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="saving"
				data-testid="working-calendar-save"
				@click="save">
				<template #icon>
					<NcLoadingIcon v-if="saving" :size="20" />
					<ContentSave v-else :size="20" />
				</template>
				{{ t('openregister', 'Save') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDialog,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import Calendar from 'vue-material-design-icons/Calendar.vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Plus from 'vue-material-design-icons/Plus.vue'

/**
 * Edits one working calendar and previews what its rules mean in a year.
 *
 * The form writes through the objects API like any other client (design D-1):
 * there is no calendar store and no calendar CRUD endpoint, so the validation
 * this form meets is the same validation a script meets. The only endpoint of
 * its own is the preview, which stores nothing.
 *
 * The `observedShift` sub-object is flattened into two fields on the row while
 * it is being edited, because a nested object inside a repeated row is the
 * shape that produced half-written rules in the form this replaces. It is
 * rebuilt on the way out.
 */
export default {
	name: 'EditWorkingCalendarModal',

	components: {
		Calendar,
		Cancel,
		ContentSave,
		Delete,
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcTextField,
		Plus,
	},

	props: {
		show: {
			type: Boolean,
			default: false,
		},

		calendar: {
			type: Object,
			default: null,
		},
	},

	emits: ['close', 'saved'],

	data() {
		return {
			form: this.blankForm(),
			saving: false,
			error: '',
			previewYear: String(new Date().getFullYear()),
			previewing: false,
			previewError: '',
			previewDates: [],
		}
	},

	computed: {
		/**
		 * Whether this is a calendar that does not exist yet.
		 *
		 * @return {boolean} True when the form creates rather than edits.
		 *
		 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-working-calendars-are-administered-under-nextcloud-admin-settings
		 */
		isNew() {
			return !this.calendar || !this.calendar['@self']
		},

		/**
		 * The dialog's name.
		 *
		 * @return {string} The translated name.
		 *
		 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-working-calendars-are-administered-under-nextcloud-admin-settings
		 */
		dialogName() {
			if (this.isNew) {
				return this.t('openregister', 'New working calendar')
			}
			return this.t('openregister', 'Working calendar {slug}', {
				slug: this.form.slug,
			})
		},

		/**
		 * The seven weekdays, ISO numbered.
		 *
		 * @return {Array<object>} Weekday options.
		 *
		 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-working-calendars-are-administered-under-nextcloud-admin-settings
		 */
		weekdayOptions() {
			return [
				{ iso: 1, label: this.t('openregister', 'Monday') },
				{ iso: 2, label: this.t('openregister', 'Tuesday') },
				{ iso: 3, label: this.t('openregister', 'Wednesday') },
				{ iso: 4, label: this.t('openregister', 'Thursday') },
				{ iso: 5, label: this.t('openregister', 'Friday') },
				{ iso: 6, label: this.t('openregister', 'Saturday') },
				{ iso: 7, label: this.t('openregister', 'Sunday') },
			]
		},

		/**
		 * The weekday options an observed shift may name, plus "never".
		 *
		 * @return {Array<object>} Options for the shift weekday.
		 *
		 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-working-calendars-are-administered-under-nextcloud-admin-settings
		 */
		shiftWeekdayOptions() {
			return [
				{ value: '', label: this.t('openregister', 'It never moves') },
				{ value: 'monday', label: this.t('openregister', 'Monday') },
				{ value: 'tuesday', label: this.t('openregister', 'Tuesday') },
				{ value: 'wednesday', label: this.t('openregister', 'Wednesday') },
				{ value: 'thursday', label: this.t('openregister', 'Thursday') },
				{ value: 'friday', label: this.t('openregister', 'Friday') },
				{ value: 'saturday', label: this.t('openregister', 'Saturday') },
				{ value: 'sunday', label: this.t('openregister', 'Sunday') },
			]
		},

		/**
		 * The rule kinds the engine understands.
		 *
		 * @return {Array<object>} Options for the rule kind.
		 *
		 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-working-calendars-are-administered-under-nextcloud-admin-settings
		 */
		kindOptions() {
			return [
				{
					value: 'fixed',
					label: this.t('openregister', 'A date every year'),
				},
				{
					value: 'easter',
					label: this.t('openregister', 'Counted from Easter'),
				},
			]
		},
	},

	watch: {
		/**
		 * @param {object} value The calendar being edited.
		 * @spec exclude watcher reloading the form when the parent hands it another calendar
		 */
		calendar: {
			immediate: true,
			/**
			 * @param {object} value The calendar being edited.
			 * @spec exclude watcher body reloading the form when the parent hands it another calendar
			 */
			handler(value) {
				this.form = this.toForm(value)
				this.error = ''
				this.previewError = ''
				this.previewDates = []
			},
		},
	},

	methods: {
		/**
		 * An empty form, for a calendar that does not exist yet.
		 *
		 * @return {object} The blank form state.
		 *
		 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-working-calendars-are-administered-under-nextcloud-admin-settings
		 */
		blankForm() {
			return {
				slug: '',
				title: '',
				organisation: '',
				workingWeekdays: [1, 2, 3, 4, 5],
				hoursPerWorkingDay: '8',
				rules: [],
				exceptions: [],
			}
		},

		/**
		 * Read a stored calendar into the form, flattening each rule's shift.
		 *
		 * @param {object|null} value The stored object, or null for a new one.
		 * @return {object} The form state.
		 *
		 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-working-calendars-are-administered-under-nextcloud-admin-settings
		 */
		toForm(value) {
			if (!value) {
				return this.blankForm()
			}

			return {
				slug: value.slug || '',
				title: value.title || '',
				organisation: value.organisation || '',
				workingWeekdays: Array.isArray(value.workingWeekdays)
					? [...value.workingWeekdays]
					: [1, 2, 3, 4, 5],

				hoursPerWorkingDay: String(value.hoursPerWorkingDay ?? '8'),
				rules: (value.rules || []).map((rule) => ({
					kind: rule.kind === 'easter' ? 'easter' : 'fixed',
					name: rule.name || '',
					month: String(rule.month ?? ''),
					day: String(rule.day ?? ''),
					offset: String(rule.offset ?? ''),
					shiftWeekday: rule.observedShift?.whenWeekday || '',
					shiftDays: String(rule.observedShift?.days ?? ''),
				})),

				exceptions: (value.exceptions || []).map((entry) => ({
					date: entry.date || '',
					name: entry.name || '',
				})),
			}
		},

		/**
		 * Build the object the objects API and the preview both take.
		 *
		 * @return {object} The `working-calendar` definition.
		 *
		 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-every-write-of-a-working-calendar-is-validated-the-same-way
		 */
		toDefinition() {
			const definition = {
				slug: this.form.slug.trim(),
				title: this.form.title.trim(),
				workingWeekdays: [...this.form.workingWeekdays].sort(
					(a, b) => a - b,
				),

				hoursPerWorkingDay: Number(this.form.hoursPerWorkingDay),
				rules: this.form.rules.map((rule) => this.toRule(rule)),
				exceptions: this.form.exceptions
					.filter((entry) => entry.date)
					.map((entry) => ({
						date: entry.date,
						name: entry.name || 'exception',
					})),
			}

			if (this.form.organisation.trim()) {
				definition.organisation = this.form.organisation.trim()
			}

			return definition
		},

		/**
		 * Rebuild one rule, restoring the nested shift the row flattened.
		 *
		 * @param {object} rule One form row.
		 * @return {object} The rule as the engine stores it.
		 *
		 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-every-write-of-a-working-calendar-is-validated-the-same-way
		 */
		toRule(rule) {
			if (rule.kind === 'easter') {
				return {
					kind: 'easter',
					name: rule.name,
					offset: Number(rule.offset),
				}
			}

			const fixed = {
				kind: 'fixed',
				name: rule.name,
				month: Number(rule.month),
				day: Number(rule.day),
			}

			if (rule.shiftWeekday) {
				fixed.observedShift = {
					whenWeekday: rule.shiftWeekday,
					days: Number(rule.shiftDays),
				}
			}

			return fixed
		},

		/**
		 * Turn a weekday on or off.
		 *
		 * @param {number} iso The ISO weekday.
		 * @return {void}
		 *
		 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-working-calendars-are-administered-under-nextcloud-admin-settings
		 */
		toggleWeekday(iso) {
			if (this.form.workingWeekdays.includes(iso)) {
				this.form.workingWeekdays = this.form.workingWeekdays.filter(
					(day) => day !== iso,
				)
				return
			}

			this.form.workingWeekdays = [...this.form.workingWeekdays, iso]
		},

		/**
		 * The option object a rule kind corresponds to.
		 *
		 * @param {string} kind The stored kind.
		 * @return {object} The matching option.
		 *
		 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-working-calendars-are-administered-under-nextcloud-admin-settings
		 */
		kindOption(kind) {
			return (
				this.kindOptions.find((option) => option.value === kind)
				|| this.kindOptions[0]
			)
		},

		/**
		 * The option object a shift weekday corresponds to.
		 *
		 * @param {string} weekday The stored weekday name, or empty.
		 * @return {object} The matching option.
		 *
		 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-working-calendars-are-administered-under-nextcloud-admin-settings
		 */
		weekdayOption(weekday) {
			return (
				this.shiftWeekdayOptions.find((option) => option.value === weekday)
				|| this.shiftWeekdayOptions[0]
			)
		},

		/**
		 * Change a rule's kind.
		 *
		 * @param {number} index The row.
		 * @param {object} option The chosen option.
		 * @return {void}
		 *
		 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-working-calendars-are-administered-under-nextcloud-admin-settings
		 */
		setRuleKind(index, option) {
			this.form.rules[index].kind = option?.value || 'fixed'
		},

		/**
		 * Change the weekday on which a rule's date moves.
		 *
		 * @param {number} index The row.
		 * @param {object} option The chosen option.
		 * @return {void}
		 *
		 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-working-calendars-are-administered-under-nextcloud-admin-settings
		 */
		setShiftWeekday(index, option) {
			this.form.rules[index].shiftWeekday = option?.value || ''
		},

		/**
		 * Append an empty rule row.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-working-calendars-are-administered-under-nextcloud-admin-settings
		 */
		addRule() {
			this.form.rules.push({
				kind: 'fixed',
				name: '',
				month: '1',
				day: '1',
				offset: '0',
				shiftWeekday: '',
				shiftDays: '-1',
			})
		},

		/**
		 * Drop a rule row.
		 *
		 * @param {number} index The row.
		 * @return {void}
		 *
		 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-working-calendars-are-administered-under-nextcloud-admin-settings
		 */
		removeRule(index) {
			this.form.rules.splice(index, 1)
		},

		/**
		 * Append an empty closure day.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-working-calendars-are-administered-under-nextcloud-admin-settings
		 */
		addException() {
			this.form.exceptions.push({ date: '', name: '' })
		},

		/**
		 * Drop a closure day.
		 *
		 * @param {number} index The row.
		 * @return {void}
		 *
		 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-working-calendars-are-administered-under-nextcloud-admin-settings
		 */
		removeException(index) {
			this.form.exceptions.splice(index, 1)
		},

		/**
		 * Ask the server what the unsaved rules mean in a year.
		 *
		 * @return {Promise<void>} Resolves when the preview has been read.
		 *
		 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-working-calendars-are-administered-under-nextcloud-admin-settings
		 */
		async preview() {
			this.previewing = true
			this.previewError = ''
			this.previewDates = []

			try {
				const response = await axios.post(
					generateUrl(
						'/apps/openregister/api/flow-timers/calendars/preview',
					),
					{
						calendar: this.toDefinition(),
						year: Number(this.previewYear),
					},
				)
				this.previewDates = response.data?.dates || []
			} catch (error) {
				this.previewError =
					error.response?.data?.error
					|| this.t('openregister', 'The preview could not be computed.')
			} finally {
				this.previewing = false
			}
		},

		/**
		 * Write the calendar through the objects API.
		 *
		 * @return {Promise<void>} Resolves when the save has been attempted.
		 *
		 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-every-write-of-a-working-calendar-is-validated-the-same-way
		 */
		async save() {
			this.saving = true
			this.error = ''

			const base = generateUrl(
				'/apps/openregister/api/objects/flow-timers/working-calendar',
			)

			try {
				if (this.isNew) {
					await axios.post(base, this.toDefinition())
				} else {
					await axios.put(
						`${base}/${this.calendar['@self'].id || this.calendar['@self'].uuid}`,
						this.toDefinition(),
					)
				}
				this.$emit('saved')
			} catch (error) {
				// The server's message is the message. A generic "save failed"
				// here would hide the validator's sentence, which names the
				// rule that is wrong.
				this.error =
					error.response?.data?.error
					|| this.t('openregister', 'The calendar could not be saved.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.calendar-form {
	display: flex;
	flex-direction: column;
	gap: 1rem;
	padding: 0 20px 12px 20px;
}

.calendar-form__block {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.calendar-form__block h4 {
	margin: 0;
	font-size: 1rem;
	color: var(--color-text);
}

.calendar-form__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.9rem;
}

.calendar-form__hint--bad {
	color: var(--color-error);
}

.calendar-form__weekdays {
	display: flex;
	flex-wrap: wrap;
	gap: 0.75rem;
}

.calendar-form__row {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: 0.5rem;
}

.calendar-form__row > * {
	flex: 1 1 8rem;
}

.calendar-form__preview {
	list-style: none;
	margin: 0;
	padding: 0;
	max-height: 16rem;
	overflow-y: auto;
}

.calendar-form__preview li {
	display: flex;
	gap: 1rem;
	padding: 2px 0;
	border-bottom: 1px solid var(--color-border);
}

.calendar-form__date {
	font-family: monospace;
	min-width: 7rem;
}
</style>
