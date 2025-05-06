import Vue from 'vue'
import { PiniaVuePlugin } from 'pinia'
import pinia from './pinia.js'
import App from './App.vue'
Vue.mixin({ methods: { t, n } })

Vue.use(PiniaVuePlugin)

new Vue(
	{
		pinia,
		render: h => h(App),
	},
).$mount('#content')
