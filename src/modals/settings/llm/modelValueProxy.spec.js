import { mount } from '@vue/test-utils'
import modelValueProxy from './modelValueProxy.js'

const Field = {
	mixins: [modelValueProxy],
	props: { modelValue: { type: String, default: '' } },
	template: '<input v-model="model">',
}

describe('modelValueProxy mixin', () => {
	it('reads the model from the modelValue prop', () => {
		const wrapper = mount(Field, { props: { modelValue: 'gpt' } })
		expect(wrapper.vm.model).toBe('gpt')
		expect(wrapper.find('input').element.value).toBe('gpt')
	})

	it('emits update:modelValue when the model is written, without mutating the prop', async () => {
		const wrapper = mount(Field, { props: { modelValue: 'gpt' } })
		await wrapper.find('input').setValue('claude')
		expect(wrapper.emitted('update:modelValue')).toEqual([['claude']])
		expect(wrapper.props('modelValue')).toBe('gpt')
	})
})
