<template>
	<NcDialog
		:name="t('openregister', 'Daily report by e-mail')"
		size="normal"
		:open="true"
		@update:open="onOpenChange">
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>
		<NcNoteCard v-if="notice" type="success">
			<p>{{ notice }}</p>
		</NcNoteCard>

		<NcLoadingIcon v-if="loading" :size="32" />
		<div v-else class="or-connection-report">
			<dl class="or-connection-report__state">
				<dt>{{ t('openregister', 'Status') }}</dt>
				<dd data-testid="connection-report-status">
					{{
						report
							? t('openregister', 'Enabled')
							: t('openregister', 'Disabled')
					}}
				</dd>
				<template v-if="report">
					<dt>{{ t('openregister', 'Last run') }}</dt>
					<dd data-testid="connection-report-last-run">
						{{ lastRunLabel }}
					</dd>
				</template>
			</dl>
			<p
				v-if="report && report.lastError"
				class="or-connection-report__last-message"
				data-testid="connection-report-last-message">
				{{ report.lastError }}
			</p>

			<NcCheckboxRadioSwitch
				v-model="allAdministrators"
				type="switch"
				data-testid="connection-report-all-admins">
				{{ t('openregister', 'All administrators') }}
			</NcCheckboxRadioSwitch>

			<NcTextField
				v-model="otherAddresses"
				:label="
					t('openregister', 'Other e-mail addresses, separated by commas')
				"
				data-testid="connection-report-addresses" />

			<NcSelect
				v-model="hour"
				:options="hourOptions"
				:inputLabel="t('openregister', 'Send at')"
				:clearable="false"
				label="label"
				data-testid="connection-report-hour" />

			<NcCheckboxRadioSwitch
				v-model="onlyWhenAttention"
				type="switch"
				data-testid="connection-report-quiet-days">
				{{ t('openregister', 'Only when something needs attention') }}
			</NcCheckboxRadioSwitch>
		</div>

		<template #actions>
			<NcButton
				v-if="report"
				:disabled="busy"
				data-testid="connection-report-switch-off"
				@click="switchOff">
				{{ t('openregister', 'Disable') }}
			</NcButton>
			<NcButton
				v-if="report"
				:disabled="busy"
				data-testid="connection-report-test"
				@click="sendTest">
				{{ t('openregister', 'Send a test now') }}
			</NcButton>
			<NcButton
				:disabled="busy"
				data-testid="connection-report-cancel"
				@click="close">
				{{ t('openregister', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="busy || loading"
				data-testid="connection-report-save"
				@click="save">
				{{ t('openregister', 'Save') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
/**
 * The Connections page's "Daily report by e-mail" dialog.
 *
 * Creates, changes or switches off the current administrator's scheduled
 * report of kind `connection-health`, and sends a test through the existing
 * run-now endpoint. The schedule is always daily; the administrator picks the
 * hour, the recipients (the `@admins` token and extra addresses) and whether
 * a quiet day sends nothing.
 *
 * @spec openspec/changes/connections-daily-report-mail/tasks.md#task-4.1
 */
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
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
import pinia from '../../pinia.js'
import { useNavigationStore } from '../../store/modules/navigation.js'

/** The scheduled report kind this dialog manages. */
export const CONNECTION_HEALTH_KIND = 'connection-health'

/** The recipient token that expands to every administrator at run time. */
export const ADMINS_TOKEN = '@admins'

/** The hour a new report is sent at. */
export const DEFAULT_HOUR = 7

/** Stored name of the report; data, like the English mail it sends. */
export const REPORT_NAME = 'Daily connection report'

const API = '/apps/openregister/api/scheduled-reports'

/**
 * The hour choices, 00:00 to 23:00.
 *
 * @return {Array<{id: number, label: string}>} One option per hour.
 * @spec openspec/changes/connections-daily-report-mail/tasks.md#task-4.1
 */
export function hourOptions() {
	return Array.from({ length: 24 }, (_, hour) => ({
		id: hour,
		label: String(hour).padStart(2, '0') + ':00',
	}))
}

/**
 * Split the free-text addresses into a de-duplicated list.
 *
 * @param {string} text Addresses separated by commas, semicolons or whitespace.
 * @return {string[]} The addresses, trimmed, without empties or repeats.
 * @spec openspec/changes/connections-daily-report-mail/tasks.md#task-4.1
 */
export function parseAddresses(text) {
	const seen = []
	for (const part of String(text ?? '').split(/[\s,;]+/)) {
		const address = part.trim()
		if (address !== '' && address !== ADMINS_TOKEN && !seen.includes(address)) {
			seen.push(address)
		}
	}
	return seen
}

/**
 * The recipients a report is saved with.
 *
 * @param {boolean} allAdministrators Whether every administrator receives it.
 * @param {string} otherAddresses The extra addresses as typed.
 * @return {string[]} The `@admins` token first when chosen, then the addresses.
 * @spec openspec/changes/connections-daily-report-mail/tasks.md#task-4.1
 */
export function buildRecipients(allAdministrators, otherAddresses) {
	const addresses = parseAddresses(otherAddresses)
	return allAdministrators ? [ADMINS_TOKEN, ...addresses] : addresses
}

export default {
	name: 'ConnectionReportDialog',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcTextField,
	},

	data() {
		const options = hourOptions()
		return {
			loading: true,
			busy: false,
			error: '',
			notice: '',
			report: null,
			allAdministrators: true,
			otherAddresses: '',
			hourOptions: options,
			hour: options[DEFAULT_HOUR],
			onlyWhenAttention: false,
		}
	},

	computed: {
		/**
		 * When the report last ran, or "Never".
		 *
		 * @return {string} The label.
		 */
		lastRunLabel() {
			if (!this.report?.lastRunAt) {
				return t('openregister', 'Never')
			}
			return new Date(this.report.lastRunAt).toLocaleString()
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * Read the current administrator's connection report, if any.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/connections-daily-report-mail/tasks.md#task-4.1
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const response = await axios.get(generateUrl(API))
				const results = response?.data?.results ?? []
				this.applyReport(
					results.find((r) => r.kind === CONNECTION_HEALTH_KIND) ?? null,
				)
			} catch (e) {
				this.error = this.messageOf(
					e,
					t('openregister', 'The daily report could not be loaded.'),
				)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fill the form from a report, or reset it when there is none.
		 *
		 * @param {object|null} report The stored report.
		 * @return {void}
		 * @spec openspec/changes/connections-daily-report-mail/tasks.md#task-4.1
		 */
		applyReport(report) {
			this.report = report
			if (report === null) {
				return
			}
			const recipients = Array.isArray(report.recipients)
				? report.recipients
				: []
			this.allAdministrators = recipients.includes(ADMINS_TOKEN)
			this.otherAddresses = recipients
				.filter((r) => r !== ADMINS_TOKEN)
				.join(', ')
			this.hour =
				this.hourOptions[report.scheduleHour]
				?? this.hourOptions[DEFAULT_HOUR]
			this.onlyWhenAttention = report.options?.onlyWhenAttention === true
		},

		/**
		 * The request body for create and change.
		 *
		 * @return {object} The scheduled report payload.
		 * @spec openspec/changes/connections-daily-report-mail/tasks.md#task-4.1
		 */
		payload() {
			return {
				name: this.report?.name ?? REPORT_NAME,
				kind: CONNECTION_HEALTH_KIND,
				scheduleType: 'daily',
				scheduleHour: this.hour?.id ?? DEFAULT_HOUR,
				deliveryMode: 'email',
				recipients: buildRecipients(
					this.allAdministrators,
					this.otherAddresses,
				),

				options: { onlyWhenAttention: this.onlyWhenAttention },
			}
		},

		/**
		 * Create the report, or change the existing one, then close.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/connections-daily-report-mail/tasks.md#task-4.1
		 */
		async save() {
			await this.run(
				async () => {
					if (this.report) {
						await axios.put(
							generateUrl(API + '/' + this.report.id),
							this.payload(),
						)
					} else {
						await axios.post(generateUrl(API), this.payload())
					}
					this.close()
				},
				t('openregister', 'The daily report could not be saved.'),
			)
		},

		/**
		 * Switch the report off by removing its schedule.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/connections-daily-report-mail/tasks.md#task-4.1
		 */
		async switchOff() {
			await this.run(
				async () => {
					await axios.delete(generateUrl(API + '/' + this.report.id))
					this.applyReport(null)
				},
				t('openregister', 'The daily report could not be saved.'),
			)
		},

		/**
		 * Queue a run of the saved report now.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/connections-daily-report-mail/tasks.md#task-4.1
		 */
		async sendTest() {
			await this.run(
				async () => {
					await axios.post(
						generateUrl(API + '/' + this.report.id + '/run-now'),
					)
					this.notice = t('openregister', 'A test report is on its way.')
				},
				t('openregister', 'The daily report could not be saved.'),
			)
		},

		/**
		 * Run one request with the buttons locked and its error shown.
		 *
		 * @param {() => Promise<void>} action The request.
		 * @param {string} fallback The message when the server gives none.
		 * @return {Promise<void>}
		 * @spec openspec/changes/connections-daily-report-mail/tasks.md#task-4.1
		 */
		async run(action, fallback) {
			this.busy = true
			this.error = ''
			this.notice = ''
			try {
				await action()
			} catch (e) {
				this.error = this.messageOf(e, fallback)
			} finally {
				this.busy = false
			}
		},

		/**
		 * The server's error text, or the fallback.
		 *
		 * @param {Error} e The failure.
		 * @param {string} fallback The message when the server gives none.
		 * @return {string} The message to show.
		 * @spec openspec/changes/connections-daily-report-mail/tasks.md#task-4.1
		 */
		messageOf(e, fallback) {
			const message = e?.response?.data?.error
			return typeof message === 'string' && message !== '' ? message : fallback
		},

		/**
		 * Close on the dialog's own close button.
		 *
		 * @param {boolean} open The new open state.
		 * @return {void}
		 */
		onOpenChange(open) {
			if (!open) {
				this.close()
			}
		},

		/**
		 * Close the dialog.
		 *
		 * @return {void}
		 */
		close() {
			useNavigationStore(pinia).setDialog(false)
		},
	},
}
</script>

<style scoped>
.or-connection-report {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.or-connection-report__state {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 4px 12px;
	margin: 0;
}

.or-connection-report__state dt {
	font-weight: bold;
}

.or-connection-report__state dd {
	margin: 0;
}

.or-connection-report__last-message {
	color: var(--color-text-maxcontrast);
}
</style>
