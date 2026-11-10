<template>
	<div class="selected-objects-container">
		<h4>
			{{
				t('openregister', 'Selected Objects ({count})', {
					count: objects.length,
				})
			}}
		</h4>

		<div v-if="objects.length" class="selected-objects-list">
			<div v-for="obj in objects" :key="obj.id" class="selected-object-item">
				<div class="object-info">
					<strong>{{
						titleOf(obj) || t('openregister', 'Unnamed Object')
					}}</strong>
					<p class="object-id">
						{{
							t('openregister', 'ID: {id}', {
								id: obj.id || obj['@self']?.id,
							})
						}}
					</p>
				</div>
				<NcButton
					variant="tertiary"
					:aria-label="
						t('openregister', 'Remove {title}', {
							title: titleOf(obj) || obj.id,
						})
					"
					@click="$emit('remove', obj.id)">
					<template #icon>
						<Close :size="20" />
					</template>
				</NcButton>
			</div>
		</div>

		<NcEmptyContent v-else :name="t('openregister', 'No objects selected')">
			<template #description>
				{{ emptyDescription }}
			</template>
		</NcEmptyContent>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcEmptyContent } from '@nextcloud/vue'
import Close from 'vue-material-design-icons/Close.vue'

/**
 * The name or title of an object, when it has one.
 *
 * @param {object} obj The object
 * @return {string|undefined} Its name
 */
function objectName(obj) {
	return obj['@self']?.name || obj.name || obj.title || obj['@self']?.title
}

/**
 * The objects a bulk dialog acts on, each with a button to take it out.
 *
 * @spec exclude Presentational list shared by the bulk object dialogs.
 */
export default {
	name: 'SelectedObjectsList',
	components: {
		NcButton,
		NcEmptyContent,
		Close,
	},

	props: {
		objects: {
			type: Array,
			required: true,
		},

		/** What the empty list tells the user. */
		emptyDescription: {
			type: String,
			required: true,
		},

		/** The name to show for an object; undefined when it has none. */
		titleOf: {
			type: Function,
			default: objectName,
		},
	},

	emits: ['remove'],

	methods: {
		t,
	},
}
</script>

<style scoped lang="scss">
@use '../../styles/modals.scss' as *;

.selected-objects-container {
	margin: 20px 0;
}

@include selected-objects-list-rules;

.selected-object-item:last-child {
	border-bottom: none;
}

@include object-info-strong-rules;
</style>
