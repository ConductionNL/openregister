<template>
	<SettingsSection
		id="push-notifications"
		:name="t('openregister', 'Push Notifications')"
		:description="
			t('openregister', 'Real-time push notification status via notify_push')
		">
		<div class="push-status-section">
			<!-- not_installed status -->
			<PushStatus
				v-if="pushStatus === 'not_installed'"
				tone="error"
				:label="t('openregister', 'Realtime push not available — the notify_push app is not installed')">
				<p class="push-status__hint">
					{{
						t(
							'openregister',
							'Install the notify_push app from the Nextcloud App Store to enable real-time updates.',
						)
					}}
					<a
						href="https://apps.nextcloud.com/apps/notify_push"
						target="_blank"
						rel="noopener noreferrer"
						class="push-status__link">
						{{ t('openregister', 'Open Nextcloud App Store') }}
					</a>
				</p>
			</PushStatus>

			<!-- unreachable status -->
			<PushStatus
				v-else-if="pushStatus === 'unreachable'"
				tone="warning"
				:label="t('openregister', 'notify_push is installed but not yet active')">
				<p class="push-status__hint">
					{{
						t(
							'openregister',
							'notify_push is installed but OpenRegister has not yet confirmed a successful push. Trigger an object save to activate, or check your notify_push configuration.',
						)
					}}
					<a
						href="https://github.com/nextcloud/notify_push#configuration"
						target="_blank"
						rel="noopener noreferrer"
						class="push-status__link">
						{{ t('openregister', 'notify_push configuration guide') }}
					</a>
				</p>
			</PushStatus>

			<!-- active status -->
			<PushStatus
				v-else-if="pushStatus === 'active'"
				tone="success"
				:label="t('openregister', 'Realtime push active')">
				<p class="push-status__hint">
					{{
						t(
							'openregister',
							'Real-time push notifications are active. Connected clients receive instant updates when objects are created, updated, or deleted.',
						)
					}}
				</p>
			</PushStatus>

			<!-- Browser Web Push (VAPID) status -->
			<div class="push-vapid-section">
				<h4 class="push-vapid-section__title">
					{{ t('openregister', 'Browser Web Push (VAPID)') }}
				</h4>

				<!-- configured -->
				<PushStatus
					v-if="vapidConfigured === true"
					tone="success"
					:label="t('openregister', 'Browser web push is configured')">
					<p class="push-status__hint">
						{{
							t(
								'openregister',
								'A VAPID keypair is configured. Users can opt in to browser notifications from their user settings, which are delivered even when the browser tab is closed.',
							)
						}}
					</p>
					<p
						v-if="vapidPublicKey"
						class="push-status__hint push-vapid-section__key">
						<span class="push-vapid-section__key-label">{{
							t('openregister', 'VAPID public key')
						}}</span>
						<code class="push-vapid-section__key-value">{{
							vapidPublicKey
						}}</code>
					</p>
				</PushStatus>

				<!-- not configured -->
				<PushStatus
					v-else-if="vapidConfigured === false"
					tone="warning"
					:label="t('openregister', 'Browser web push is not configured')">
					<p class="push-status__hint">
						{{
							t(
								'openregister',
								'No VAPID keypair is configured yet. Generate one with the following occ command to enable browser notifications:',
							)
						}}
					</p>
					<p class="push-status__hint">
						<code class="push-vapid-section__command"
							>occ openregister:web-push:generate-vapid</code
						>
					</p>
				</PushStatus>

				<!-- loading -->
				<div v-else class="push-status__hint">
					{{
						t(
							'openregister',
							'Checking browser web push configuration …',
						)
					}}
				</div>
			</div>
		</div>
	</SettingsSection>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import SettingsSection from '../../../components/shared/SettingsSection.vue'
import PushStatus from './PushStatus.vue'

/**
 * Push Notifications Configuration Component
 *
 * Displays the current notify_push status (not_installed | unreachable | active)
 * and the browser Web Push (VAPID) configuration status (configured-or-not +
 * the public key + an occ-command hint), guiding the admin towards the correct
 * action.
 *
 * @category Component
 * @package
 *
 * @author   Conduction Development Team <info@conduction.nl>
 * @copyright 2025 Conduction B.V.
 * @license  EUPL-1.2
 *
 * @see https://www.openregister.nl
 *
 * @spec openspec/changes/add-live-updates/tasks.md#task-11
 * @spec openspec/changes/archive/2026-10-05-openregister-web-push-engine/tasks.md#task-5.1
 */
export default {
	name: 'PushNotificationsConfiguration',

	components: {
		SettingsSection,
		PushStatus,
	},

	props: {
		/**
		 * Push status string passed from backend via initial state or props.
		 * One of: 'not_installed' | 'unreachable' | 'active'
		 */
		pushStatus: {
			type: String,
			default: 'not_installed',
			validator: (value) =>
				['not_installed', 'unreachable', 'active'].includes(value),
		},
	},

	data() {
		return {
			/** Whether a VAPID keypair is configured. null while loading. */
			vapidConfigured: null,
			/** The configured VAPID public key (never the private key). */
			vapidPublicKey: '',
		}
	},

	/**
	 * Fetch the browser Web Push (VAPID) configuration status on mount.
	 *
	 * @spec exclude UI plumbing — view-creation data fetch for display only
	 * @return {Promise<void>}
	 */
	async created() {
		await this.loadVapidStatus()
	},

	methods: {
		/**
		 * Load the VAPID public key + configured flag from the backend.
		 *
		 * @spec openspec/changes/archive/2026-10-05-openregister-web-push-engine/tasks.md#task-5.1
		 * @return {Promise<void>}
		 */
		async loadVapidStatus() {
			try {
				const response = await axios.get(
					generateUrl('/apps/openregister/webpush/vapid-public-key'),
				)
				this.vapidConfigured = response.data.configured === true
				this.vapidPublicKey = response.data.publicKey || ''
			} catch (error) {
				console.error('Failed to load VAPID status:', error)
				this.vapidConfigured = false
				this.vapidPublicKey = ''
			}
		},
	},
}
</script>

<style scoped lang="scss">
@use './sectionStyles.scss' as *;

.push-status-section {
	margin-top: 8px;
}

.push-status__hint {
	font-size: 14px;
	color: var(--color-text-maxcontrast);
	margin: 0;
	line-height: 1.6;
}

.push-status__link {
	display: inline-block;
	margin-top: 8px;
	color: var(--color-primary-element);
	text-decoration: underline;
}

.push-status__link:hover {
	color: var(--color-primary-element-hover);
}

.push-vapid-section {
	margin-top: 8px;
}

.push-vapid-section__title {
	font-size: 15px;
	font-weight: 600;
	margin: 0 0 8px;
}

.push-vapid-section__key {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-top: 8px;
}

.push-vapid-section__key-label {
	font-weight: 600;
}

.push-vapid-section__key-value,
.push-vapid-section__command {
	@include code-chip;
}
</style>
