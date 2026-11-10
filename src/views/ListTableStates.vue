<template>
	<div class="tableContainer" :class="{ 'is-loading': loading }">
		<div v-if="loading" class="loadingWrapper">
			<NcLoadingIcon :size="64" />
		</div>

		<NcEmptyContent
			v-else-if="empty"
			:name="emptyName"
			:description="emptyDescription">
			<template #icon>
				<slot name="emptyIcon" />
			</template>
		</NcEmptyContent>

		<slot v-else />

		<slot name="footer" />
	</div>
</template>

<script>
/**
 * The table container of a list page: a spinner while loading, the empty
 * state when there are no rows, else the table, and a footer in every state.
 *
 * Slots: default (the table), emptyIcon (the empty state's icon) and footer
 * (shown below in every state, such as the pager).
 *
 * @spec exclude UI plumbing — shared list-table container markup.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'

export default {
	name: 'ListTableStates',
	components: {
		NcEmptyContent,
		NcLoadingIcon,
	},

	props: {
		/** Whether the rows are loading. */
		loading: {
			type: Boolean,
			default: false,
		},

		/** Whether there are no rows to show. */
		empty: {
			type: Boolean,
			default: false,
		},

		/** The empty state's title. */
		emptyName: {
			type: String,
			default: '',
		},

		/** The empty state's description. */
		emptyDescription: {
			type: String,
			default: '',
		},
	},
}
</script>

<style scoped lang="scss">
@use '../styles/views.scss' as *;

@include table-container-2;

@include table-container-is-loading;

@include loading-wrapper;
</style>
