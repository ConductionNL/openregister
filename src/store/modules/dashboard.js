import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export const useDashboardStore = defineStore('dashboard', {
	state: () => ({
		registers: [],
		loading: false,
		error: null,
		isInitialized: false,
	}),

	getters: {
		getRegisters: (state) => state.registers,
		isLoading: (state) => state.loading,
		getError: (state) => state.error,
		getSystemTotals: (state) => state.registers.find(register => register.title === 'System Totals'),
		getOrphanedItems: (state) => state.registers.find(register => register.title === 'Orphaned Items'),
	},

	actions: {
		async preload() {
			if (!this.isInitialized && !this.loading) {
				await this.fetchRegisters()
				this.isInitialized = true
			}
			return this.registers
		},

		async fetchRegisters() {
			// If already loading, return existing promise
			if (this.loading) {
				return this.registers
			}

			try {
				this.loading = true
				this.error = null
				const response = await axios.get(generateUrl('/apps/openregister/api/dashboard'))
				this.registers = response.data.registers
				return this.registers
			} catch (error) {
				console.error('Error fetching registers:', error)
				this.error = error.message || 'Failed to fetch registers'
				throw error
			} finally {
				this.loading = false
			}
		},

		async calculateSizes(registerId) {
			try {
				await axios.post(generateUrl(`/apps/openregister/api/dashboard/calculate/${registerId}`))
				// Refresh the registers after calculation
				await this.fetchRegisters()
				return true
			} catch (error) {
				console.error('Error calculating sizes:', error)
				throw error
			}
		},

		reset() {
			this.registers = []
			this.loading = false
			this.error = null
			this.isInitialized = false
		},
	},
})
