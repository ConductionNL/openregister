<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Per-user opt-in browser-notifications toggle (openregister-web-push-engine).

 Drives the browser Push API subscription through the always-loaded client
 (window.OCA.OpenRegister.WebPush). It is OPT-IN ONLY: it NEVER auto-prompts —
 the notification-permission request fires solely on an explicit toggle gesture,
 per Chrome's permission-abuse rules. Shows the current permission state and an
 unsupported-browser notice when the Push API is unavailable.

 TODO mount: this repo registers NO PHP Personal settings class (only
 lib/Settings/OpenRegisterAdmin + IntegrationsAdminSettings, both admin). To
 surface this toggle to end users, add an OCP\Settings\ISettings personal
 settings class + a `<settings><personal>…</personal></settings>` entry in
 appinfo/info.xml and a personal-settings Vue entry point (mirroring
 src/settings.js → #settings) that mounts this component. Until that personal
 mount point exists the component is unmounted; it is fully functional and only
 needs the host shell. See openspec/changes/openregister-web-push-engine.

 @spec openspec/changes/openregister-web-push-engine/tasks.md#task-52
-->
<template>
	<NcSettingsSection :name="t('openregister', 'Browser notifications')"
		:description="t('openregister', 'Receive OpenRegister notifications as native browser notifications, even when the tab is closed.')"
		data-testid="browser-notifications-section">
		<NcNoteCard v-if="!supported" type="warning" data-testid="browser-notifications-unsupported">
			{{ t('openregister', 'This browser does not support web push notifications.') }}
		</NcNoteCard>

		<template v-else>
			<NcCheckboxRadioSwitch
				type="switch"
				:model-value="enabled"
				:disabled="busy"
				data-testid="browser-notifications-toggle"
				@update:modelValue="onToggle">
				{{ t('openregister', 'Enable browser notifications') }}
			</NcCheckboxRadioSwitch>

			<p class="user-settings-section__hint" data-testid="browser-notifications-permission">
				{{ permissionLabel }}
			</p>

			<NcNoteCard v-if="error" type="error" data-testid="browser-notifications-error">
				{{ error }}
			</NcNoteCard>
		</template>
	</NcSettingsSection>
</template>

<script>
import { NcCheckboxRadioSwitch, NcNoteCard, NcSettingsSection } from '@nextcloud/vue'

export default {
	name: 'BrowserNotificationsSection',
	components: {
		NcCheckboxRadioSwitch,
		NcNoteCard,
		NcSettingsSection,
	},
	data() {
		return {
			/** Whether the browser supports web push. */
			supported: false,
			/** Whether browser notifications are currently enabled. */
			enabled: false,
			/** Whether a subscribe/unsubscribe call is in flight. */
			busy: false,
			/** Current notification permission state. */
			permission: 'default',
			/** Last error message, if any. */
			error: null,
		}
	},
	computed: {
		/**
		 * Human-readable label for the current permission state.
		 *
		 * @return {string} The localised permission label.
		 * @spec openspec/changes/openregister-web-push-engine/tasks.md#task-52
		 */
		permissionLabel() {
			switch (this.permission) {
			case 'granted':
				return this.t('openregister', 'Notification permission: granted')
			case 'denied':
				return this.t('openregister', 'Notification permission: denied. Re-enable notifications for this site in your browser settings.')
			default:
				return this.t('openregister', 'Notification permission: not yet requested. Enabling the toggle will ask for permission.')
			}
		},
	},
	mounted() {
		this.refreshState()
	},
	methods: {
		/**
		 * Resolve the WebPush client exposed by the always-loaded script.
		 *
		 * @return {object|null} The WebPush client, or null when absent.
		 * @spec openspec/changes/openregister-web-push-engine/tasks.md#task-52
		 */
		client() {
			return (window.OCA && window.OCA.OpenRegister && window.OCA.OpenRegister.WebPush) || null
		},
		/**
		 * Read support + permission state from the WebPush client. Never prompts.
		 *
		 * @spec openspec/changes/openregister-web-push-engine/tasks.md#task-52
		 * @return {void}
		 */
		refreshState() {
			// Feature-detect directly rather than via the (deferred) push-client
			// script: the client may not have executed yet when this component
			// mounts, and depending on it would wrongly report "not supported".
			// The client global is only needed for the enable/disable actions,
			// by which point the deferred script has long since run.
			this.supported = (typeof navigator !== 'undefined'
				&& 'serviceWorker' in navigator
				&& typeof window !== 'undefined'
				&& 'PushManager' in window
				&& 'Notification' in window)
			this.permission = ('Notification' in window) ? Notification.permission : 'unsupported'
			// A granted permission does not guarantee an active subscription, but
			// it is the only non-async signal we have without a network call; the
			// authoritative enable/disable result comes from the toggle handlers.
			this.enabled = (this.permission === 'granted')
		},
		/**
		 * Handle a toggle gesture: subscribe on enable, unsubscribe on disable.
		 *
		 * The permission prompt only ever fires from this user gesture.
		 *
		 * @param {boolean} checked The requested toggle state.
		 * @spec openspec/changes/openregister-web-push-engine/tasks.md#task-52
		 * @return {Promise<void>}
		 */
		async onToggle(checked) {
			const client = this.client()
			if (client === null) {
				this.error = this.t('openregister', 'Browser notification client is still loading — please try again in a moment.')
				return
			}
			this.busy = true
			this.error = null
			try {
				if (checked === true) {
					await client.enablePush()
					this.enabled = true
				} else {
					await client.disablePush()
					this.enabled = false
				}
			} catch (e) {
				this.error = e.message || this.t('openregister', 'Could not change browser notification settings.')
				// Revert the optimistic state on failure.
				this.enabled = !checked
			} finally {
				this.permission = client.permission()
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.user-settings-section {
	margin-bottom: 24px;
}

.user-settings-section__hint {
	color: var(--color-text-maxcontrast);
	margin-bottom: 8px;
}
</style>
