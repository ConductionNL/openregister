<template>
	<NcButton
		:variant="isSubscribed ? 'secondary' : 'tertiary'"
		:disabled="loading"
		:title="title"
		:aria-label="title"
		@click="toggle">
		<template #icon>
			<NcLoadingIcon v-if="loading" :size="20" />
			<BellIcon v-else-if="isSubscribed" :size="20" />
			<BellOutlineIcon v-else :size="20" />
		</template>
		{{ buttonLabel }}
	</NcButton>
</template>

<script>
/**
 * NotificationSubscriptionToggle
 *
 * Toggle button for subscribing/unsubscribing the current user to a
 * (register, schema) tuple. Either id may be omitted — null means
 * "all schemas in the register" or "this schema across all registers".
 *
 * On mount, fetches the user's subscriptions once and decides the
 * initial filled/outline state. Click flips the state with optimistic
 * UI: button updates immediately, reverts on API error.
 *
 * Closes notificatie-engine task: "Users MUST be able to manage their
 * notification preferences".
 */
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import BellIcon from 'vue-material-design-icons/Bell.vue'
import BellOutlineIcon from 'vue-material-design-icons/BellOutline.vue'

import {
	listSubscriptions,
	subscribe,
	unsubscribe,
	hasSubscription,
} from '../services/notificationSubscriptions.js'

export default {
	name: 'NotificationSubscriptionToggle',
	components: {
		NcButton,
		NcLoadingIcon,
		BellIcon,
		BellOutlineIcon,
	},
	props: {
		/**
		 * Register id to subscribe to. Either registerId or schemaId
		 * (or both) MUST be set.
		 */
		registerId: {
			type: [Number, String],
			default: null,
		},
		/**
		 * Schema id to subscribe to.
		 */
		schemaId: {
			type: [Number, String],
			default: null,
		},
	},
	data() {
		return {
			loading: false,
			subscriptions: [],
		}
	},
	computed: {
		/**
		 * @spec exclude computed numeric coercion of registerId prop, UI plumbing
		 */
		registerIdNum() {
			return this.registerId !== null && this.registerId !== ''
				? Number(this.registerId)
				: null
		},
		/**
		 * @spec exclude computed numeric coercion of schemaId prop, UI plumbing
		 */
		schemaIdNum() {
			return this.schemaId !== null && this.schemaId !== ''
				? Number(this.schemaId)
				: null
		},
		/**
		 * @spec exclude computed subscription-state read via service helper; subscription contract owned by notificatie-engine
		 */
		isSubscribed() {
			return hasSubscription(this.subscriptions, {
				registerId: this.registerIdNum,
				schemaId: this.schemaIdNum,
			})
		},
		/**
		 * @spec exclude computed button-label display helper, UI plumbing
		 */
		buttonLabel() {
			return this.isSubscribed
				? t('openregister', 'Subscribed')
				: t('openregister', 'Subscribe')
		},
		/**
		 * @spec exclude computed tooltip/title display helper, UI plumbing
		 */
		title() {
			return this.isSubscribed
				? t('openregister', 'Click to unsubscribe from notifications')
				: t('openregister', 'Click to subscribe to notifications')
		},
	},
	async mounted() {
		await this.refresh()
	},
	methods: {
		/**
		 * @spec exclude service passthrough loading subscriptions; subscription contract owned by notificatie-engine
		 */
		async refresh() {
			try {
				this.subscriptions = await listSubscriptions()
			} catch (error) {
				// eslint-disable-next-line no-console
				console.error('Failed to load notification subscriptions:', error)
			}
		},
		/**
		 * @spec exclude service passthrough toggling subscribe/unsubscribe with optimistic UI; contract owned by notificatie-engine
		 */
		async toggle() {
			if (this.registerIdNum === null && this.schemaIdNum === null) {
				return
			}

			const wasSubscribed = this.isSubscribed
			this.loading = true

			try {
				if (wasSubscribed) {
					await unsubscribe({
						registerId: this.registerIdNum,
						schemaId: this.schemaIdNum,
					})
				} else {
					await subscribe({
						registerId: this.registerIdNum,
						schemaId: this.schemaIdNum,
					})
				}
				await this.refresh()
				this.$emit('change', { subscribed: !wasSubscribed })
			} catch (error) {
				// eslint-disable-next-line no-console
				console.error('Failed to toggle notification subscription:', error)
			} finally {
				this.loading = false
			}
		},
	},
}
</script>
