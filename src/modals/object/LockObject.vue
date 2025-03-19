<script setup>
import { objectStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.modal === 'lockObject'"
		name="Lock Object"
		size="normal"
		:can-close="false">
		<p v-if="success === null" class="lockP">
			Do you want to lock <b>{{ objectStore.objectItem?.uuid }}</b>?<br/><br/>Locking an object prevents other users from modifying it until it is unlocked. You can specify an optional process name to indicate why it's locked and a duration after which it will automatically unlock. Only the user who locked the object or an administrator can unlock it before the duration expires.
		</p>
		<NcNoteCard v-if="success" type="success">
			<p>Object successfully locked</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<template #actions>
			<div class="buttonContainer">
				<NcButton @click="closeModal">
					<template #icon>
						<Cancel :size="20" />
					</template>
					{{ success ? 'Close' : 'Cancel' }}
				</NcButton>
				<NcButton
					:disabled="loading || success"
					type="primary"
					@click="lockObject()">
					<template #icon>
						<NcLoadingIcon v-if="loading" :size="20" />
						<LockOutline v-else :size="20" />
					</template>
					Lock
				</NcButton>
			</div>
		</template>

		<div v-if="!success" class="formContainer modalSpacing10">
			<NcTextField
				:value.sync="process"
				label="Process Name (optional)"
				:disabled="loading" />
			<NcTextField
				type="number"
				:value.sync="duration"
				label="Duration in seconds (optional)"
				:disabled="loading" />
		</div>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcDialog,
	NcTextField,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'

import LockOutline from 'vue-material-design-icons/LockOutline.vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'

export default {
	name: 'LockObject',
	components: {
		NcDialog,
		NcTextField,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		LockOutline,
		Cancel,
	},
	data() {
		return {
			process: '',
			duration: 3600,
			success: null,
			loading: false,
			error: null,
			closeModalTimeout: null,
		}
	},
	methods: {
		closeModal() {
			navigationStore.setModal(false)
			clearTimeout(this.closeModalTimeout)
			this.success = null
			this.loading = false
			this.error = null
			this.process = ''
			this.duration = 3600
		},
		async lockObject() {
			this.loading = true

			try {
				await objectStore.lockObject(
					objectStore.objectItem.id,
					this.process || undefined,
					this.duration || undefined,
				)
				this.success = true
				this.error = null
				this.closeModalTimeout = setTimeout(this.closeModal, 2000)
			} catch (error) {
				this.error = error.message || 'Failed to lock object'
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style>
.lockP {
	margin-bottom: 15px;
}
.modalSpacing10 {
	display: flex;
	flex-direction: column;
	gap: 10px;
}
</style>