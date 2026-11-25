<template>
	<SettingsSection
		id="notification-rule-reach"
		:name="t('openregister', 'Notification rules that reached nobody')"
		:description="t('openregister', 'Rules whose recipients resolved to nobody, so nothing was sent and nobody was told. Add members to the groups they name, or change their recipients.')">
		<p v-if="state === 'loading'" class="rule-reach__hint">
			{{ t('openregister', 'Loading…') }}
		</p>
		<p v-else-if="state === 'failed'" class="rule-reach__hint">
			{{ t('openregister', 'Could not load which notification rules reached nobody.') }}
		</p>
		<p v-else-if="rules.length === 0" class="rule-reach__hint">
			{{ t('openregister', 'Every notification rule reached at least one recipient.') }}
		</p>
		<ul v-else class="rule-reach__list">
			<li v-for="rule in rules" :key="rule.rule" class="rule-reach__rule">
				<code class="rule-reach__name">{{ rule.rule }}</code>
				<span class="rule-reach__hint">
					{{ t('openregister', 'Times reached nobody: {count}. Last on {date}.', { count: rule.occurrences, date: formatDate(rule.lastSeen) }) }}
				</span>
			</li>
		</ul>
	</SettingsSection>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import SettingsSection from '../../../components/shared/SettingsSection.vue'

/**
 * The notification rules that resolved to no recipients, for administrators.
 *
 * Reads `GET /api/notification-rule-reach`, the persisted record of rules
 * that reached nobody, so an administrator configuring notifications sees an
 * unstaffed rule without knowing which log line to search for.
 *
 * @spec openspec/changes/a-rule-that-reaches-nobody-says-so/specs/notificatie-engine/spec.md#requirement-the-record-reaches-a-person-not-only-a-log-req-rrn-03
 */
export default {
	name: 'NotificationRuleReach',

	components: {
		SettingsSection,
	},

	data() {
		return {
			/** One of loading | loaded | failed. */
			state: 'loading',
			/** The rules that reached nobody, newest first. */
			rules: [],
		}
	},

	/**
	 * Load the record when the section is created.
	 *
	 * @spec openspec/changes/a-rule-that-reaches-nobody-says-so/specs/notificatie-engine/spec.md#requirement-the-record-reaches-a-person-not-only-a-log-req-rrn-03
	 * @return {Promise<void>}
	 */
	async created() {
		await this.load()
	},

	methods: {
		/**
		 * Read which rules reached nobody.
		 *
		 * @spec openspec/changes/a-rule-that-reaches-nobody-says-so/specs/notificatie-engine/spec.md#requirement-the-record-reaches-a-person-not-only-a-log-req-rrn-03
		 * @return {Promise<void>}
		 */
		async load() {
			try {
				const response = await axios.get(generateUrl('/apps/openregister/api/notification-rule-reach'))
				const rules = response?.data?.rulesReachingNobody
				this.rules = Array.isArray(rules) ? rules : []
				this.state = 'loaded'
			} catch {
				this.rules = []
				this.state = 'failed'
			}
		},

		/**
		 * A stored ISO time in the viewer's locale; an unreadable one as given.
		 *
		 * @spec openspec/changes/a-rule-that-reaches-nobody-says-so/specs/notificatie-engine/spec.md#requirement-the-record-reaches-a-person-not-only-a-log-req-rrn-03
		 * @param {string} iso The time
		 * @return {string} The time to show
		 */
		formatDate(iso) {
			const date = new Date(iso)
			if (Number.isNaN(date.getTime())) {
				return String(iso ?? '')
			}
			return date.toLocaleString()
		},
	},
}
</script>

<style scoped lang="scss">
.rule-reach__hint {
	font-size: 14px;
	color: var(--color-text-maxcontrast);
	margin: 0;
	line-height: 1.6;
}

.rule-reach__list {
	list-style: none;
	margin: 8px 0 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.rule-reach__rule {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.rule-reach__name {
	font-weight: 600;
	color: var(--color-main-text);
}
</style>
