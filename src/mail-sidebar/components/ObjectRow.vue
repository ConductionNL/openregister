<template>
	<span class="or-object-row" :class="{ 'or-object-row--wrap': wrap }">
		<span class="or-object-row__head">
			<span class="or-object-row__kind">{{ kind }}</span>
			<span v-if="identifier" class="or-object-row__id">#{{ identifier }}</span>
			<span class="or-object-row__name">{{ name }}</span>
		</span>
		<span v-if="subline" class="or-object-row__sub">{{ subline }}</span>
	</span>
</template>

<script>
/**
 * One object as the picker boards draw it (WpPickerStates): its kind in
 * small capitals, its record number, its name, and one muted line under it.
 *
 * @spec openspec/specs/mail-sidebar/spec.md
 */
export default {
	name: 'ObjectRow',
	props: {
		/** The kind of object, such as the schema title ("Case"). */
		kind: { type: String, default: '' },
		/** The record number, shown as #number; left out when empty. */
		identifier: { type: String, default: '' },
		name: { type: String, default: '' },
		/** One muted line under the name; left out when empty. */
		subline: { type: String, default: '' },
		/** Let the name wrap instead of cutting it off, as a picked row does. */
		wrap: { type: Boolean, default: false },
	},
}
</script>
