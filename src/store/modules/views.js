/* eslint-disable no-console */
import { createCrudStore, buildHeaders } from '@conduction/nextcloud-vue'

export const useViewsStore = createCrudStore('views', {
	endpoint: 'views',
	features: { loading: true },
	extend: {
		state: () => ({
			activeView: null,
		}),
		getters: {
			getAllViews: (state) => state.list,
			getPublicViews: (state) => state.list.filter(view => view.isPublic === true),
			getPrivateViews: (state) => state.list.filter(view => view.isPublic !== true),
			getDefaultView: (state) => state.list.find(view => view.isDefault === true) || null,
			getActiveView: (state) => state.activeView,
		},
		actions: {
			setActiveView(view) {
				this.activeView = view
				console.info('Active view set:', view)
			},
			clearActiveView() {
				this.activeView = null
				console.info('Active view cleared')
			},
			// Override getOne — API wraps single items in { view: {...} }
			async getOne(id) {
				this.loading = true
				this.error = null
				try {
					const response = await fetch(`${this._options.baseApiUrl}/${id}`, {
						method: 'GET',
						headers: buildHeaders(),
					})
					if (!response.ok) {
						throw new Error(`HTTP error! status: ${response.status}`)
					}
					const data = await response.json()
					const view = data.view || data
					return view
				} catch (error) {
					this.error = error.message
					throw error
				} finally {
					this.loading = false
				}
			},
			// Override save — API wraps responses in { view: {...} }
			async save(viewItem) {
				this.loading = true
				this.error = null
				const isNew = !viewItem.id && !viewItem.uuid
				const url = isNew
					? this._options.baseApiUrl
					: `${this._options.baseApiUrl}/${viewItem.id || viewItem.uuid}`
				const method = isNew ? 'POST' : 'PUT'
				const body = this.cleanForSave(viewItem)
				try {
					const response = await fetch(url, {
						method,
						headers: buildHeaders(),
						body: JSON.stringify(body),
					})
					if (!response.ok) {
						throw new Error(`HTTP error! status: ${response.status}`)
					}
					const data = await response.json()
					const view = data.view || data
					// Update local list
					if (isNew) {
						this.list.push(view)
					} else {
						const index = this.list.findIndex(v => v.id === (viewItem.id || viewItem.uuid) || v.uuid === (viewItem.id || viewItem.uuid))
						if (index !== -1) {
							this.list[index] = view
						}
					}
					return view
				} catch (error) {
					this.error = error.message
					throw error
				} finally {
					this.loading = false
				}
			},
			// Override deleteOne — accepts id string, removes from list locally
			async deleteOne(id) {
				this.loading = true
				this.error = null
				try {
					const itemId = typeof id === 'object' ? (id.id || id.uuid) : id
					const response = await fetch(`${this._options.baseApiUrl}/${itemId}`, {
						method: 'DELETE',
						headers: buildHeaders(),
					})
					if (!response.ok) {
						throw new Error(`HTTP error! status: ${response.status}`)
					}
					this.list = this.list.filter(v => v.id !== itemId && v.uuid !== itemId)
					if (this.activeView && (this.activeView.id === itemId || this.activeView.uuid === itemId)) {
						this.activeView = null
					}
				} catch (error) {
					this.error = error.message
					throw error
				} finally {
					this.loading = false
				}
			},
			applyView(view, searchStore) {
				if (!view || !view.configuration) {
					console.warn('Invalid view provided to applyView')
					return
				}
				const config = view.configuration
				if (config.registers) searchStore.setSelectedRegisters(config.registers)
				if (config.schemas) searchStore.setSelectedSchemas(config.schemas)
				if (config.source) searchStore.setSource(config.source)
				if (config.searchTerms) searchStore.setSearchTerms(config.searchTerms)
				if (config.facetFilters) searchStore.setFacetFilters(config.facetFilters)
				if (config.enabledFacets) searchStore.setEnabledFacets(config.enabledFacets)
				if (config.advancedFilters) searchStore.setAdvancedFilters(config.advancedFilters)
				if (config.pagination) searchStore.setPagination(config.pagination)
				if (config.sorting) searchStore.setSorting(config.sorting)
				if (config.columns) searchStore.setColumns(config.columns)
				this.setActiveView(view)
				console.info('View applied successfully:', view.name)
			},
			createViewFromSearchState(searchStore, name, description = '', isDefault = false, isPublic = false) {
				return {
					name,
					description,
					isDefault,
					isPublic,
					configuration: {
						registers: searchStore.selectedRegisters || [],
						schemas: searchStore.selectedSchemas || [],
						source: searchStore.source || 'auto',
						searchTerms: searchStore.searchTerms || [],
						facetFilters: searchStore.facetFilters || {},
						enabledFacets: searchStore.enabledFacets || {},
						advancedFilters: searchStore.advancedFilters || {},
						pagination: searchStore.pagination || { page: 1, limit: 20 },
						sorting: searchStore.sorting || {},
						columns: searchStore.columns || {},
					},
				}
			},
		},
	},
})
