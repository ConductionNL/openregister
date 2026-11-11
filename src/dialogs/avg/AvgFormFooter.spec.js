/**
 * AvgFormFooter renders what the three AVG case dialogs wrote inline before
 * their </form>: the error and the Cancel / submit buttons, disabled while
 * saving or while the form is incomplete.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec exclude Unit test of a presentational form footer.
 */
import { mount } from '@vue/test-utils'
import AvgFormFooter from './AvgFormFooter.vue'

jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => `t:${text}` }))
jest.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		inheritAttrs: false,
		emits: ['click'],
		template: `<button :type="$attrs.type || 'button'" :disabled="$attrs.disabled" :data-variant="$attrs.variant" @click="$emit('click', $event)"><slot name="icon" /><slot /></button>`,
	},
	NcLoadingIcon: { name: 'NcLoadingIcon', template: '<i class="loading" />' },
	NcNoteCard: { name: 'NcNoteCard', props: ['type'], template: '<div class="note" :data-type="type"><slot /></div>' },
}))

const library = jest.requireMock('@nextcloud/vue')

const OLD = {
	components: library,
	props: ['error', 'saving', 'incomplete'],
	emits: ['close'],
	methods: { t: (app, text) => `t:${text}` },
	template: `<form>
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<div class="avgFormActions">
			<NcButton
				variant="tertiary"
				:disabled="saving"
				@click="$emit('close')">
				{{ t('openregister', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				type="submit"
				:disabled="saving || incomplete">
				<template #icon>
					<NcLoadingIcon v-if="saving" :size="20" />
				</template>
				{{ t('openregister', 'Draft denial') }}
			</NcButton>
		</div>
	</form>`,
}

const NEW = {
	components: { AvgFormFooter },
	props: ['error', 'saving', 'incomplete'],
	emits: ['close'],
	template: `<form>
		<AvgFormFooter
			:error="error"
			:saving="saving"
			:incomplete="incomplete"
			submitLabel="t:Draft denial"
			@close="$emit('close')" />
	</form>`,
}

/**
 * The markup with scope ids, fragment anchors and collapsible whitespace removed.
 *
 * @param {object} wrapper A mounted component
 * @return {string} Its HTML
 */
function html(wrapper) {
	return wrapper
		.html()
		.replace(/ data-v-[a-z0-9]+(="")?/g, '')
		.replace(/<!--[^>]*-->/g, '')
		.replace(/>\s+/g, '>')
		.replace(/\s+</g, '<')
}

describe('AvgFormFooter', () => {
	for (const [name, props] of Object.entries({
		ready: { error: null, saving: false, incomplete: false },
		incomplete: { error: null, saving: false, incomplete: true },
		saving: { error: null, saving: true, incomplete: false },
		failed: { error: 'refused', saving: false, incomplete: false },
	})) {
		it(`renders the inline footer when ${name}`, () => {
			expect(html(mount(NEW, { props }))).toBe(html(mount(OLD, { props })))
		})
	}

	it('closes on Cancel and submits its form', async () => {
		const onSubmit = jest.fn((event) => event.preventDefault())
		const wrapper = mount(NEW, {
			props: { error: null, saving: false, incomplete: false },
			attachTo: document.body,
		})
		wrapper.element.addEventListener('submit', onSubmit)
		const [cancel, submit] = wrapper.findAll('button')
		await cancel.trigger('click')
		expect(wrapper.emitted('close')).toHaveLength(1)
		submit.element.click()
		expect(onSubmit).toHaveBeenCalledTimes(1)
		wrapper.unmount()
	})
})
