<?php

/**
 * ObjectRelationsRequest parameter parsing tests.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\Support\ObjectRelationsRequest;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Depth is at least one, language defaults to nl, blank strings read as absent,
 * lists accept arrays or comma strings, and ids must be numeric.
 */
class ObjectRelationsRequestTest extends TestCase {

	/**
	 * A request wrapper over fixed parameters.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return ObjectRelationsRequest
	 */
	private function request(array $params): ObjectRelationsRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default=null): mixed => array_key_exists($key, $params) ? $params[$key] : $default
		);
		return new ObjectRelationsRequest($request);
	}//end request()

	/**
	 * depth() defaults to 1, reads numbers, floors at 1 and ignores non-numbers.
	 *
	 * @return void
	 */
	public function testDepthIsAtLeastOne(): void {
		$this->assertSame(1, $this->request([])->depth());
		$this->assertSame(3, $this->request(['depth' => '3'])->depth());
		$this->assertSame(1, $this->request(['depth' => '0'])->depth());
		$this->assertSame(1, $this->request(['depth' => '-4'])->depth());
		$this->assertSame(1, $this->request(['depth' => 'deep'])->depth());
	}//end testDepthIsAtLeastOne()

	/**
	 * optionalDepth() is null unless a depth was actually sent.
	 *
	 * @return void
	 */
	public function testOptionalDepthOnlyWhenSent(): void {
		$this->assertNull($this->request([])->optionalDepth());
		$this->assertNull($this->request(['depth' => '  '])->optionalDepth());
		$this->assertSame(2, $this->request(['depth' => '2'])->optionalDepth());
	}//end testOptionalDepthOnlyWhenSent()

	/**
	 * language() trims an explicit language and defaults to nl.
	 *
	 * @return void
	 */
	public function testLanguageDefaultsToDutch(): void {
		$this->assertSame('nl', $this->request([])->language());
		$this->assertSame('nl', $this->request(['language' => '   '])->language());
		$this->assertSame('en', $this->request(['language' => ' en '])->language());
	}//end testLanguageDefaultsToDutch()

	/**
	 * stringParam() trims, and treats missing, blank and non-string values as absent.
	 *
	 * @return void
	 */
	public function testStringParamTreatsBlankAsAbsent(): void {
		$this->assertSame('zaak', $this->request(['type' => ' zaak '])->stringParam('type'));
		$this->assertNull($this->request(['type' => ''])->stringParam('type'));
		$this->assertNull($this->request(['type' => ['zaak']])->stringParam('type'));
		$this->assertNull($this->request([])->stringParam('type'));
	}//end testStringParamTreatsBlankAsAbsent()

	/**
	 * csvParam() splits on commas, trims and drops empties; absent is an empty list.
	 *
	 * @return void
	 */
	public function testCsvParamSplitsAndTrims(): void {
		$this->assertSame(['a', 'b'], $this->request(['fields' => ' a, ,b ,'])->csvParam('fields'));
		$this->assertSame([], $this->request([])->csvParam('fields'));
	}//end testCsvParamSplitsAndTrims()

	/**
	 * listParam() is null when absent, and accepts an array or a comma string.
	 *
	 * @return void
	 */
	public function testListParamAcceptsArraysAndStrings(): void {
		$this->assertNull($this->request([])->listParam('schemas'));
		$this->assertSame(['1', '2'], $this->request(['schemas' => '1, 2,'])->listParam('schemas'));
		$this->assertSame(['3', '4'], $this->request(['schemas' => [' 3', 4, '']])->listParam('schemas'));
		$this->assertSame([], $this->request(['schemas' => ''])->listParam('schemas'));
	}//end testListParamAcceptsArraysAndStrings()

	/**
	 * idOf() reads numeric references and refuses null or slugs.
	 *
	 * @return void
	 */
	public function testIdOfOnlyReadsNumbers(): void {
		$wrapper = $this->request([]);

		$this->assertSame(12, $wrapper->idOf('12'));
		$this->assertNull($wrapper->idOf('zaken'));
		$this->assertNull($wrapper->idOf(null));
	}//end testIdOfOnlyReadsNumbers()
}//end class
