<!--
  The last run of the operator's seed directory, on the operations console.
  Reads GET /api/settings/seed (administrator only).

  @spec openspec/changes/seed-from-a-mounted-directory/specs/data-import-export/spec.md
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.
-->
<template>
	<section class="consoleSection seedReport">
		<h2>{{ t('openregister', 'Seed directory') }}</h2>

		<p v-if="error" class="seedReportError" role="alert">
			{{ t('openregister', 'Could not read the seed report') }}
		</p>
		<p v-else-if="loaded && report === null" class="seedReportEmpty">
			{{ t('openregister', 'No seed has run yet') }}
		</p>
		<template v-else-if="report !== null">
			<p class="seedReportStatus" :class="'seedReportStatus--' + report.status">
				{{ statusLabel }}
			</p>
			<p class="seedReportLine">
				{{ t('openregister', 'Last run: {moment}', { moment: momentLabel }) }}
			</p>
			<p v-if="report.directory" class="seedReportLine">
				{{ t('openregister', 'Read from {directory}', { directory: report.directory }) }}
			</p>
			<p class="seedReportLine">
				{{
					t('openregister', 'Imported {imported}, skipped {skipped}, failed {failed}', {
						imported: totals.imported,
						skipped: totals.skipped,
						failed: totals.failed,
					})
				}}
			</p>
			<ul v-if="lines.length > 0" class="seedReportFailures">
				<li v-for="(line, index) in lines" :key="index">
					{{ line }}
				</li>
			</ul>
		</template>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/**
 * The seed directory's last report: its outcome, totals, problems and failures.
 *
 * @spec openspec/changes/seed-from-a-mounted-directory/specs/data-import-export/spec.md
 */
export default {
	name: 'SeedReportSection',

	data() {
		return {
			report: null,
			loaded: false,
			error: false,
		}
	},

	computed: {
		/**
		 * The run's outcome in words.
		 *
		 * @return {string} The label
		 */
		statusLabel() {
			const labels = {
				success: t('openregister', 'Succeeded'),
				partial: t('openregister', 'Partly succeeded'),
				failed: t('openregister', 'Failed'),
			}

			return labels[this.report?.status] ?? String(this.report?.status ?? '')
		},

		/**
		 * The totals, zero where the report has none.
		 *
		 * @return {{imported: number, skipped: number, failed: number}} The totals
		 */
		totals() {
			const totals = this.report?.totals ?? {}
			return {
				imported: Number(totals.imported ?? 0),
				skipped: Number(totals.skipped ?? 0),
				failed: Number(totals.failed ?? 0),
			}
		},

		/**
		 * When the run happened, in the reader's locale when the moment parses.
		 *
		 * @return {string} The moment
		 */
		momentLabel() {
			const moment = String(this.report?.moment ?? '')
			const parsed = new Date(moment)
			if (moment === '' || Number.isNaN(parsed.getTime())) {
				return moment
			}

			return parsed.toLocaleString()
		},

		/**
		 * The run's problems and every named failure, one line each, as the server wrote them.
		 *
		 * @return {Array<string>} The lines
		 */
		lines() {
			const problems = Array.isArray(this.report?.problems) ? this.report.problems.map(String) : []
			const files = Array.isArray(this.report?.files) ? this.report.files : []
			const failures = files.flatMap((file) => (Array.isArray(file?.failures) ? file.failures : []))
				.map((failure) => [failure.file, [failure.entity, failure.slug].filter(Boolean).join(' '), failure.reason]
					.filter(Boolean)
					.join(': '))

			return [...problems, ...failures]
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * Read the last report.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.error = false
			try {
				const response = await axios.get(generateUrl('/apps/openregister/api/settings/seed'))
				const report = response.data?.report
				this.report = report !== null && typeof report === 'object' ? report : null
			} catch {
				this.report = null
				this.error = true
			} finally {
				this.loaded = true
			}
		},
	},
}
</script>

<style scoped>
.seedReportStatus--success {
	color: var(--color-success-text);
}

.seedReportStatus--partial {
	color: var(--color-warning-text);
}

.seedReportStatus--failed,
.seedReportError {
	color: var(--color-error-text);
}

.seedReportFailures {
	margin: 0;
	padding-inline-start: 20px;
	list-style: disc;
}
</style>
