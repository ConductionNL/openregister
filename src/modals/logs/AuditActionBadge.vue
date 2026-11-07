<!--
SPDX-FileCopyrightText: 2026 Conduction <info@conduction.nl>
SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<span class="action-badge" :class="`action-${action}`">
		<Plus v-if="action === 'create'" :size="16" />
		<Pencil v-else-if="action === 'update'" :size="16" />
		<Delete v-else-if="action === 'delete'" :size="16" />
		<Eye v-else-if="action === 'read'" :size="16" />
		{{ action?.toUpperCase() }}
	</span>
</template>

<script>
import Delete from 'vue-material-design-icons/Delete.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Plus from 'vue-material-design-icons/Plus.vue'

/**
 * The action of an audit trail entry as a badge: its icon and its name. The
 * modal that shows it styles `.action-badge` and its `action-*` colours.
 *
 * @spec exclude Presentational badge shared by the audit trail modals.
 */
export default {
	name: 'AuditActionBadge',
	components: {
		Delete,
		Eye,
		Pencil,
		Plus,
	},

	props: {
		/** The audit trail action, e.g. create, update, delete or read. */
		action: {
			type: String,
			default: undefined,
		},
	},
}
</script>
