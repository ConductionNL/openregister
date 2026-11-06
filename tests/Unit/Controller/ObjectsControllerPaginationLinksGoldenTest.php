<?php

/**
 * Golden record of the next/prev links ObjectsController::paginate() builds.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Controller\Support\ResultWindow;
use OCP\AppFramework\Controller;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * @covers \OCA\OpenRegister\Controller\ObjectsController
 * @uses \OCA\OpenRegister\Controller\Support\ResultWindow
 * @uses \OCA\OpenRegister\Service\Object\PaginationLinks
 */
class ObjectsControllerPaginationLinksGoldenTest extends TestCase {

	private const FIXTURE = __DIR__.'/fixtures/objects-pagination-links-golden.json';

	/**
	 * URIs with a query string, crossed with page positions.
	 *
	 * @return array<string, array{0: string, 1: int, 2: int}>
	 */
	public static function cases(): array {
		$uris = [
			'/api/objects/1/2?_limit=2',
			'/api/objects/1/2?page=3',
			'/api/objects/1/2?_limit=2&page=3',
			'/api/objects/1/2?search=page%3D1',
		];
		$out = [];
		foreach ($uris as $uri) {
			foreach ([[1, 3], [2, 3], [3, 3], [4, 3], [1, 1], [2, 1]] as [$page, $pages]) {
				$out[$uri.' page '.$page.'/'.$pages] = [$uri, $page, $pages];
			}
		}

		return $out;
	}//end cases()

	/**
	 * A URI whose page parameter the links have to rewrite or leave alone.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function rewrites(): array {
		return [
			'_page' => ['/api?_page=2', '/api?_page=3', '/api?_page=1'],
			'_page after another parameter' => ['/api?_limit=2&_page=2', '/api?_limit=2&_page=3', '/api?_limit=2&_page=1'],
			'_page before another parameter' => ['/api?_page=2&_limit=2', '/api?_page=3&_limit=2', '/api?_page=1&_limit=2'],
			'both spellings' => ['/api?_page=2&page=2', '/api?_page=3&page=3', '/api?_page=1&page=1'],
			'a page with no number' => ['/api?page=&_limit=2', '/api?page=3&_limit=2', '/api?page=1&_limit=2'],
			'a parameter ending in page' => ['/api?xpage=4', '/api?xpage=4&page=3', '/api?xpage=4&page=1'],
		];
	}//end rewrites()

	/**
	 * `next` and `prev` move off the current page whatever spells the parameter.
	 *
	 * @param string $uri  The request URI, read at page 2 of 3.
	 * @param string $next The expected next link.
	 * @param string $prev The expected previous link.
	 *
	 * @return void
	 */
	#[DataProvider('rewrites')]
	public function testLinksRewriteThePageParameter(string $uri, string $next, string $prev): void {
		$this->assertSame(['next' => $next, 'prev' => $prev], $this->links(uri: $uri, page: 2, pages: 3));
	}//end testLinksRewriteThePageParameter()

	/**
	 * The links paginate() answers for a request URI and a page position.
	 *
	 * @param string $uri   The request URI.
	 * @param int    $page  The page.
	 * @param int    $pages The number of pages.
	 *
	 * @return array{next: string|null, prev: string|null}
	 */
	private function links(string $uri, int $page, int $pages): array {
		$request = $this->createMock(IRequest::class);
		$request->method('getRequestUri')->willReturn($uri);
		$controller = (new ReflectionClass(ObjectsController::class))->newInstanceWithoutConstructor();
		(new ReflectionProperty(Controller::class, 'request'))->setValue($controller, $request);

		$result = (new ReflectionMethod(ObjectsController::class, 'paginate'))
			->invoke($controller, [], new ResultWindow(($pages * 10), 10, 0, $page));

		return ['next' => ($result['next'] ?? null), 'prev' => ($result['prev'] ?? null)];
	}//end links()

	/**
	 * Every URI with a query string gets the same links as before.
	 *
	 * @param string $uri   The request URI.
	 * @param int    $page  The page.
	 * @param int    $pages The number of pages.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testLinksMatchGolden(string $uri, int $page, int $pages): void {
		$actual = $this->links(uri: $uri, page: $page, pages: $pages);
		$golden = [];
		if (is_file(self::FIXTURE) === true) {
			$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		$name = $this->dataName();
		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertArrayHasKey($name, $golden, 'No golden recording for this case.');
		$this->assertSame($golden[$name], $actual);
	}//end testLinksMatchGolden()

	/**
	 * A URI with no query string gets `?page=N`, not `&page=N?page=N`.
	 *
	 * @return void
	 */
	public function testAUriWithoutAQueryStringGetsAQuestionMark(): void {
		$this->assertSame(
			['next' => '/api/objects/1/2?page=3', 'prev' => '/api/objects/1/2?page=1'],
			$this->links(uri: '/api/objects/1/2', page: 2, pages: 3)
		);
	}//end testAUriWithoutAQueryStringGetsAQuestionMark()
}//end class
