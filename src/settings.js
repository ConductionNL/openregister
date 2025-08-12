import Vue from 'vue'
import AdminSettings from './views/settings/Settings.vue'

Vue.mixin({ methods: { t, n } })

new Vue(
	{
		render: h => h(AdminSettings),
	},
).$mount('#settings')
