<!--
  Ask before leaving a migration pack with unsaved changes.

  The editor owns the navigation: Leave emits leave, Stay, Escape and the
  close button emit stay.

  @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.
-->
<template>
	<NcDialog
		:name="t('openregister', 'Leave without saving?')"
		size="small"
		@update:open="onOpenChange">
		<p>
			{{
				t(
					'openregister',
					'This pack has changes that are not saved. If you leave now, they are lost.',
				)
			}}
		</p>
		<template #actions>
			<NcButton variant="error" @click="$emit('leave')">
				{{ t('openregister', 'Leave and lose changes') }}
			</NcButton>
			<NcButton variant="primary" @click="$emit('stay')">
				{{ t('openregister', 'Stay on this page') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'
import { t } from '../../views/migrationPack/l10n.js'

export default {
	name: 'UnsavedChangesDialog',
	components: { NcButton, NcDialog },

	emits: ['leave', 'stay'],

	methods: {
		t,
		/**
		 * Stay on the dialog's own close button or Escape.
		 *
		 * @param {boolean} open Whether the dialog stays open.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
		 */
		onOpenChange(open) {
			if (!open) {
				this.$emit('stay')
			}
		},
	},
}
</script>
