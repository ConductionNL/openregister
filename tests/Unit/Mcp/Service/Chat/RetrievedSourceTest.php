<?php

/**
 * RetrievedSource: how one retrieval hit becomes a chat source entry.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Mcp\Service\Chat
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/unit-test-coverage/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Mcp\Service\Chat;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Mcp\Service\Chat\RetrievedSource;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Mcp\Service\Chat\RetrievedSource
 */
class RetrievedSourceTest extends TestCase {

	public function testAnObjectHitCarriesItsRegisterAndSchemaFromMetadata(): void {
		$source = new RetrievedSource([
			'entity_id' => 'abc',
			'entity_type' => 'object',
			'title' => 'Permit 12',
			'similarity' => 0.82,
			'chunk_text' => 'chunk',
			'metadata' => ['uuid' => 'u-1', 'register_id' => 3, 'register' => 9, 'schema' => 5, 'uri' => '/o/u-1'],
		]);

		$this->assertSame(
			[
				'id' => 'abc',
				'type' => 'object',
				'name' => 'Permit 12',
				'similarity' => 0.82,
				'text' => 'chunk',
				'uuid' => 'u-1',
				'register' => 3,
				'schema' => 5,
				'uri' => '/o/u-1',
			],
			$source->toArray()
		);
	}

	public function testAFileHitDecodesJsonMetadataAndFallsBackToItsEntityId(): void {
		$source = new RetrievedSource([
			'entity_id' => '77',
			'entity_type' => 'file',
			'score' => 0.5,
			'text' => 'plain',
			'metadata' => json_encode(['file_name' => 'report.pdf', 'mime_type' => 'application/pdf']),
		]);

		$this->assertSame(
			[
				'id' => '77',
				'type' => 'file',
				'name' => 'report.pdf',
				'similarity' => 0.5,
				'text' => 'plain',
				'file_id' => '77',
				'file_path' => null,
				'mime_type' => 'application/pdf',
			],
			$source->toArray()
		);
	}

	public function testAnUnknownTypeHasNoExtraKeysAndDefaults(): void {
		$this->assertSame(
			['id' => null, 'type' => 'unknown', 'name' => 'Unknown Source', 'similarity' => 1.0, 'text' => ''],
			(new RetrievedSource([]))->toArray()
		);
	}

	public function testTheNamePrefersTopLevelFieldsInOrderAndSkipsEmptyOnes(): void {
		$name = static fn (array $hit): string => (new RetrievedSource($hit))->toArray()['name'];

		$this->assertSame('B', $name(['title' => '', 'name' => 'B', 'filename' => 'C']));
		$this->assertSame('C', $name(['filename' => 'C', 'metadata' => ['object_title' => 'M']]));
		$this->assertSame('M', $name(['metadata' => ['title' => 'T', 'object_title' => 'M']]));
		$this->assertSame('T', $name(['metadata' => ['title' => 'T']]));
	}

	public function testTheFallbackNameUsesTheTypeAndFirstEightIdCharacters(): void {
		$name = static fn (array $hit): string => (new RetrievedSource($hit))->toArray()['name'];

		$this->assertSame('Object #12345678', $name(['entity_id' => '1234567890', 'entity_type' => 'object']));
		$this->assertSame('Item #abc', $name(['entity_id' => 'abc']));
	}
}
