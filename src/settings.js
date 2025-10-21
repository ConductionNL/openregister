import Vue from 'vue'
import { createPinia, PiniaVuePlugin } from 'pinia'
import Settings from './views/settings/Settings.vue'
import { translate, translatePlural } from '@nextcloud/l10n'

// Install Pinia plugin
Vue.use(PiniaVuePlugin)

// Create Pinia instance
const pinia = createPinia()

// Add translation methods to Vue prototype
Vue.mixin({
	methods: {
		t: translate,
		n: translatePlural,
	},
})

new Vue({
	pinia,
	render: h => h(Settings),
}).$mount('#settings')
