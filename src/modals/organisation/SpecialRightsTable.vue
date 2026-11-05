<template>
	<div class="special-rights-container">
		<p class="rbac-description">
			{{
				t(
					'openregister',
					'Grant additional permissions beyond standard CRUD operations',
				)
			}}
		</p>

		<table class="rbac-table special-rights-table">
			<thead>
				<tr>
					<th scope="col">{{ t('openregister', 'Right') }}</th>
					<th scope="col">{{ t('openregister', 'Description') }}</th>
					<th scope="col">{{ t('openregister', 'Groups') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="row in rights" :key="row.right">
					<td class="right-name">
						<span class="right-badge">{{ row.right }}</span>
					</td>
					<td class="right-description">
						{{ row.description() }}
					</td>
					<td class="right-groups">
						<NcSelect
							:modelValue="selected[row.right]"
							:inputLabel="row.inputLabel"
							:options="groups"
							label="name"
							trackBy="id"
							:multiple="true"
							:placeholder="t('openregister', 'Select groups...')"
							@update:modelValue="
								$emit('update', { right: row.right, groups: $event })
							" />
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcSelect } from '@nextcloud/vue'
import { SPECIAL_RIGHTS } from './organisationRights.js'

/**
 * The table granting each special right to groups.
 *
 * @spec exclude Presentational table of the organisation modal's Security tab.
 */
export default {
	name: 'SpecialRightsTable',
	components: { NcSelect },

	props: {
		selected: { type: Object, required: true },
		groups: { type: Array, required: true },
	},

	emits: ['update'],

	computed: {
		/** @return {Array<object>} The rights offered. */
		rights() {
			return SPECIAL_RIGHTS
		},
	},

	methods: { t },
}
</script>

<style scoped lang="scss">
@use '../../styles/modals.scss' as *;

@include rbac-description;

.special-rights-container {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 0;
}

.special-rights-table {
	width: 100%;
	border-collapse: collapse;
	border: 1px solid var(--color-border-dark);
	border-radius: 8px;
	overflow: hidden;
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.special-rights-table thead tr {
	background: var(--color-background-dark);
}

.special-rights-table th {
	color: var(--color-text-dark);
	font-weight: 600;
	padding: 12px 16px;
	text-align: left;
	border-bottom: 2px solid var(--color-border-dark);
}

.special-rights-table td {
	padding: 12px 16px;
	border-bottom: 1px solid var(--color-border);
	vertical-align: middle;
}

.special-rights-table tbody tr:hover {
	background: var(--color-background-hover);
}

.right-name {
	width: 20%;
}

.right-badge {
	display: inline-block;
	padding: 4px 12px;
	border-radius: 12px;
	font-size: 12px;
	font-weight: 600;
	background: var(--color-primary-element);
	color: var(--color-primary-text);
}

.right-description {
	width: 40%;
	font-size: 13px;
	color: var(--color-text-lighter);
}

.right-groups {
	width: 40%;
}
</style>
