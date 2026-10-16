<template>
	<!-- Re-importing takes the file's authorization over a local change, and
	     re-imports the whole app's configuration where it has a base
	     document: say so before it runs, not after. -->
	<NcDialog
		:open="true"
		:name="t('openregister', 'Re-import {register}?', { register: row.title })"
		size="small"
		@update:open="onOpenChange">
		<p>
			{{
				t(
					'openregister',
					'Re-importing {app} takes the authorization of its schemas from the file, replacing any change made on this instance, and may re-import every register of the app, not only {register}.',
					{ app: row.appId, register: row.title },
				)
			}}
		</p>
		<template #actions>
			<NcButton @click="$emit('cancel')">
				{{ t('openregister', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" @click="$emit('confirm')">
				{{ t('openregister', 'Re-import') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'

/**
 * The question asked before a register descriptor is re-imported.
 *
 * @spec openspec/changes/reimport-real-register-configuration/specs/register-descriptor-admin/spec.md
 */
export default {
	name: 'ReimportDescriptorDialog',
	components: { NcButton, NcDialog },
	props: {
		/** The descriptor row to re-import ({appId, title, ...}). */
		row: { type: Object, required: true },
	},

	emits: ['cancel', 'confirm'],

	methods: {
		/**
		 * The dialog's own close button or Escape cancels.
		 *
		 * @param {boolean} open Whether the dialog stays open.
		 * @return {void}
		 *
		 * @spec openspec/changes/reimport-real-register-configuration/specs/register-descriptor-admin/spec.md
		 */
		onOpenChange(open) {
			if (!open) {
				this.$emit('cancel')
			}
		},
	},
}
</script>
