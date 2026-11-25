<template>
	<span class="or-object-row">
		<span class="or-object-row__head">
			<NcLoadingIcon v-if="busy" :size="16" class="or-object-row__spinner" />
			<span class="or-object-row__kind">{{ kind }}</span>
			<span v-if="identifier" class="or-object-row__id">#{{ identifier }}</span>
			<span class="or-object-row__name">{{ nameParts.before }}<mark v-if="nameParts.match" class="or-object-row__mark">{{ nameParts.match }}</mark>{{ nameParts.after }}</span>
		</span>
		<span v-if="status || handler || trailing" class="or-object-row__sub">
			<ObjectStatus :status="status" :handler="handler" />
			<span v-if="trailing" class="or-object-row__trailing">{{ trailing }}</span>
		</span>
	</span>
</template>

<script>
/**
 * One object as the boards draw a match (MlConnectionsStates): its kind in
 * small capitals, its record number, its name with the typed words marked,
 * and its status and handler under it.
 *
 * @spec openspec/specs/mail-sidebar/spec.md
 */
import { NcLoadingIcon } from '@nextcloud/vue'
import ObjectStatus from './ObjectStatus.vue'
import { markParts } from '../objectRow.js'

export default {
	name: 'ObjectRow',
	components: { NcLoadingIcon, ObjectStatus },
	props: {
		/** The kind of object, such as the schema title ("Case"). */
		kind: { type: String, default: '' },
		/** The record number, shown as #number; left out when empty. */
		identifier: { type: String, default: '' },
		name: { type: String, default: '' },
		status: { type: String, default: '' },
		/** The handler's display name. */
		handler: { type: String, default: '' },
		/** The typed words, marked in the name. */
		term: { type: String, default: '' },
		/** Show a spinner before the kind, while the object is being connected. */
		busy: { type: Boolean, default: false },
		/** A short word at the end of the second line, such as "Connecting". */
		trailing: { type: String, default: '' },
	},

	computed: {
		/**
		 * @spec openspec/specs/mail-sidebar/spec.md
		 */
		nameParts() {
			return markParts(this.name, this.term)
		},
	},
}
</script>
