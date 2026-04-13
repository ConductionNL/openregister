<template>
	<div class="section">
		<h2>{{ t('openregister', 'Notifications') }}</h2>
		<div v-if="loading" class="section__loading">
			{{ t('openregister', 'Loading preferences...') }}
		</div>
		<div v-else class="notifications-section">
			<div v-for="(label, key) in toggleLabels" :key="key" class="notifications-section__toggle">
				<NcCheckboxRadioSwitch :checked.sync="prefs[key]" @update:checked="save">
					{{ label }}
				</NcCheckboxRadioSwitch>
			</div>
			<div class="notifications-section__digest">
				<label for="email-digest">{{ t('openregister', 'Email digest frequency') }}</label>
				<NcSelect v-model="prefs.emailDigest"
					:options="digestOptions"
					input-id="email-digest"
					@input="save" />
			</div>
			<p v-if="message" :class="{ 'section__error': isError, 'section__success': !isError }">
				{{ message }}
			</p>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcCheckboxRadioSwitch from '@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch.js'
import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js'

export default {
	name: 'NotificationsSection',
	components: { NcCheckboxRadioSwitch, NcSelect },
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
			message: '',
			isError: false,
			digestOptions: ['none', 'daily', 'weekly'],
			toggleLabels: {
				objectChanges: t('openregister', 'Object changes in owned objects'),
				assignments: t('openregister', 'Assignment notifications'),
				organisationChanges: t('openregister', 'Organisation membership changes'),
				systemAnnouncements: t('openregister', 'System announcements'),
			},
		}
	},
	async mounted() {
		try {
			const { data } = await axios.get(generateUrl('/apps/openregister/api/user/me/notifications'))
			this.prefs = { ...this.prefs, ...data }
		} catch (e) {
			// Use defaults.
		} finally {
			this.loading = false
		}
	},
	methods: {
		t,
		async save() {
			this.message = ''
			try {
				const { data } = await axios.put(
					generateUrl('/apps/openregister/api/user/me/notifications'),
					this.prefs,
				)
				this.prefs = { ...this.prefs, ...data }
				this.message = t('openregister', 'Preferences saved')
				this.isError = false
			} catch (e) {
				this.message = e.response?.data?.error || t('openregister', 'Failed to save preferences')
				this.isError = true
			}
		},
	},
}
</script>

<style scoped>
.section { margin-bottom: 32px; padding: 16px; border-bottom: 1px solid var(--color-border); }
.section__loading { color: var(--color-text-maxcontrast); }
.section__error { color: var(--color-error); margin-top: 8px; }
.section__success { color: var(--color-success); margin-top: 8px; }
.notifications-section__toggle { margin-bottom: 8px; }
.notifications-section__digest { margin-top: 16px; }
.notifications-section__digest label { display: block; margin-bottom: 4px; font-weight: bold; }
</style>
