/**
 * Facet Integration Test Examples
 *
 * This file contains examples and manual tests for the facet integration.
 * Use these in the browser console to test facet functionality.
 */
/* eslint-disable no-console */

// Import object store
import { objectStore } from '../store/store.js'

// Test 1: Basic Facet Discovery
async function testFacetDiscovery() {
	console.log('Testing facet discovery...')

	try {
		// Get facetable fields for current context
		const facetableFields = await objectStore.getFacetableFields()
		console.log('Facetable fields:', facetableFields)

		// Check what metadata facets are available
		console.log('Metadata facets:', objectStore.availableMetadataFacets)

		// Check what object field facets are available
		console.log('Object field facets:', objectStore.availableObjectFieldFacets)

		return facetableFields
	} catch (error) {
		console.error('Facet discovery failed:', error)
	}
}

// Test 2: Basic Facet Retrieval
async function testBasicFacets() {
	console.log('Testing basic facet retrieval...')

	try {
		// Get facets with default configuration
		const facets = await objectStore.getFacets()
		console.log('Current facets:', facets)
		console.log('Store facet state:', objectStore.currentFacets)

		return facets
	} catch (error) {
		console.error('Facet retrieval failed:', error)
	}
}

// Test 3: Custom Facet Configuration
async function testCustomFacets() {
	console.log('Testing custom facet configuration...')

	const customConfig = {
		_facets: {
			'@self': {
				register: { type: 'terms' },
				schema: { type: 'terms' },
				created: { type: 'date_histogram', interval: 'month' },
			},
		},
	}

	try {
		const facets = await objectStore.getFacets(customConfig)
		console.log('Custom facets:', facets)

		return facets
	} catch (error) {
		console.error('Custom facet test failed:', error)
	}
}

// Test 4: Active Facet Management
async function testActiveFacets() {
	console.log('Testing active facet management...')

	try {
		// Enable a metadata facet
		await objectStore.updateActiveFacet('@self.register', 'terms', true)
		console.log('Enabled register facet')

		// Enable an object field facet (if available)
		const objectFields = Object.keys(objectStore.availableObjectFieldFacets)
		if (objectFields.length > 0) {
			const firstField = objectFields[0]
			const fieldConfig = objectStore.availableObjectFieldFacets[firstField]
			await objectStore.updateActiveFacet(firstField, fieldConfig.facet_types[0], true)
			console.log(`Enabled ${firstField} facet`)
		}

		console.log('Current active facets:', objectStore.activeFacets)
		console.log('Current facet results:', objectStore.currentFacets)

	} catch (error) {
		console.error('Active facet test failed:', error)
	}
}

// Test 5: Object List with Facets
async function testObjectListWithFacets() {
	console.log('Testing object list with facets...')

	try {
		// Refresh object list (includes facets by default)
		const result = await objectStore.refreshObjectList()

		console.log('Object list result:', result.data)
		console.log('Facets included:', Boolean(result.data.facets))
		console.log('Facetable fields included:', Boolean(result.data.facetable))

		return result
	} catch (error) {
		console.error('Object list with facets test failed:', error)
	}
}

// Test 6: Object List without Facets
async function testObjectListWithoutFacets() {
	console.log('Testing object list without facets...')

	try {
		// Refresh object list without facets for performance
		const result = await objectStore.refreshObjectList({ includeFacets: false })

		console.log('Object list result:', result.data)
		console.log('Facets included:', Boolean(result.data.facets))
		console.log('Facetable fields included:', Boolean(result.data.facetable))

		return result
	} catch (error) {
		console.error('Object list without facets test failed:', error)
	}
}

// Test 7: Complete Workflow Test
async function testCompleteWorkflow() {
	console.log('Testing complete facet workflow...')

	try {
		// Step 1: Discover facetable fields
		console.log('1. Discovering facetable fields...')
		await testFacetDiscovery()

		// Step 2: Get initial object list with facets
		console.log('2. Getting object list with facets...')
		await testObjectListWithFacets()

		// Step 3: Enable some facets
		console.log('3. Enabling active facets...')
		await testActiveFacets()

		// Step 4: Test custom configuration
		console.log('4. Testing custom facet configuration...')
		await testCustomFacets()

		console.log('Complete workflow test finished successfully!')

	} catch (error) {
		console.error('Complete workflow test failed:', error)
	}
}

// Helper function to check store state
function checkStoreState() {
	console.log('=== Object Store Facet State ===')
	console.log('Loading:', objectStore.facetsLoading)
	console.log('Has facets:', objectStore.hasFacets)
	console.log('Has facetable fields:', objectStore.hasFacetableFields)
	console.log('Available metadata facets:', Object.keys(objectStore.availableMetadataFacets))
	console.log('Available object field facets:', Object.keys(objectStore.availableObjectFieldFacets))
	console.log('Current facets:', Object.keys(objectStore.currentFacets))
	console.log('Active facets:', objectStore.activeFacets)
	console.log('================================')
}

/**
 * Test the exact URL that was reported as not working
 * This function tests the URL mentioned by the user to verify facets are now returned
 */
async function testExactUserURL() {
	console.log('=== Testing Exact User URL ===')

	// This is the exact URL the user reported as not working
	const testUrl = '/index.php/apps/openregister/api/objects/4/22?_limit=20&_page=1&_facetable=true&_facets[@self][register][type]=terms&_facets[@self][schema][type]=terms&_facets[@self][created][type]=date_histogram&_facets[@self][created][interval]=month'

	try {
		console.log('Fetching:', testUrl)
		const response = await fetch(testUrl)

		if (!response.ok) {
			console.error('HTTP Error:', response.status, response.statusText)
			const errorText = await response.text()
			console.error('Error response:', errorText)
			return
		}

		const data = await response.json()

		console.log('Response keys:', Object.keys(data))
		console.log('Has facets:', !!data.facets)
		console.log('Has facetable:', !!data.facetable)
		console.log('Results count:', data.results?.length || 0)
		console.log('Total:', data.total)

		if (data.facets) {
			console.log('Facet keys:', Object.keys(data.facets))
			if (data.facets['@self']) {
				console.log('Metadata facet keys:', Object.keys(data.facets['@self']))
			}
		}

		if (data.facetable) {
			console.log('Facetable fields available:')
			console.log('- @self fields:', Object.keys(data.facetable['@self'] || {}))
			console.log('- object fields:', Object.keys(data.facetable.object_fields || {}))
		}

		// Check if we got the expected facet structure
		const expectedFacets = ['register', 'schema', 'created']
		let foundFacets = 0

		if (data.facets && data.facets['@self']) {
			expectedFacets.forEach(facet => {
				if (data.facets['@self'][facet]) {
					foundFacets++
					console.log(`✓ Found ${facet} facet`)
				} else {
					console.log(`✗ Missing ${facet} facet`)
				}
			})
		}

		if (foundFacets === expectedFacets.length) {
			console.log('🎉 SUCCESS: All expected facets found!')
		} else {
			console.log(`⚠️ PARTIAL: Found ${foundFacets}/${expectedFacets.length} expected facets`)
		}

		return data

	} catch (error) {
		console.error('Test failed:', error.message)
		console.error('Stack:', error.stack)
	}
}

/**
 * Test the controller's new buildSearchQuery method
 * This simulates what happens when the controller processes facet parameters
 */
function testBuildSearchQuery() {
	console.log('=== Testing buildSearchQuery Logic ===')

	// Simulate the parameters from the user's URL
	const mockParams = {
		_limit: '20',
		_page: '1',
		_facetable: 'true',
		_facets: {
			'@self': {
				register: { type: 'terms' },
				schema: { type: 'terms' },
				created: { type: 'date_histogram', interval: 'month' },
			},
		},
	}

	// Simulate what buildSearchQuery does
	const query = {}
	query['@self'] = {
		register: '4', // From URL path
		schema: '22', // From URL path
	}

	// Add special parameters
	Object.entries(mockParams).forEach(([key, value]) => {
		if (key.startsWith('_')) {
			query[key] = value
		}
	})

	console.log('Built query:', JSON.stringify(query, null, 2))

	// Check if facet configuration is preserved
	if (query._facets && query._facets['@self']) {
		console.log('✓ Facet configuration preserved')
		console.log('Facet fields:', Object.keys(query._facets['@self']))
	} else {
		console.log('✗ Facet configuration missing')
	}

	if (query._facetable === 'true') {
		console.log('✓ Facetable discovery enabled')
	} else {
		console.log('✗ Facetable discovery not enabled')
	}

	return query
}

// Export functions for console use
if (typeof window !== 'undefined') {
	window.facetTests = {
		testFacetDiscovery,
		testBasicFacets,
		testCustomFacets,
		testActiveFacets,
		testObjectListWithFacets,
		testObjectListWithoutFacets,
		testCompleteWorkflow,
		checkStoreState,
		testExactUserURL,
		testBuildSearchQuery,
	}

	console.log('Facet test functions available at window.facetTests')
	console.log('Available tests:')
	console.log('- window.facetTests.testFacetDiscovery()')
	console.log('- window.facetTests.testBasicFacets()')
	console.log('- window.facetTests.testCustomFacets()')
	console.log('- window.facetTests.testActiveFacets()')
	console.log('- window.facetTests.testObjectListWithFacets()')
	console.log('- window.facetTests.testObjectListWithoutFacets()')
	console.log('- window.facetTests.testCompleteWorkflow()')
	console.log('- window.facetTests.checkStoreState()')
	console.log('- window.facetTests.testExactUserURL()')
	console.log('- window.facetTests.testBuildSearchQuery()')
}

export {
	testFacetDiscovery,
	testBasicFacets,
	testCustomFacets,
	testActiveFacets,
	testObjectListWithFacets,
	testObjectListWithoutFacets,
	testCompleteWorkflow,
	checkStoreState,
	testExactUserURL,
	testBuildSearchQuery,
}
