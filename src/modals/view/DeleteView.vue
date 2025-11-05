<template>
	<NcDialog
		:name="t('openregister', 'Delete View')"
		size="normal"
		:can-close="false">
		<p v-if="!success">
			{{ t('openregister', 'Are you sure you want to permanently delete') }} <b>{{ view?.name || t('openregister', 'Untitled View') }}</b>? 
			{{ t('openregister', 'This action cannot be undone.') }}
		</p>

		<NcNoteCard v-if="!success" type="warning">
			<p><strong>{{ t('openregister', 'Warning:') }}</strong> {{ t('openregister', 'This will permanently delete:') }}</p>
			<ul>
				<li>{{ t('openregister', 'The saved view and all its search configuration') }}</li>
				<li>{{ t('openregister', 'Any favorites and sharing settings for this view') }}</li>
			</ul>
		</NcNoteCard>

		<NcNoteCard v-if="success" type="success">
			<p>{{ t('openregister', 'View successfully deleted') }}</p>
		</NcNoteCard>

		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<template #actions>
			<NcButton @click="closeDialog">
				<template #icon>
					<Close :size="20" />
				</template>
				{{ success ? t('openregister', 'Close') : t('openregister', 'Cancel') }}
			</NcButton>
			<NcButton
				v-if="!success"
				:disabled="loading"
				type="error"
				@click="deleteView()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<Delete v-if="!loading" :size="20" />
				</template>
				{{ t('openregister', 'Delete View') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcDialog,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'

import Close from 'vue-material-design-icons/Close.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import { translate as t } from '@nextcloud/l10n'
import { viewsStore } from '../../store/store.js'

export default {
	name: 'DeleteView',
	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		Close,
		Delete,
	},
	props: {
		view: {
			type: Object,
			default: null,
		},
	},
	data() {
		return {
			success: false,
			loading: false,
			error: null,
			closeModalTimeout: null,
		}
	},
	methods: {
		t,
		closeDialog() {
			clearTimeout(this.closeModalTimeout)
			this.success = false
			this.loading = false
			this.error = null
			this.$emit('close')
		},
		async deleteView() {
			if (!this.view) return

			this.loading = true
			this.error = null

			try {
				await viewsStore.deleteView(this.view.id || this.view.uuid)
				this.success = true

				// Auto-close after 2 seconds
				this.closeModalTimeout = setTimeout(() => {
					this.closeDialog()
				}, 2000)
			} catch (error) {
				console.error('Error deleting view:', error)
				this.success = false
				this.error = error.response?.data?.error || error.message || this.t('openregister', 'An error occurred while deleting the view')
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
p {
	margin: 12px 0;
}

ul {
	margin: 8px 0;
	padding-left: 24px;
}

li {
	margin: 4px 0;
}
</style>

