<?php

/**
 * An image-url value is written in its object form.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use OCA\OpenRegister\Service\Object\SaveObject\ImageObjectForm;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\ImageObjectForm
 */
class ImageObjectFormTest extends TestCase {

	public function testABareUrlBecomesTheObjectFormAndEverythingElseStays(): void {
		$data = [
			'cover' => 'https://example.org/img.png',
			'banner' => ['url' => 'https://example.org/b.png', 'altText' => 'A banner'],
			'title' => 'https://example.org/not-an-image-property',
			'empty' => null,
		];
		$properties = [
			'cover' => ['type' => 'image-url'],
			'banner' => ['type' => 'image-url'],
			'title' => ['type' => 'string'],
			'empty' => ['type' => 'image-url'],
			'absent' => ['type' => 'image-url'],
			'odd' => 'not a declaration',
		];

		$this->assertSame(
			[
				'cover' => ['url' => 'https://example.org/img.png'],
				'banner' => ['url' => 'https://example.org/b.png', 'altText' => 'A banner'],
				'title' => 'https://example.org/not-an-image-property',
				'empty' => null,
			],
			(new ImageObjectForm())->apply($data, $properties)
		);
	}//end testABareUrlBecomesTheObjectFormAndEverythingElseStays()
}//end class
