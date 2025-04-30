import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export const useDashboardStore = defineStore('dashboard', {
	state: () => ({
		registers: [],
		loading: false,
		error: null,
		isInitialized: false,
		selectedRegisterId: null,
		selectedSchemaId: null,
		dateRange: {
			from: null,
			till: null,
		},
		chartData: {
			auditTrailActions: null,
			objectsByRegister: null,
			objectsBySchema: null,
			objectsBySize: null,
		},
		chartLoading: {
			auditTrailActions: false,
			objectsByRegister: false,
			objectsBySchema: false,
			objectsBySize: false,
		},
	}),

	getters: {
		getRegisters: (state) => state.registers,
		isLoading: (state) => state.loading,
		getError: (state) => state.error,
		getSystemTotals: (state) => state.registers.find(register => register.title === 'System Totals'),
		getOrphanedItems: (state) => state.registers.find(register => register.title === 'Orphaned Items'),
		getSelectedRegisterId: (state) => state.selectedRegisterId,
		getSelectedSchemaId: (state) => state.selectedSchemaId,
		getDateRange: (state) => state.dateRange,
		getChartData: (state) => state.chartData,
		isChartLoading: (state) => state.chartLoading,
	},

	actions: {
		setSelectedRegisterId(registerId) {
			this.selectedRegisterId = registerId
			// Refresh chart data when selection changes
			this.fetchAllChartData()
		},

		setSelectedSchemaId(schemaId) {
			this.selectedSchemaId = schemaId
			// Refresh chart data when selection changes
			this.fetchAllChartData()
		},

		setDateRange(from, till) {
			this.dateRange = { from, till }
			// Refresh audit trail chart when date range changes
			this.fetchAuditTrailActionChart()
		},

		async fetchAuditTrailActionChart() {
			if (this.chartLoading.auditTrailActions) return

			try {
				this.chartLoading.auditTrailActions = true
				const response = await axios.get(generateUrl('/apps/openregister/api/dashboard/charts/audit-trail-actions'), {
					params: {
						from: this.dateRange.from,
						till: this.dateRange.till,
						registerId: this.selectedRegisterId,
						schemaId: this.selectedSchemaId,
					},
				})
				this.chartData.auditTrailActions = response.data
			} catch (error) {
				console.error('Error fetching audit trail action chart:', error)
				throw error
			} finally {
				this.chartLoading.auditTrailActions = false
			}
		},

		async fetchObjectsByRegisterChart() {
			if (this.chartLoading.objectsByRegister) return

			try {
				this.chartLoading.objectsByRegister = true
				const response = await axios.get(generateUrl('/apps/openregister/api/dashboard/charts/objects-by-register'), {
					params: {
						registerId: this.selectedRegisterId,
						schemaId: this.selectedSchemaId,
					},
				})
				this.chartData.objectsByRegister = response.data
			} catch (error) {
				console.error('Error fetching objects by register chart:', error)
				throw error
			} finally {
				this.chartLoading.objectsByRegister = false
			}
		},

		async fetchObjectsBySchemaChart() {
			if (this.chartLoading.objectsBySchema) return

			try {
				this.chartLoading.objectsBySchema = true
				const response = await axios.get(generateUrl('/apps/openregister/api/dashboard/charts/objects-by-schema'), {
					params: {
						registerId: this.selectedRegisterId,
						schemaId: this.selectedSchemaId,
					},
				})
				this.chartData.objectsBySchema = response.data
			} catch (error) {
				console.error('Error fetching objects by schema chart:', error)
				throw error
			} finally {
				this.chartLoading.objectsBySchema = false
			}
		},

		async fetchObjectsBySizeChart() {
			if (this.chartLoading.objectsBySize) return

			try {
				this.chartLoading.objectsBySize = true
				const response = await axios.get(generateUrl('/apps/openregister/api/dashboard/charts/objects-by-size'), {
					params: {
						registerId: this.selectedRegisterId,
						schemaId: this.selectedSchemaId,
					},
				})
				this.chartData.objectsBySize = response.data
			} catch (error) {
				console.error('Error fetching objects by size chart:', error)
				throw error
			} finally {
				this.chartLoading.objectsBySize = false
			}
		},

		async fetchAllChartData() {
			await Promise.all([
				this.fetchAuditTrailActionChart(),
				this.fetchObjectsByRegisterChart(),
				this.fetchObjectsBySchemaChart(),
				this.fetchObjectsBySizeChart(),
			])
		},

		async preload() {
			if (!this.isInitialized && !this.loading) {
				await this.fetchRegisters()
				await this.fetchAllChartData()
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
			this.selectedRegisterId = null
			this.selectedSchemaId = null
			this.dateRange = { from: null, till: null }
			this.chartData = {
				auditTrailActions: null,
				objectsByRegister: null,
				objectsBySchema: null,
				objectsBySize: null,
			}
			this.chartLoading = {
				auditTrailActions: false,
				objectsByRegister: false,
				objectsBySchema: false,
				objectsBySize: false,
			}
		},
	},
})
