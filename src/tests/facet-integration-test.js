/**
 * Facet Integration Test Examples
 *
 * This file contains examples and manual tests for the facet integration.
 * Use these in the browser console to test facet functionality.
 */

// Import object store
import { objectStore } from '../store/store.js'

// Test 1: Basic Facet Discovery
async function testFacetDiscovery() {
	console.info('Testing facet discovery...')

	try {
		// Get facetable fields for current context
		const facetableFields = await objectStore.getFacetableFields()
		console.info('Facetable fields:', facetableFields)

		// Check what metadata facets are available
		console.info('Metadata facets:', objectStore.availableMetadataFacets)

		// Check what object field facets are available
		console.info('Object field facets:', objectStore.availableObjectFieldFacets)

		return facetableFields
	} catch (error) {
		console.error('Facet discovery failed:', error)
	}
}

// Test 2: Basic Facet Retrieval
async function testBasicFacets() {
	console.info('Testing basic facet retrieval...')

	try {
		// Get facets with default configuration
		const facets = await objectStore.getFacets()
		console.info('Current facets:', facets)
		console.info('Store facet state:', objectStore.currentFacets)

		return facets
	} catch (error) {
		console.error('Facet retrieval failed:', error)
	}
}

// Test 3: Custom Facet Configuration
async function testCustomFacets() {
	console.info('Testing custom facet configuration...')

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
		console.info('Custom facets:', facets)

		return facets
	} catch (error) {
		console.error('Custom facet test failed:', error)
	}
}

// Test 4: Active Facet Management
async function testActiveFacets() {
	console.info('Testing active facet management...')

	try {
		// Enable a metadata facet
		await objectStore.updateActiveFacet('@self.register', 'terms', true)
		console.info('Enabled register facet')

		// Enable an object field facet (if available)
		const objectFields = Object.keys(objectStore.availableObjectFieldFacets)
		if (objectFields.length > 0) {
			const firstField = objectFields[0]
			const fieldConfig = objectStore.availableObjectFieldFacets[firstField]
			await objectStore.updateActiveFacet(firstField, fieldConfig.facet_types[0], true)
			console.info(`Enabled ${firstField} facet`)
		}

		console.info('Current active facets:', objectStore.activeFacets)
		console.info('Current facet results:', objectStore.currentFacets)

	} catch (error) {
		console.error('Active facet test failed:', error)
	}
}

// Test 5: Object List with Facets
async function testObjectListWithFacets() {
	console.info('Testing object list with facets...')

	try {
		// Refresh object list (includes facets by default)
		const result = await objectStore.refreshObjectList()

		console.info('Object list result:', result.data)
		console.info('Facets included:', Boolean(result.data.facets))
		console.info('Facetable fields included:', Boolean(result.data.facetable))

		return result
	} catch (error) {
		console.error('Object list with facets test failed:', error)
	}
}

// Test 6: Object List without Facets
async function testObjectListWithoutFacets() {
	console.info('Testing object list without facets...')

	try {
		// Refresh object list without facets for performance
		const result = await objectStore.refreshObjectList({ includeFacets: false })

		console.info('Object list result:', result.data)
		console.info('Facets included:', Boolean(result.data.facets))
		console.info('Facetable fields included:', Boolean(result.data.facetable))

		return result
	} catch (error) {
		console.error('Object list without facets test failed:', error)
	}
}

// Test 7: Complete Workflow Test
async function testCompleteWorkflow() {
	console.info('Testing complete facet workflow...')

	try {
		// Step 1: Discover facetable fields
		console.info('1. Discovering facetable fields...')
		await testFacetDiscovery()

		// Step 2: Get initial object list with facets
		console.info('2. Getting object list with facets...')
		await testObjectListWithFacets()

		// Step 3: Enable some facets
		console.info('3. Enabling active facets...')
		await testActiveFacets()

		// Step 4: Test custom configuration
		console.info('4. Testing custom facet configuration...')
		await testCustomFacets()

		console.info('Complete workflow test finished successfully!')

	} catch (error) {
		console.error('Complete workflow test failed:', error)
	}
}

// Helper function to check store state
function checkStoreState() {
	console.info('=== Object Store Facet State ===')
	console.info('Loading:', objectStore.facetsLoading)
	console.info('Has facets:', objectStore.hasFacets)
	console.info('Has facetable fields:', objectStore.hasFacetableFields)
	console.info('Available metadata facets:', Object.keys(objectStore.availableMetadataFacets))
	console.info('Available object field facets:', Object.keys(objectStore.availableObjectFieldFacets))
	console.info('Current facets:', Object.keys(objectStore.currentFacets))
	console.info('Active facets:', objectStore.activeFacets)
	console.info('================================')
}

/**
 * Test the exact URL that was reported as not working
 * This function tests the URL mentioned by the user to verify facets are now returned
 */
async function testExactUserURL() {
	console.info('=== Testing Exact User URL ===')

	// This is the exact URL the user reported as not working
	const testUrl = '/index.php/apps/openregister/api/objects/4/22?_limit=20&_page=1&_facetable=true&_facets[@self][register][type]=terms&_facets[@self][schema][type]=terms&_facets[@self][created][type]=date_histogram&_facets[@self][created][interval]=month'

	try {
		console.info('Fetching:', testUrl)
		const response = await fetch(testUrl)

		if (!response.ok) {
			console.error('HTTP Error:', response.status, response.statusText)
			const errorText = await response.text()
			console.error('Error response:', errorText)
			return
		}

		const data = await response.json()

		console.info('Response keys:', Object.keys(data))
		console.info('Has facets:', !!data.facets)
		console.info('Has facetable:', !!data.facetable)
		console.info('Results count:', data.results?.length || 0)
		console.info('Total:', data.total)

		if (data.facets) {
			console.info('Facet keys:', Object.keys(data.facets))
			if (data.facets['@self']) {
				console.info('Metadata facet keys:', Object.keys(data.facets['@self']))
			}
		}

		if (data.facetable) {
			console.info('Facetable fields available:')
			console.info('- @self fields:', Object.keys(data.facetable['@self'] || {}))
			console.info('- object fields:', Object.keys(data.facetable.object_fields || {}))
		}

		// Check if we got the expected facet structure
		const expectedFacets = ['register', 'schema', 'created']
		let foundFacets = 0

		if (data.facets && data.facets['@self']) {
			expectedFacets.forEach(facet => {
				if (data.facets['@self'][facet]) {
					foundFacets++
					console.info(`✓ Found ${facet} facet`)
				} else {
					console.info(`✗ Missing ${facet} facet`)
				}
			})
		}

		if (foundFacets === expectedFacets.length) {
			console.info('🎉 SUCCESS: All expected facets found!')
		} else {
			console.info(`⚠️ PARTIAL: Found ${foundFacets}/${expectedFacets.length} expected facets`)
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
	console.info('=== Testing buildSearchQuery Logic ===')

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

	console.info('Built query:', JSON.stringify(query, null, 2))

	// Check if facet configuration is preserved
	if (query._facets && query._facets['@self']) {
		console.info('✓ Facet configuration preserved')
		console.info('Facet fields:', Object.keys(query._facets['@self']))
	} else {
		console.info('✗ Facet configuration missing')
	}

	if (query._facetable === 'true') {
		console.info('✓ Facetable discovery enabled')
	} else {
		console.info('✗ Facetable discovery not enabled')
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

	console.info('Facet test functions available at window.facetTests')
	console.info('Available tests:')
	console.info('- window.facetTests.testFacetDiscovery()')
	console.info('- window.facetTests.testBasicFacets()')
	console.info('- window.facetTests.testCustomFacets()')
	console.info('- window.facetTests.testActiveFacets()')
	console.info('- window.facetTests.testObjectListWithFacets()')
	console.info('- window.facetTests.testObjectListWithoutFacets()')
	console.info('- window.facetTests.testCompleteWorkflow()')
	console.info('- window.facetTests.checkStoreState()')
	console.info('- window.facetTests.testExactUserURL()')
	console.info('- window.facetTests.testBuildSearchQuery()')
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
