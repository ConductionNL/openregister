<template>
	<!-- Re-importing takes the file's authorization over a local change, and
	     re-imports the whole app's configuration where it has a base
	     document: say so before it runs, not after. -->
	<CnDialog
		:name="t('openregister', 'Re-import {register}?', { register: row.title })"
		size="small"
		@closing="$emit('cancel')">
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
			<CnDialogActions
				:cancelLabel="t('openregister', 'Cancel')"
				:confirmLabel="t('openregister', 'Re-import')"
				@cancel="$emit('cancel')"
				@submit="$emit('confirm')" />
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog, CnDialogActions } from '@conduction/nextcloud-vue'

/**
 * The question asked before a register descriptor is re-imported.
 *
 * @spec openspec/changes/reimport-real-register-configuration/specs/register-descriptor-admin/spec.md
 */
export default {
	name: 'ReimportDescriptorDialog',
	components: { CnDialog, CnDialogActions },
	props: {
		/** The descriptor row to re-import ({appId, title, ...}). */
		row: { type: Object, required: true },
	},

	emits: ['cancel', 'confirm'],
}
</script>
