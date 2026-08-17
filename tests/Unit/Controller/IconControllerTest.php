<?php

/**
 * Contract tests for IconController.
 *
 * Covers the single public endpoint GET /api/icon/mdi/{name} → mdi().
 *
 * The endpoint is `#[PublicPage]`, so its contract is a wire contract: it must
 * answer either a cacheable `image/svg+xml` document for a curated glyph, or a
 * 404 for anything else. It must never echo the caller-supplied name back into
 * the document (the name reaches the response only as a lookup key).
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/unified-search-index/specs/unified-search-provider/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Controller\IconController;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class IconControllerTest extends TestCase {
	/**
	 * The controller under test.
	 *
	 * @var IconController
	 */
	private IconController $controller;

	/**
	 * The mocked HTTP request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);

		$this->controller = new IconController(
			'openregister',
			$this->request
		);
	}

	public function testMdiServesCuratedGlyphAsSvg(): void {
		$result = $this->controller->mdi('dog');

		$this->assertInstanceOf(DataDisplayResponse::class, $result);
		$this->assertSame(200, $result->getStatus());
		$this->assertSame('image/svg+xml', $result->getHeaders()['Content-Type']);
		$this->assertStringStartsWith('<svg xmlns="http://www.w3.org/2000/svg"', $result->getData());
		$this->assertStringContainsString('viewBox="0 0 24 24"', $result->getData());
		$this->assertStringContainsString('<path fill=', $result->getData());
	}

	/**
	 * Glyph geometry is immutable per name, so the response must be publicly
	 * cacheable and immutable — otherwise the icon is refetched per page.
	 *
	 * @return void
	 */
	public function testMdiMarksTheGlyphImmutablyCacheable(): void {
		$result = $this->controller->mdi('dog');

		$cacheControl = $result->getHeaders()['Cache-Control'];
		$this->assertStringContainsString('max-age=86400', $cacheControl);
		$this->assertStringContainsString('immutable', $cacheControl);
	}

	/**
	 * "Dog", "mdi-dog" and "mdiDog" are the three shapes a schema icon field
	 * can hold; all three must resolve to the SAME document, or a deep link
	 * built from a stored icon reference 404s depending on how it was typed.
	 *
	 * @return void
	 */
	public function testMdiNormalisesTheIconReference(): void {
		$plain = $this->controller->mdi('dog');
		$prefixed = $this->controller->mdi('mdi-dog');
		$camel = $this->controller->mdi('mdiDog');
		$capital = $this->controller->mdi('Dog');

		$this->assertSame(200, $prefixed->getStatus());
		$this->assertSame($plain->getData(), $prefixed->getData());
		$this->assertSame($plain->getData(), $camel->getData());
		$this->assertSame($plain->getData(), $capital->getData());
	}

	/**
	 * An unknown name must 404 with an EMPTY body so the caller falls back to
	 * its own icon — and so the public endpoint cannot be turned into a
	 * reflector for attacker-supplied markup.
	 *
	 * @return void
	 */
	public function testMdiReturnsEmpty404ForAnUnknownIcon(): void {
		$result = $this->controller->mdi('definitely-not-a-curated-glyph');

		$this->assertInstanceOf(DataDisplayResponse::class, $result);
		$this->assertSame(404, $result->getStatus());
		$this->assertSame('', $result->getData());
		$this->assertStringNotContainsString('definitely-not-a-curated-glyph', $result->getData());
	}

	public function testMdiReturns404ForAnEmptyName(): void {
		$result = $this->controller->mdi('');

		$this->assertSame(404, $result->getStatus());
		$this->assertSame('', $result->getData());
	}

	/**
	 * `<script>` is not a curated key, so it must take the 404 arm rather than
	 * reaching the SVG body — an SVG document is a script execution context.
	 *
	 * @return void
	 */
	public function testMdiDoesNotReflectMarkupIntoTheSvgDocument(): void {
		$result = $this->controller->mdi('<script>alert(1)</script>');

		$this->assertSame(404, $result->getStatus());
		$this->assertStringNotContainsString('script', $result->getData());
	}
}
