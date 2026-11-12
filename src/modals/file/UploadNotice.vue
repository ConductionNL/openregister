<template>
	<div v-if="success !== null || error">
		<NcNoteCard v-if="success" type="success">
			<p>
				{{ t('openregister', 'Files added successfully') }}
			</p>
		</NcNoteCard>
		<NcNoteCard v-if="error && !success" type="error">
			<p>
				{{ t('openregister', 'Something went wrong while adding files') }}
			</p>
		</NcNoteCard>
		<NcNoteCard v-if="error && !success" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>
	</div>
	<div
		class="filesListDragDropNoticeWrapper"
		:class="{ 'filesListDragDropNoticeWrapper--disabled': disabled }">
		<div class="filesListDragDropNoticeWrapperIcon">
			<TrayArrowDown :size="48" />
			<h3 class="filesListDragDropNoticeTitle">
				{{ t('openregister', 'Drag a file or files here') }}
			</h3>
		</div>

		<h3 class="filesListDragDropNoticeTitle">
			{{ t('openregister', 'Or') }}
		</h3>

		<div class="filesListDragDropNoticeTitle">
			<NcButton :disabled="disabled" variant="primary" @click="$emit('add')">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('openregister', 'Add a file or files') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
/**
 * The result of an upload and the prompt to add files, in the drop panel of
 * the upload modal.
 *
 * @spec exclude Presentational part of the upload modal.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcNoteCard } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import TrayArrowDown from 'vue-material-design-icons/TrayArrowDown.vue'

export default {
	name: 'UploadNotice',

	components: { NcButton, NcNoteCard, Plus, TrayArrowDown },

	props: {
		success: { type: Boolean, default: null },
		error: { type: [Boolean, String], default: false },
		disabled: { type: Boolean, default: false },
	},

	emits: ['add'],

	methods: { t },
}
</script>

<style scoped>
.filesListDragDropNoticeWrapper--disabled {
	opacity: 0.4;
}
</style>
