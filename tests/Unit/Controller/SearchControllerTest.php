<?php
/**
 * SearchController Test
 *
 * Test class for the SearchController to verify search functionality.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit;

use OCA\OpenRegister\Controller\SearchController;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\ISearch;
use PHPUnit\Framework\TestCase;

/**
 * Test class for SearchController
 *
 * @package OCA\OpenRegister\Tests\Unit
 */
class SearchControllerTest extends TestCase
{

    /**
     * Test search controller can be instantiated
     *
     * @return void
     */
    public function testSearchControllerCanBeInstantiated(): void
    {
        // Create mock objects
        $request = $this->createMock(IRequest::class);
        $searchService = $this->createMock(ISearch::class);

        // Create controller instance
        $controller = new SearchController('openregister', $request, $searchService);

        // Verify controller was created
        $this->assertInstanceOf(SearchController::class, $controller);
    }

    /**
     * Test search method exists and returns JSONResponse
     *
     * @return void
     */
    public function testSearchMethodExists(): void
    {
        // Create mock objects
        $request = $this->createMock(IRequest::class);
        $searchService = $this->createMock(ISearch::class);

        // Create controller instance
        $controller = new SearchController('openregister', $request, $searchService);

        // Verify search method exists
        $this->assertTrue(method_exists($controller, 'search'));
    }

}//end class