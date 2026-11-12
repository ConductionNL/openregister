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
					<strong>{{ shownName(obj) }}</strong>
					<p class="object-id">
						{{ t('openregister', 'ID: {id}', { id: idOf(obj) }) }}
					</p>
				</div>
				<NcButton
					variant="tertiary"
					:aria-label="
						t('openregister', 'Remove {title}', {
							title: removedName(obj),
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

		/**
		 * How an object is named, on the list and on its remove button. By
		 * default its name or title, else "Unnamed Object" on the list and
		 * its id on the button.
		 */
		nameOf: {
			type: Function,
			default: null,
		},

		/** The id shown for an object. By default its id, else its `@self` id. */
		idOf: {
			type: Function,
			default: (obj) => obj.id || obj['@self']?.id,
		},
	},

	emits: ['remove'],

	methods: {
		t,

		/**
		 * The name to show for an object.
		 *
		 * @spec exclude Client-side display helper.
		 * @param {object} obj The object
		 * @return {string|undefined} Its name or title, when it has one
		 */
		titleOf(obj) {
			return obj['@self']?.name || obj.name || obj.title || obj['@self']?.title
		},

		/**
		 * The name shown for an object on the list.
		 *
		 * @spec exclude Client-side display helper.
		 * @param {object} obj The object
		 * @return {string} Its name
		 */
		shownName(obj) {
			if (this.nameOf) {
				return this.nameOf(obj)
			}
			return this.titleOf(obj) || t('openregister', 'Unnamed Object')
		},

		/**
		 * The name an object's remove button says it removes.
		 *
		 * @spec exclude Client-side display helper.
		 * @param {object} obj The object
		 * @return {string} Its name, or its id when it has none
		 */
		removedName(obj) {
			if (this.nameOf) {
				return this.nameOf(obj)
			}
			return this.titleOf(obj) || obj.id
		},
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
