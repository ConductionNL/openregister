<template>
	<SettingsSection
		id="push-notifications"
		:name="t('openregister', 'Push Notifications')"
		:description="
			t('openregister', 'Real-time push notification status via notify_push')
		">
		<div class="push-status-section">
			<!-- not_installed status -->
			<div
				v-if="pushStatus === 'not_installed'"
				class="push-status push-status--error">
				<div class="push-status__badge">
					<AlertCircle :size="20" />
					<span>{{
						t(
							'openregister',
							'Realtime push not available — the notify_push app is not installed',
						)
					}}</span>
				</div>
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
			</div>

			<!-- unreachable status -->
			<div
				v-else-if="pushStatus === 'unreachable'"
				class="push-status push-status--warning">
				<div class="push-status__badge">
					<AlertOutline :size="20" />
					<span>{{
						t(
							'openregister',
							'notify_push is installed but not yet active',
						)
					}}</span>
				</div>
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
			</div>

			<!-- active status -->
			<div
				v-else-if="pushStatus === 'active'"
				class="push-status push-status--success">
				<div class="push-status__badge">
					<CheckCircle :size="20" />
					<span>{{ t('openregister', 'Realtime push active') }}</span>
				</div>
				<p class="push-status__hint">
					{{
						t(
							'openregister',
							'Real-time push notifications are active. Connected clients receive instant updates when objects are created, updated, or deleted.',
						)
					}}
				</p>
			</div>

			<!-- Browser Web Push (VAPID) status -->
			<div class="push-vapid-section">
				<h4 class="push-vapid-section__title">
					{{ t('openregister', 'Browser Web Push (VAPID)') }}
				</h4>

				<!-- configured -->
				<div
					v-if="vapidConfigured === true"
					class="push-status push-status--success">
					<div class="push-status__badge">
						<CheckCircle :size="20" />
						<span>{{
							t('openregister', 'Browser web push is configured')
						}}</span>
					</div>
					<p class="push-status__hint">
						{{
							t(
								'openregister',
								'A VAPID keypair is configured. Users can opt in to browser notifications from their personal settings, which are delivered even when the browser tab is closed.',
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
				</div>

				<!-- not configured -->
				<div
					v-else-if="vapidConfigured === false"
					class="push-status push-status--warning">
					<div class="push-status__badge">
						<AlertOutline :size="20" />
						<span>{{
							t('openregister', 'Browser web push is not configured')
						}}</span>
					</div>
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
				</div>

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
import SettingsSection from '../../../components/shared/SettingsSection.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import AlertOutline from 'vue-material-design-icons/AlertOutline.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

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
 * @link https://www.openregister.nl
 *
 * @spec openspec/changes/add-live-updates/tasks.md#task-11
 * @spec openspec/changes/openregister-web-push-engine/tasks.md#task-5.1
 */
export default {
	name: 'PushNotificationsConfiguration',

	components: {
		SettingsSection,
		AlertCircle,
		AlertOutline,
		CheckCircle,
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
		 * @spec openspec/changes/openregister-web-push-engine/tasks.md#task-5.1
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

<style scoped>
.push-status-section {
	margin-top: 8px;
}

.push-status {
	padding: 16px;
	border-radius: var(--border-radius-large);
	border-left: 4px solid;
	margin-bottom: 16px;
}

.push-status--error {
	background: var(--color-error-light, rgba(var(--color-error-rgb), 0.1));
	border-color: var(--color-error);
}

.push-status--warning {
	background: var(--color-warning-light, rgba(var(--color-warning-rgb), 0.1));
	border-color: var(--color-warning);
}

.push-status--success {
	background: var(--color-success-light, rgba(var(--color-success-rgb), 0.1));
	border-color: var(--color-success);
}

.push-status__badge {
	display: flex;
	align-items: center;
	gap: 8px;
	font-weight: 600;
	font-size: 15px;
	margin-bottom: 8px;
}

.push-status--error .push-status__badge {
	color: var(--color-error);
}

.push-status--warning .push-status__badge {
	color: var(--color-warning);
}

.push-status--success .push-status__badge {
	color: var(--color-success);
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
	display: inline-block;
	font-family: var(--font-face-monospace, monospace);
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	padding: 4px 8px;
	word-break: break-all;
}
</style>
