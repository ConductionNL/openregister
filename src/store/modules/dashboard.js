import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export const useDashboardStore = defineStore('dashboard', {
	state: () => ({
		registers: [],
		loading: false,
		error: null,
	}),

	getters: {
		getRegisters: (state) => state.registers,
		isLoading: (state) => state.loading,
		getError: (state) => state.error,
	},

	actions: {
		async fetchRegisters() {
			try {
				this.loading = true
				this.error = null
				const response = await axios.get(generateUrl('/apps/openregister/api/dashboard'))
				this.registers = response.data.registers
			} catch (error) {
				console.error('Error fetching registers:', error)
				this.error = error.message || 'Failed to fetch registers'
			} finally {
				this.loading = false
			}
		},

		async calculateSizes(registerId) {
			try {
				await axios.post(generateUrl(`/apps/openregister/api/dashboard/calculate/${registerId}`))
				return true
			} catch (error) {
				console.error('Error calculating sizes:', error)
				throw error
			}
		},
	},
})
