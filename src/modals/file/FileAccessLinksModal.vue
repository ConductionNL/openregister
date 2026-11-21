<template>
	<NcModal :name="t('openregister', 'Access links')" size="normal" @close="$emit('close')">
		<div class="file-access-links-modal">
			<h2>{{ t('openregister', 'Access links') }}</h2>
			<p v-if="file.fileName" class="file-access-links-modal__name">
				{{ file.fileName }}
			</p>
			<SubjectAccessLinks subjectType="file" :subjectId="fileSubject" />
		</div>
	</NcModal>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcModal } from '@nextcloud/vue'
import SubjectAccessLinks from '../../components/access-links/SubjectAccessLinks.vue'

/**
 * Share a file by link: the access links over one file an object carries,
 * so someone without an account can download it.
 *
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
 */
export default {
	name: 'FileAccessLinksModal',
	components: { NcModal, SubjectAccessLinks },

	props: {
		/** The file row: its id, its object's uuid and its name. */
		file: {
			type: Object,
			required: true,
		},
	},

	emits: ['close'],

	computed: {
		/**
		 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
		 * @return {string} The subject a file link names: `<object uuid>/<file id>`.
		 */
		fileSubject() {
			return `${this.file.objectUuid}/${this.file.id}`
		},
	},

	methods: { t },
}
</script>

<style scoped>
.file-access-links-modal {
	padding: 24px;
}

.file-access-links-modal__name {
	color: var(--color-text-maxcontrast);
	margin-bottom: 12px;
}
</style>
