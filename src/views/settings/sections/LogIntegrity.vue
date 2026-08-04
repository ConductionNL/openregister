<template>
	<SettingsSection
		id="log-integrity"
		:name="t('openregister', 'Log integrity')"
		:description="t('openregister', 'Every audit entry is chained to the one before it with a SHA-256 hash, so altering or deleting history breaks the links either side of it. This is where you can see whether that chain is whole.')">
		<div class="integrity">
			<!-- Seal coverage: cheap, loaded on open -->
			<div v-if="status === null" class="integrity__hint">
				{{ t('openregister', 'Reading seal coverage …') }}
			</div>

			<div v-else class="integrity__coverage">
				<div class="integrity__bar" :title="coverageTitle">
					<div class="integrity__bar-fill" :class="barClass" :style="{ width: status.coverage + '%' }" />
				</div>

				<div class="integrity__figures">
					<div class="integrity__figure">
						<span class="integrity__figure-value">{{ formatNumber(status.total) }}</span>
						<span class="integrity__figure-label">{{ t('openregister', 'Audit entries') }}</span>
					</div>
					<div class="integrity__figure">
						<span class="integrity__figure-value">{{ formatNumber(status.sealed) }}</span>
						<span class="integrity__figure-label">{{ t('openregister', 'Sealed') }}</span>
					</div>
					<div class="integrity__figure" :class="{ 'integrity__figure--attention': status.unsealed > 0 }">
						<span class="integrity__figure-value">{{ formatNumber(status.unsealed) }}</span>
						<span class="integrity__figure-label">{{ t('openregister', 'Awaiting a seal') }}</span>
					</div>
					<div class="integrity__figure">
						<span class="integrity__figure-value">{{ status.coverage }}%</span>
						<span class="integrity__figure-label">{{ t('openregister', 'Coverage') }}</span>
					</div>
				</div>

				<div v-if="status.unsealed > 0" class="integrity__state integrity__state--warning">
					<div class="integrity__state-badge">
						<AlertOutline :size="20" />
						<span>{{ n('openregister', '%n entry has no hash yet', '%n entries have no hash yet', status.unsealed) }}</span>
					</div>
					<p class="integrity__hint">
						{{ t('openregister', 'An entry with no hash is one the chain cannot vouch for. A background job sweeps these every five minutes and seals the oldest first, so a backlog after heavy write activity is normal and drains on its own. A backlog that never shrinks is not — it means sealing is failing on both the write path and the sweep.') }}
					</p>
				</div>

				<div v-else class="integrity__state integrity__state--success">
					<div class="integrity__state-badge">
						<ShieldCheck :size="20" />
						<span>{{ t('openregister', 'Every entry is sealed') }}</span>
					</div>
					<p class="integrity__hint">
						{{ t('openregister', 'Every audit entry carries a hash. That says the sweeper is keeping up — it does not by itself prove the stored hashes still agree with their rows. Verify the chain to establish that.') }}
					</p>
				</div>
			</div>

			<!-- Verification: expensive, on demand only -->
			<div class="integrity__verify">
				<h4 class="integrity__subtitle">
					{{ t('openregister', 'Chain verification') }}
				</h4>
				<p class="integrity__hint">
					{{ t('openregister', 'Recomputes every hash and compares it with the one stored. This reads the whole audit trail, so it is run on request rather than each time this page opens.') }}
				</p>

				<NcButton
					variant="secondary"
					:disabled="verifying"
					class="integrity__verify-button"
					@click="verifyChain">
					<template #icon>
						<NcLoadingIcon v-if="verifying" :size="20" />
						<ShieldSearch v-else :size="20" />
					</template>
					{{ verifying ? t('openregister', 'Verifying …') : t('openregister', 'Verify chain') }}
				</NcButton>

				<div v-if="verification !== null" class="integrity__state" :class="verification.valid ? 'integrity__state--success' : 'integrity__state--error'">
					<div class="integrity__state-badge">
						<ShieldCheck v-if="verification.valid" :size="20" />
						<ShieldAlert v-else :size="20" />
						<span v-if="verification.valid">{{ t('openregister', 'Chain intact') }}</span>
						<span v-else>{{ t('openregister', 'Chain broken') }}</span>
					</div>

					<p v-if="verification.valid" class="integrity__hint">
						{{ t('openregister', 'Verified {count} entries against their stored hashes with no mismatch. The history has not been rewritten.', { count: formatNumber(verification.entriesVerified) }) }}
					</p>

					<template v-else>
						<p class="integrity__hint">
							{{ t('openregister', 'Verification stopped at entry {id} after {count} entries matched. From that entry on, the recomputed hash disagrees with the one stored.', { id: verification.brokenAt, count: formatNumber(verification.entriesVerified) }) }}
						</p>
						<p class="integrity__hint">
							{{ t('openregister', 'This has two possible causes and they are not equally serious: the entry was altered after it was written, or it was sealed before the seal lock existed and chained onto the wrong predecessor. Read the entry before deciding. Repair is a deliberate operator action — it rewrites stored hashes, which is exactly the event this chain exists to make visible — so it is only available as a command:') }}
						</p>
						<code class="integrity__command">occ openregister:rechain-audit-trail</code>
					</template>
				</div>

				<div v-if="error !== ''" class="integrity__state integrity__state--error">
					<div class="integrity__state-badge">
						<ShieldAlert :size="20" />
						<span>{{ error }}</span>
					</div>
				</div>
			</div>
		</div>
	</SettingsSection>
</template>

<script>
import SettingsSection from '../../../components/shared/SettingsSection.vue'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import AlertOutline from 'vue-material-design-icons/AlertOutline.vue'
import ShieldAlert from 'vue-material-design-icons/ShieldAlert.vue'
import ShieldCheck from 'vue-material-design-icons/ShieldCheck.vue'
import ShieldSearch from 'vue-material-design-icons/ShieldSearch.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Log Integrity section.
 *
 * Surfaces the audit hash chain, which until now had no representation in the
 * UI at all — the chain could develop gaps, or break outright, and the only way
 * to find out was to call the verify endpoint by hand. That is how 49,123
 * unsealed rows accumulated unnoticed on a live instance.
 *
 * Two numbers are shown, and the component keeps them deliberately distinct:
 *
 *   - Seal COVERAGE is cheap (three COUNT queries) and loads with the page.
 *   - VERIFICATION rehashes the entire trail and is only ever run on request.
 *
 * Coverage answers "is the sweeper keeping up"; verification answers "does the
 * stored history still hash to what it claims". Presenting full coverage as
 * though it were a clean verification would be the most misleading thing this
 * card could do, so the success copy says so explicitly.
 *
 * @category Component
 * @package
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @link https://www.openregister.nl
 *
 * @spec openspec/specs/audit-hash-chain/spec.md
 */
export default {
	name: 'LogIntegrity',

	components: {
		SettingsSection,
		NcButton,
		NcLoadingIcon,
		AlertOutline,
		ShieldAlert,
		ShieldCheck,
		ShieldSearch,
	},

	data() {
		return {
			/** Seal coverage summary; null while loading. */
			status: null,
			/** Result of the last on-demand verification; null if not run. */
			verification: null,
			/** Whether a verification is in flight. */
			verifying: false,
			/** Last error message, empty when there is none. */
			error: '',
		}
	},

	computed: {
		/**
		 * Colour the coverage bar by how much of the trail is unvouched for.
		 *
		 * @return {string} The modifier class.
		 */
		barClass() {
			if (this.status === null || this.status.unsealed === 0) {
				return 'integrity__bar-fill--success'
			}
			return this.status.coverage < 95 ? 'integrity__bar-fill--error' : 'integrity__bar-fill--warning'
		},

		/**
		 * Tooltip spelling the bar out, for anyone who cannot read the colour.
		 *
		 * @return {string} The title text.
		 */
		coverageTitle() {
			if (this.status === null) {
				return ''
			}
			return this.t('openregister', '{sealed} of {total} entries sealed', {
				sealed: this.formatNumber(this.status.sealed),
				total: this.formatNumber(this.status.total),
			})
		},
	},

	/**
	 * Load the cheap coverage summary when the section is created.
	 *
	 * @spec exclude UI plumbing — view-creation data fetch for display only
	 * @return {Promise<void>}
	 */
	async created() {
		await this.loadStatus()
	},

	methods: {
		/**
		 * Fetch seal coverage.
		 *
		 * @spec openspec/specs/audit-hash-chain/spec.md
		 * @return {Promise<void>}
		 */
		async loadStatus() {
			try {
				const response = await axios.get(generateUrl('/apps/openregister/api/audit-trails/integrity'))
				this.status = response.data
			} catch (error) {
				console.error('Failed to load audit integrity status:', error)
				this.error = this.t('openregister', 'Could not read the seal coverage of the audit trail.')
			}
		},

		/**
		 * Run the full chain verification and refresh coverage alongside it.
		 *
		 * @spec openspec/specs/audit-hash-chain/spec.md
		 * @return {Promise<void>}
		 */
		async verifyChain() {
			this.verifying = true
			this.error = ''
			this.verification = null

			try {
				const response = await axios.get(generateUrl('/apps/openregister/api/audit-trails/verify'))
				this.verification = response.data
				await this.loadStatus()
			} catch (error) {
				console.error('Failed to verify audit chain:', error)
				this.error = this.t('openregister', 'Verification could not be completed.')
			} finally {
				this.verifying = false
			}
		},

		/**
		 * Group digits so six-figure counts stay readable.
		 *
		 * @param {number} value The number to format.
		 * @return {string} The grouped representation.
		 */
		formatNumber(value) {
			return Number(value || 0).toLocaleString()
		},
	},
}
</script>

<style scoped>
.integrity {
	margin-top: 8px;
}

.integrity__bar {
	height: 10px;
	border-radius: var(--border-radius);
	background: var(--color-background-dark);
	overflow: hidden;
}

.integrity__bar-fill {
	height: 100%;
	transition: width 0.3s ease;
}

.integrity__bar-fill--success {
	background: var(--color-success);
}

.integrity__bar-fill--warning {
	background: var(--color-warning);
}

.integrity__bar-fill--error {
	background: var(--color-error);
}

.integrity__figures {
	display: flex;
	flex-wrap: wrap;
	gap: 24px;
	margin: 16px 0;
}

.integrity__figure {
	display: flex;
	flex-direction: column;
}

.integrity__figure-value {
	font-size: 22px;
	font-weight: 600;
}

.integrity__figure-label {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.integrity__figure--attention .integrity__figure-value {
	color: var(--color-warning);
}

.integrity__state {
	padding: 16px;
	border-radius: var(--border-radius-large);
	border-left: 4px solid;
	margin-bottom: 16px;
}

.integrity__state--success {
	background: var(--color-success-light, rgba(var(--color-success-rgb), 0.1));
	border-color: var(--color-success);
}

.integrity__state--warning {
	background: var(--color-warning-light, rgba(var(--color-warning-rgb), 0.1));
	border-color: var(--color-warning);
}

.integrity__state--error {
	background: var(--color-error-light, rgba(var(--color-error-rgb), 0.1));
	border-color: var(--color-error);
}

.integrity__state-badge {
	display: flex;
	align-items: center;
	gap: 8px;
	font-weight: 600;
	font-size: 15px;
	margin-bottom: 8px;
}

.integrity__state--success .integrity__state-badge {
	color: var(--color-success);
}

.integrity__state--warning .integrity__state-badge {
	color: var(--color-warning);
}

.integrity__state--error .integrity__state-badge {
	color: var(--color-error);
}

.integrity__hint {
	font-size: 14px;
	color: var(--color-text-maxcontrast);
	margin: 0 0 8px;
	line-height: 1.6;
}

.integrity__subtitle {
	font-size: 15px;
	font-weight: 600;
	margin: 0 0 8px;
}

.integrity__verify-button {
	margin: 8px 0 16px;
}

.integrity__command {
	display: inline-block;
	font-family: var(--font-face-monospace, monospace);
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	padding: 4px 8px;
	word-break: break-all;
}
</style>
