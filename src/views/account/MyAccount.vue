<template>
	<div class="my-account">
		<h2>{{ t('openregister', 'My Account') }}</h2>
		<p class="my-account__description">
			{{ t('openregister', 'Manage your account settings, security, and personal data.') }}
		</p>

		<PasswordSection />
		<AvatarSection />
		<NotificationsSection />
		<ActivitySection />
		<TokensSection />
		<ExportSection />
		<AccountSection />
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import PasswordSection from './sections/PasswordSection.vue'
import AvatarSection from './sections/AvatarSection.vue'
import NotificationsSection from './sections/NotificationsSection.vue'
import ActivitySection from './sections/ActivitySection.vue'
import TokensSection from './sections/TokensSection.vue'
import ExportSection from './sections/ExportSection.vue'
import AccountSection from './sections/AccountSection.vue'

export default {
	name: 'MyAccount',
	components: {
		PasswordSection,
		AvatarSection,
		NotificationsSection,
		ActivitySection,
		TokensSection,
		ExportSection,
		AccountSection,
	},
	methods: {
		t,
	},
}
</script>

<style scoped>
.my-account {
	max-width: 900px;
	margin: 0 auto;
	padding: 20px;
}

.my-account__description {
	color: var(--color-text-maxcontrast);
	margin-bottom: 24px;
}
</style>
