<template>
	<NcButton
		:type="isSubscribed ? 'secondary' : 'tertiary'"
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
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
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
		registerIdNum() {
			return this.registerId !== null && this.registerId !== ''
				? Number(this.registerId)
				: null
		},
		schemaIdNum() {
			return this.schemaId !== null && this.schemaId !== ''
				? Number(this.schemaId)
				: null
		},
		isSubscribed() {
			return hasSubscription(this.subscriptions, {
				registerId: this.registerIdNum,
				schemaId: this.schemaIdNum,
			})
		},
		buttonLabel() {
			return this.isSubscribed
				? t('openregister', 'Subscribed')
				: t('openregister', 'Subscribe')
		},
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
		async refresh() {
			try {
				this.subscriptions = await listSubscriptions()
			} catch (error) {
				// eslint-disable-next-line no-console
				console.error('Failed to load notification subscriptions:', error)
			}
		},
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
