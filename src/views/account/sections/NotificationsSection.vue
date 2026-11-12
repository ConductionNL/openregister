<template>
	<div class="section">
		<h2>{{ t('openregister', 'Notifications') }}</h2>
		<div v-if="loading" class="section__loading">
			{{ t('openregister', 'Loading preferences...') }}
		</div>
		<div v-else class="notifications-section">
			<div
				v-for="(label, key) in toggleLabels"
				:key="key"
				class="notifications-section__toggle">
				<NcCheckboxRadioSwitch
					v-model="prefs[key]"
					@update:modelValue="save">
					{{ label }}
				</NcCheckboxRadioSwitch>
			</div>
			<div class="notifications-section__digest">
				<label for="email-digest">{{
					t('openregister', 'Email digest frequency')
				}}</label>
				<NcSelect
					v-model="prefs.emailDigest"
					inputLabel="Prefs Email Digest"
					:options="digestOptions"
					inputId="email-digest"
					@update:modelValue="save" />
			</div>
			<SectionStatus :message="message" :isError="isError" />
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcCheckboxRadioSwitch, NcSelect } from '@nextcloud/vue'
import SectionStatus from '../../../components/shared/SectionStatus.vue'
import { sectionStatus } from '../../../composables/sectionStatus.js'

export default {
	name: 'NotificationsSection',
	components: { NcCheckboxRadioSwitch, NcSelect, SectionStatus },

	mixins: [sectionStatus],

	data() {
		return {
			loading: true,
			prefs: {
				objectChanges: true,
				assignments: true,
				organisationChanges: true,
				systemAnnouncements: true,
				emailDigest: 'daily',
			},

			digestOptions: ['none', 'daily', 'weekly'],
			toggleLabels: {
				objectChanges: t('openregister', 'Object changes in owned objects'),
				assignments: t('openregister', 'Assignment notifications'),
				organisationChanges: t(
					'openregister',
					'Organisation membership changes',
				),

				systemAnnouncements: t('openregister', 'System announcements'),
			},
		}
	},

	/**
	 * @spec exclude settings-section lifecycle fetch of notification preferences on mount
	 */
	async mounted() {
		try {
			const { data } = await axios.get(
				generateUrl('/apps/openregister/api/user/me/notifications'),
			)
			this.prefs = { ...this.prefs, ...data }
		} catch {
			// Use defaults.
		} finally {
			this.loading = false
		}
	},

	methods: {
		t,
		/**
		 * @spec exclude settings-section save plumbing; PUTs notification preferences and shows a status message
		 */
		async save() {
			this.message = ''
			try {
				const { data } = await axios.put(
					generateUrl('/apps/openregister/api/user/me/notifications'),
					this.prefs,
				)
				this.prefs = { ...this.prefs, ...data }
				this.setStatus(t('openregister', 'Preferences saved'))
			} catch (e) {
				this.setStatusError(
					e,
					t('openregister', 'Failed to save preferences'),
				)
			}
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../../styles/views.scss' as *;

@include section;

.section__loading {
	color: var(--color-text-maxcontrast);
}

@include section-error-rules;

.notifications-section__toggle {
	margin-bottom: 8px;
}

.notifications-section__digest {
	margin-top: 16px;
}

.notifications-section__digest label {
	display: block;
	margin-bottom: 4px;
	font-weight: bold;
}
</style>
