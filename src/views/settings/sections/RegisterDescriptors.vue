<template>
	<SettingsSection
		id="register-descriptors"
		:name="t('openregister', 'Register descriptors')"
		:description="
			t(
				'openregister',
				'Apps ship their registers as descriptors that are imported when the app is installed or upgraded. Once an app\'s version stops changing that import never runs again, and a failed one is only written to the log — so a register can be missing on a instance that otherwise looks healthy. This is where you can see which ones landed.',
			)
		">
		<div class="descriptors">
			<div v-if="loading" class="descriptors__hint">
				<NcLoadingIcon :size="20" />
				{{ t('openregister', 'Reading descriptors\u00a0…') }}
			</div>

			<div v-else-if="error" class="descriptors__hint descriptors__hint--bad">
				{{ error }}
			</div>

			<template v-else>
				<!-- The summary leads with what is wrong. A panel that opens on
				     "15 declared" and buries the absent ones reproduces the
				     silence it exists to break. -->
				<p class="descriptors__summary">
					<span
						v-if="absent > 0"
						class="descriptors__count descriptors__count--absent">
						{{
							n(
								'openregister',
								'%n register is missing',
								'%n registers are missing',
								absent,
							)
						}}
					</span>
					<span
						v-if="behind > 0"
						class="descriptors__count descriptors__count--behind">
						{{
							n(
								'openregister',
								'%n is out of date',
								'%n are out of date',
								behind,
							)
						}}
					</span>
					<span
						v-if="absent === 0 && behind === 0"
						class="descriptors__count">
						{{
							t(
								'openregister',
								'Every declared register is present and current.',
							)
						}}
					</span>
					<span class="descriptors__count descriptors__count--muted">
						{{
							n(
								'openregister',
								'%n declared in total',
								'%n declared in total',
								total,
							)
						}}
					</span>
				</p>

				<table class="descriptors__table">
					<thead>
						<tr>
							<th scope="col">{{ t('openregister', 'App') }}</th>
							<th scope="col">{{ t('openregister', 'Register') }}</th>
							<th scope="col">{{ t('openregister', 'State') }}</th>
							<th scope="col">{{ t('openregister', 'Version') }}</th>
							<th scope="col">
								<span class="descriptors__sr">{{
									t('openregister', 'Actions')
								}}</span>
							</th>
						</tr>
					</thead>
					<tbody>
						<tr
							v-for="row in rows"
							:key="row.appId + '/' + row.slug"
							:class="rowClass(row)">
							<td>{{ row.appId }}</td>
							<td>
								{{ row.title }}
								<span class="descriptors__slug">{{ row.slug }}</span>
							</td>
							<td>
								<span
									class="descriptors__state"
									:class="'descriptors__state--' + row.state">
									{{ stateLabel(row.state) }}
								</span>
							</td>
							<td class="descriptors__versions">
								{{ versionLabel(row) }}
							</td>
							<td>
								<NcButton
									:disabled="busy === rowKey(row)"
									variant="secondary"
									@click="reimport(row)">
									<template #icon>
										<NcLoadingIcon
											v-if="busy === rowKey(row)"
											:size="20" />
										<Download v-else :size="20" />
									</template>
									{{
										row.state === 'absent'
											? t('openregister', 'Import')
											: t('openregister', 'Re-import')
									}}
								</NcButton>
							</td>
						</tr>
					</tbody>
				</table>

				<!-- The outcome is shown, never only logged. A button that
				     reports nothing is indistinguishable from one that did
				     nothing, which is the failure being repaired here. -->
				<p
					v-if="outcome"
					class="descriptors__hint"
					:class="
						outcome.ok
							? 'descriptors__hint--good'
							: 'descriptors__hint--bad'
					"
					role="status">
					{{ outcome.message }}
				</p>
			</template>
		</div>
	</SettingsSection>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import Download from 'vue-material-design-icons/Download.vue'
import SettingsSection from '../../../components/shared/SettingsSection.vue'

/**
 * Register descriptors section.
 *
 * Eighteen of the fleet's apps ship a register descriptor and import it from a
 * Repair step. Repair steps run on install and `occ upgrade`, and `occ upgrade`
 * reports "No upgrade required" as soon as the app's version settles — so the
 * import can never run again. The steps are also documented never to throw: a
 * failure logs a warning and leaves the instance looking healthy.
 *
 * The consequence was found the expensive way. A dev instance was missing the
 * `flows` register entirely; two e2e suites died on the register lookup, and
 * establishing why took an account listing, a register dump and a read of the
 * Repair step's source. On the same instance, an `occ upgrade` that reported
 * complete success left 8 of 15 declared registers absent.
 *
 * So this panel leads with what is WRONG. The absent rows are the finding; a
 * panel that opened on a healthy total and made the reader hunt would be the
 * same silence in a nicer font.
 *
 * The re-import is always forced, because the import short-circuits on a
 * version comparison and the versions match in exactly the case somebody
 * presses this.
 */
export default {
	name: 'RegisterDescriptors',
	components: {
		Download,
		NcButton,
		NcLoadingIcon,
		SettingsSection,
	},

	data() {
		return {
			rows: [],
			total: 0,
			absent: 0,
			behind: 0,
			loading: true,
			error: '',
			busy: '',
			outcome: null,
		}
	},

	mounted() {
		this.load()
	},

	methods: {
		rowKey(row) {
			return `${row.appId}/${row.slug}`
		},

		rowClass(row) {
			return row.state === 'current' ? '' : 'descriptors__row--attention'
		},

		stateLabel(state) {
			if (state === 'absent') {
				return this.t('openregister', 'Missing')
			}
			if (state === 'behind') {
				return this.t('openregister', 'Out of date')
			}
			return this.t('openregister', 'Present')
		},

		versionLabel(row) {
			if (row.installedVersion === null) {
				return this.t('openregister', 'ships v{shipped}', {
					shipped: row.shippedVersion,
				})
			}
			if (row.state === 'behind') {
				return this.t('openregister', 'v{installed} → ships v{shipped}', {
					installed: row.installedVersion,
					shipped: row.shippedVersion,
				})
			}
			return `v${row.installedVersion}`
		},

		async load() {
			this.loading = true
			this.error = ''
			try {
				const response = await axios.get(
					generateUrl('/apps/openregister/api/register-descriptors'),
				)
				this.rows = response.data.results ?? []
				this.total = response.data.total ?? 0
				this.absent = response.data.absent ?? 0
				this.behind = response.data.behind ?? 0
			} catch (e) {
				this.error = this.t(
					'openregister',
					'Could not read the descriptor inventory: {reason}',
					{ reason: e?.response?.data?.error ?? e.message },
				)
			} finally {
				this.loading = false
			}
		},

		async reimport(row) {
			this.busy = this.rowKey(row)
			this.outcome = null
			try {
				await axios.post(
					generateUrl(
						`/apps/openregister/api/register-descriptors/${row.appId}/${row.slug}/import`,
					),
				)
				this.outcome = {
					ok: true,
					message: this.t(
						'openregister',
						'{register} was imported from {app}.',
						{
							register: row.title,
							app: row.appId,
						},
					),
				}
				// Re-read rather than patch the row locally: the inventory is
				// the authority on what is now present, and a locally-optimistic
				// row would report success the import may not have achieved.
				await this.load()
			} catch (e) {
				this.outcome = {
					ok: false,
					message: this.t('openregister', 'Import failed: {reason}', {
						reason:
							e?.response?.data?.reason
							?? e?.response?.data?.error
							?? e.message,
					}),
				}
			} finally {
				this.busy = ''
			}
		},
	},
}
</script>

<style scoped>
.descriptors__summary {
	display: flex;
	flex-wrap: wrap;
	gap: 0.75rem;
	margin-bottom: 0.75rem;
}

.descriptors__count {
	font-weight: 600;
}

.descriptors__count--absent {
	color: var(--color-error);
}

.descriptors__count--behind {
	color: var(--color-warning);
}

.descriptors__count--muted {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}

.descriptors__table {
	width: 100%;
	border-collapse: collapse;
}

.descriptors__table th,
.descriptors__table td {
	text-align: left;
	padding: 0.4rem 0.5rem;
	border-bottom: 1px solid var(--color-border);
}

.descriptors__row--attention {
	background-color: var(--color-background-hover);
}

.descriptors__slug {
	display: block;
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.descriptors__state--absent {
	color: var(--color-error);
	font-weight: 600;
}

.descriptors__state--behind {
	color: var(--color-warning);
	font-weight: 600;
}

.descriptors__versions {
	white-space: nowrap;
	color: var(--color-text-maxcontrast);
}

.descriptors__hint {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	margin-top: 0.75rem;
}

.descriptors__hint--bad {
	color: var(--color-error);
}

.descriptors__hint--good {
	color: var(--color-success);
}

.descriptors__sr {
	position: absolute;
	width: 1px;
	height: 1px;
	overflow: hidden;
	clip: rect(0 0 0 0);
	white-space: nowrap;
}
</style>
