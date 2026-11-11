<template>
	<h3>{{ t('openregister', 'Execution Mode') }}</h3>
	<div class="execution-modes">
		<NcCheckboxRadioSwitch
			v-model="mode"
			value="serial"
			name="execution_mode"
			type="radio">
			{{ t('openregister', 'Serial Mode (Safer, slower)') }}
		</NcCheckboxRadioSwitch>
		<NcCheckboxRadioSwitch
			v-model="mode"
			value="parallel"
			name="execution_mode"
			type="radio">
			{{
				t('openregister', 'Parallel Mode (Faster, more resource intensive)')
			}}
		</NcCheckboxRadioSwitch>
	</div>

	<div class="mode-descriptions">
		<p>
			<strong>{{ t('openregister', 'Serial:') }}</strong>
			{{ serialText }}
		</p>
		<p>
			<strong>{{ t('openregister', 'Parallel:') }}</strong>
			{{ parallelText }}
		</p>
	</div>
</template>

<script>
import { NcCheckboxRadioSwitch } from '@nextcloud/vue'
import { modelProxy } from './modelProxy.js'

/**
 * The serial or parallel choice of a vectorization run, with a line on
 * what each mode does.
 *
 * @spec exclude Presentational form section shared by the vectorization modals.
 */
export default {
	name: 'VectorizationModeChoice',
	components: { NcCheckboxRadioSwitch },

	props: {
		modelValue: { type: String, required: true },
		serialText: { type: String, required: true },
		parallelText: { type: String, required: true },
	},

	emits: ['update:modelValue'],

	computed: {
		mode: modelProxy(),
	},
}
</script>
