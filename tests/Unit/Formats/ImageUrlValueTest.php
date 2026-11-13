<?php

/**
 * An image-url value is a URL or an object with a URL, a positive size, alt text and a known format.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Formats;

use OCA\OpenRegister\Formats\ImageUrlValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Formats\ImageUrlValue
 */
class ImageUrlValueTest extends TestCase {

	public function testABareUrlIsNormalisedToTheObjectForm(): void {
		$this->assertSame(['url' => 'https://example.org/img.png'], ImageUrlValue::normalised('https://example.org/img.png'));
	}//end testABareUrlIsNormalisedToTheObjectForm()

	public function testTheObjectFormIsKeptAsItIs(): void {
		$image = ['url' => 'https://example.org/img.png', 'width' => 1200, 'height' => 630, 'altText' => 'Town hall at dusk', 'format' => 'png'];

		$this->assertSame($image, ImageUrlValue::normalised($image));
		$this->assertNull((new ImageUrlValue())->validate($image, 'cover'));
	}//end testTheObjectFormIsKeptAsItIs()

	public function testABareUrlIsValid(): void {
		$this->assertNull((new ImageUrlValue())->validate('https://example.org/img.png', 'cover'));
	}//end testABareUrlIsValid()

	/**
	 * Refused values and the exact message.
	 *
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public static function refusedImages(): array {
		return [
			'not a url' => ['not a url', "Property 'cover': 'not a url' is not a valid URL"],
			'object with a bad url' => [['url' => 'not a url'], "Property 'cover': 'not a url' is not a valid URL"],
			'unknown format' => [
				['url' => 'https://example.org/x.bmp', 'format' => 'bmp'],
				"Property 'cover': format 'bmp' is not one of jpeg|png|webp|avif|svg",
			],
			'format not a string' => [
				['url' => 'https://example.org/x.png', 'format' => 7],
				"Property 'cover': format '7' is not one of jpeg|png|webp|avif|svg",
			],
			'missing url' => [['altText' => 'A cat'], "Property 'cover': image-url is missing 'url'"],
			'url not a string' => [['url' => 42], "Property 'cover': 'url' must be a string"],
			'zero width' => [['url' => 'https://example.org/x.png', 'width' => 0], "Property 'cover': 'width' must be a positive integer"],
			'string height' => [['url' => 'https://example.org/x.png', 'height' => '630'], "Property 'cover': 'height' must be a positive integer"],
			'alt text not a string' => [['url' => 'https://example.org/x.png', 'altText' => ['a']], "Property 'cover': 'altText' must be a string"],
			'a number' => [42, "Property 'cover': image-url must be a URL or an object with a 'url'"],
			'a list' => [['https://example.org/x.png'], "Property 'cover': image-url must be a URL or an object with a 'url'"],
		];
	}//end refusedImages()

	#[DataProvider('refusedImages')]
	public function testARefusedImageNamesThePropertyAndTheRule(mixed $value, string $message): void {
		$this->assertSame($message, (new ImageUrlValue())->validate($value, 'cover'));
	}//end testARefusedImageNamesThePropertyAndTheRule()
}//end class
