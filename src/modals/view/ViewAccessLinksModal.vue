<template>
	<NcModal :name="t('openregister', 'Access links')" size="normal" @close="$emit('close')">
		<div class="view-access-links-modal">
			<h2>{{ t('openregister', 'Access links') }}</h2>
			<p v-if="view.name" class="view-access-links-modal__name">
				{{ view.name }}
			</p>
			<SubjectAccessLinks subjectType="view" :subjectId="viewId" />
		</div>
	</NcModal>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcModal } from '@nextcloud/vue'
import SubjectAccessLinks from '../../components/access-links/SubjectAccessLinks.vue'

/**
 * Share a saved view by link: the access links over one view, so someone
 * without an account can read the rows that view selects.
 *
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
 */
export default {
	name: 'ViewAccessLinksModal',
	components: { NcModal, SubjectAccessLinks },

	props: {
		/** The saved view the links open. */
		view: {
			type: Object,
			required: true,
		},
	},

	emits: ['close'],

	computed: {
		/**
		 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
		 * @return {string} The id the link names the view by.
		 */
		viewId() {
			return String(this.view.id ?? this.view.uuid ?? '')
		},
	},

	methods: { t },
}
</script>

<style scoped>
.view-access-links-modal {
	padding: 24px;
}

.view-access-links-modal__name {
	color: var(--color-text-maxcontrast);
	margin-bottom: 12px;
}
</style>
