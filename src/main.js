import Vue from 'vue'
import { PiniaVuePlugin } from 'pinia'
import pinia from './pinia.js'
import App from './App.vue'
// import { setupDashboardStoreWatchers } from './store/modules/dashboard.js' // No longer needed here
Vue.mixin({ methods: { t, n } })

Vue.use(PiniaVuePlugin)

// Set up dashboard store watchers to keep dashboard data in sync
// setupDashboardStoreWatchers() // Moved to App.vue

new Vue(
	{
		pinia,
		render: h => h(App),
	},
).$mount('#content')
