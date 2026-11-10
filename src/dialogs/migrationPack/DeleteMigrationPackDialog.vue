<!--
  Confirm the delete of one migration pack.

  The list owns the delete itself: Delete emits confirm, Cancel, Escape and
  the close button emit close. A built-in pack says that the app which
  installed it puts it back on its next install or update.

  @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-pack-changes-must-be-limited-to-administrators-and-logged
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.
-->
<template>
	<NcDialog
		:open="open"
		:name="t('openregister', 'Delete migration pack')"
		size="small"
		@update:open="onOpenChange">
		<p>
			{{
				t(
					'openregister',
					'Delete "{name}"? Imports that use this pack will no longer find it.',
					{ name: pack.name || pack.packSlug },
				)
			}}
		</p>
		<p v-if="pack.builtin">
			{{
				t(
					'openregister',
					'This pack was installed by an app. That app installs it again, unedited, the next time it is installed or updated.',
				)
			}}
		</p>
		<template #actions>
			<NcButton :disabled="busy" @click="$emit('close')">
				{{ t('openregister', 'Cancel') }}
			</NcButton>
			<NcButton variant="error" :disabled="busy" @click="$emit('confirm')">
				{{ t('openregister', 'Delete') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'
import { t } from '../../views/migrationPack/l10n.js'

export default {
	name: 'DeleteMigrationPackDialog',
	components: { NcButton, NcDialog },

	props: {
		open: { type: Boolean, required: true },
		pack: { type: Object, required: true },
		busy: { type: Boolean, default: false },
	},

	emits: ['close', 'confirm'],

	methods: {
		t,
		/**
		 * Close on the dialog's own close button or Escape.
		 *
		 * @param {boolean} open Whether the dialog stays open.
		 *
		 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-pack-changes-must-be-limited-to-administrators-and-logged
		 */
		onOpenChange(open) {
			if (!open) {
				this.$emit('close')
			}
		},
	},
}
</script>
