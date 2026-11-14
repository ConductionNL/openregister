<?php

/**
 * A schema's `previewUrl`: validated as a URL template with known placeholders, and filled per preview.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db\Schema
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/records-draft-versions/specs/content-versioning/spec.md#requirement-a-draft-can-be-previewed-on-an-outside-site-through-an-expiring-link
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\Schema;

use InvalidArgumentException;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\Schema\PreviewUrlTemplate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\Schema\PreviewUrlTemplate
 * @covers \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @covers \OCA\OpenRegister\Db\Schema
 */
final class PreviewUrlTemplateTest extends TestCase {

	private const TEMPLATE = 'https://www.voorbeeld.nl/preview/{uuid}?versie={version}&token={token}';

	public function testAKnownTemplateIsKeptTrimmed(): void {
		self::assertSame(self::TEMPLATE, PreviewUrlTemplate::validated(value: '  ' . self::TEMPLATE . ' '));
		self::assertSame('http://localhost:3000/p', PreviewUrlTemplate::validated(value: 'http://localhost:3000/p'));
	}

	public function testNothingDeclaredStoresNothing(): void {
		self::assertNull(PreviewUrlTemplate::validated(value: null));
		self::assertNull(PreviewUrlTemplate::validated(value: '   '));
	}

	/**
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public static function refused(): array {
		return [
			'unknown placeholder' => ['https://site.nl/{id}', 'unknown placeholder {id}'],
			'unbalanced brace'    => ['https://site.nl/{uuid', 'unbalanced brace'],
			'stray closing brace' => ['https://site.nl/uuid}', 'unbalanced brace'],
			'relative'            => ['/preview/{uuid}', 'absolute http or https'],
			'other scheme'        => ['javascript:alert({uuid})', 'absolute http or https'],
			'spaces'              => ['https://site.nl/a b/{uuid}', 'absolute http or https'],
			'not a string'        => [['https://site.nl'], 'URL template string'],
			'too long'            => ['https://site.nl/' . str_repeat('a', 2048), 'longer than 2048'],
		];
	}

	#[DataProvider('refused')]
	public function testAnUnusableTemplateIsRefused(mixed $value, string $message): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);

		PreviewUrlTemplate::validated(value: $value);
	}

	public function testFillingEncodesEachValue(): void {
		self::assertSame(
			'https://www.voorbeeld.nl/preview/9a1e?versie=her%20ziening&token=a%26b',
			PreviewUrlTemplate::fill(template: self::TEMPLATE, uuid: '9a1e', version: 'her ziening', token: 'a&b')
		);
	}

	public function testASchemaSaveKeepsAValidTemplateAndReadsItBack(): void {
		$schema = new Schema();
		$schema->setConfiguration(['previewUrl' => self::TEMPLATE, 'exportable' => true]);

		self::assertSame(self::TEMPLATE, $schema->getPreviewUrl());
		self::assertSame(self::TEMPLATE, $schema->getConfiguration()['previewUrl']);
		self::assertSame([], $schema->consumeDroppedAnnotationKeys());
	}

	public function testASchemaSaveDropsABadTemplateAndKeepsTheRest(): void {
		$schema = new Schema();
		$schema->setConfiguration(['previewUrl' => 'https://site.nl/{slug}', 'exportable' => true]);

		self::assertNull($schema->getPreviewUrl());
		self::assertArrayNotHasKey('previewUrl', $schema->getConfiguration());
		self::assertTrue($schema->getConfiguration()['exportable']);
		self::assertSame(['previewUrl'], $schema->consumeDroppedAnnotationKeys());
	}

	public function testASchemaWithoutATemplateHasNone(): void {
		self::assertNull((new Schema())->getPreviewUrl());
	}
}
