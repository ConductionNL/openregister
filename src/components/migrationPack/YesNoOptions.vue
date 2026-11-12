<!--
  The options of a yes/no select in the migration-pack editor, with a
  disabled placeholder while nothing is chosen.

  @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-administrators-must-be-able-to-list-and-edit-migration-packs-in-the-openregister-ui
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.
-->
<template>
	<option v-if="choice === ''" value="" disabled>
		{{ t('openregister', 'Choose yes or no') }}
	</option>
	<option value="true">
		{{ t('openregister', 'Yes') }}
	</option>
	<option value="false">
		{{ t('openregister', 'No') }}
	</option>
</template>

<script>
import { t } from '../../views/migrationPack/l10n.js'

export default {
	name: 'YesNoOptions',
	props: {
		/** The current choice: 'true', 'false', or '' while none is chosen. */
		choice: { type: String, required: true },
	},

	methods: { t },
}
</script>
