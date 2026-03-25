<template>
	<section class="or-mail-suggested-objects" aria-labelledby="or-mail-suggested-title">
		<h3 id="or-mail-suggested-title" class="or-mail-section-title">
			{{ t('openregister', 'Related Cases') }}
		</h3>
		<div v-if="loading" class="or-mail-loading">
			<span class="icon-loading-small" />
			{{ t('openregister', 'Loading...') }}
		</div>
		<div v-else-if="objects.length === 0" class="or-mail-empty">
			{{ t('openregister', 'No related cases found for this sender') }}
		</div>
		<div v-else class="or-mail-object-list">
			<ObjectCard
				v-for="obj in objects"
				:key="obj.objectUuid"
				:object="obj"
				:show-unlink="false" />
		</div>
	</section>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import ObjectCard from './ObjectCard.vue'

export default {
	name: 'SuggestedObjectsList',
	components: { ObjectCard },
	props: {
		objects: {
			type: Array,
			default: () => [],
		},
		loading: {
			type: Boolean,
			default: false,
		},
	},
	methods: { t },
}
</script>
