<?php

/**
 * ObjectVectorizationStrategy: fetch defaults, the text an object is embedded as, and
 * the metadata (title, description, register/schema/uuid/uri) stored with its vector.
 *
 * The view-scope flag on the fetch is pinned separately in
 * ObjectVectorizationStrategyViewScopeTest; this file covers the rest.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Vectorization\Strategies
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Vectorization\Strategies;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable Squiz.Commenting.VariableComment.Missing -- typed mock fixtures; the declaration IS the description.

use JsonSerializable;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\SettingsServiceInterface;
use OCA\OpenRegister\Service\Vectorization\Strategies\ObjectVectorizationStrategy;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Service\Vectorization\Strategies\ObjectVectorizationStrategy
 */
class ObjectVectorizationStrategyMetadataTest extends TestCase {

	private ObjectOperationsInterface&MockObject $objects;
	private SettingsServiceInterface&MockObject $settings;
	private ObjectVectorizationStrategy $strategy;

	protected function setUp(): void {
		parent::setUp();
		$this->objects = $this->createMock(ObjectOperationsInterface::class);
		$this->settings = $this->createMock(SettingsServiceInterface::class);
		$this->strategy = new ObjectVectorizationStrategy($this->objects, $this->settings, new NullLogger());
	}

	/**
	 * An entity as the strategy receives it: anything that serializes to the object data.
	 *
	 * @param array<string, mixed> $data The serialized object.
	 *
	 * @return JsonSerializable
	 */
	private static function entity(array $data): JsonSerializable {
		return new class($data) implements JsonSerializable {
			public function __construct(private readonly array $data) {
			}

			public function jsonSerialize(): array {
				return $this->data;
			}
		};
	}

	/**
	 * Object data with every optional lookup key present but null, so the
	 * fallback chains are reached without undefined-key notices.
	 *
	 * @param array<string, mixed> $data The fields to set.
	 *
	 * @return array<string, mixed>
	 */
	private static function data(array $data): array {
		return array_merge(['summary' => null, 'Beschrijving' => null], $data);
	}

	public function testFetchUsesTheDefaultBatchSizeAndDatabaseSource(): void {
		$rows = [['id' => 1], ['id' => 2]];
		$this->objects->expects($this->once())->method('searchObjects')
			->with(['_limit' => 25, '_source' => 'database'], false, false, null, null, null, true)
			->willReturn($rows);

		$this->assertSame($rows, $this->strategy->fetchEntities([]));
	}

	public function testFetchPassesBatchSizeAndViews(): void {
		$this->objects->expects($this->once())->method('searchObjects')
			->with(['_limit' => 3, '_source' => 'database'], false, false, null, null, ['v1'], true)
			->willReturn([]);

		$this->assertSame([], $this->strategy->fetchEntities(['batch_size' => 3, 'views' => ['v1']]));
	}

	public function testACountResultIsTreatedAsNoObjects(): void {
		$this->objects->method('searchObjects')->willReturn(7);

		$this->assertSame([], $this->strategy->fetchEntities([]));
	}

	public function testAnObjectIsEmbeddedAsOnePrettyPrintedJsonItem(): void {
		$this->settings->expects($this->once())->method('getObjectSettingsOnly')->willReturn(['includeMetadata' => false]);
		$data = ['id' => 5, 'title' => 'Zaak'];

		$items = $this->strategy->extractVectorizationItems(self::entity($data));

		$this->assertSame([['text' => json_encode($data, JSON_PRETTY_PRINT), 'index' => 0]], $items);
	}

	public function testMetadataTakesTitleDescriptionAndIdsFromTopLevelFields(): void {
		$entity = self::entity(self::data([
			'id' => 12, 'title' => 'Title', 'name' => 'Name', 'description' => 'Desc',
			'register' => 3, 'schema' => 4, 'uuid' => 'u-1', 'uri' => 'https://x/1',
			'@self' => ['register' => 30, 'schema' => 40, 'id' => 'self-id', 'uri' => 'self-uri'],
		]));

		$meta = $this->strategy->prepareVectorMetadata($entity, ['text' => str_repeat('b', 501)]);

		$this->assertSame('object', $meta['entity_type']);
		$this->assertSame('12', $meta['entity_id']);
		$this->assertSame(0, $meta['chunk_index']);
		$this->assertSame(1, $meta['total_chunks']);
		$this->assertSame(str_repeat('b', 500), $meta['chunk_text']);
		$extra = $meta['additional_metadata'];
		$this->assertSame(['Title', 'Title', 'Title'], [$extra['object_title'], $extra['title'], $extra['name']]);
		$this->assertSame('Desc', $extra['description']);
		$this->assertSame([3, 3, 4, 4], [$extra['register'], $extra['register_id'], $extra['schema'], $extra['schema_id']]);
		$this->assertSame(['u-1', 'https://x/1'], [$extra['uuid'], $extra['uri']]);
	}

	public function testMetadataFallsBackToTheSelfBlockForIds(): void {
		$entity = self::entity(self::data([
			'id' => 1, 'name' => 'N',
			'@self' => ['register' => 30, 'schema' => 40, 'id' => 'self-id', 'uri' => 'self-uri'],
		]));

		$extra = $this->strategy->prepareVectorMetadata($entity, ['text' => 'x'])['additional_metadata'];

		$this->assertSame('N', $extra['title']);
		$this->assertSame([30, 40, 'self-id', 'self-uri'], [$extra['register'], $extra['schema'], $extra['uuid'], $extra['uri']]);
		$this->assertSame('', $extra['description']);
	}

	public function testUnderscoredFieldsAreUsedBeforeTheSelfBlock(): void {
		$entity = self::entity(self::data([
			'id' => 1, '_name' => 'Underscored', '_register' => 7, '_schema' => 8, '_uuid' => 'uu', '_uri' => 'ur',
			'_description' => 'D', '@self' => ['register' => 30],
		]));

		$extra = $this->strategy->prepareVectorMetadata($entity, ['text' => 'x'])['additional_metadata'];

		$this->assertSame(['Underscored', 'D'], [$extra['title'], $extra['description']]);
		$this->assertSame([7, 8, 'uu', 'ur'], [$extra['register'], $extra['schema'], $extra['uuid'], $extra['uri']]);
	}

	public function testWithoutATitleFieldTheFirstShortPlainStringIsUsed(): void {
		$entity = self::entity(self::data([
			'_hidden' => 'meta', '@type' => 'x', 'id' => 'id-string', 'Description' => 'skip me',
			'empty' => '', 'long' => str_repeat('l', 100), 'count' => 3, 'label' => 'Label',
			'beschrijving' => 'lower-case dutch',
		]));

		$extra = $this->strategy->prepareVectorMetadata($entity, ['text' => 'x'])['additional_metadata'];

		$this->assertSame('Label', $extra['title']);
		$this->assertSame('lower-case dutch', $extra['description']);
	}

	public function testWithoutAnyUsableStringTheTitleNamesTheObjectId(): void {
		$entity = self::entity(self::data(['id' => 77, 'count' => 1, 'text' => 'skipped']));

		$extra = $this->strategy->prepareVectorMetadata($entity, ['text' => 'x'])['additional_metadata'];

		$this->assertSame('Object #77', $extra['title']);
		$this->assertSame(77, $extra['object_id']);
	}

	public function testWithoutAnIdTheObjectIsUnknown(): void {
		$entity = self::entity(self::data(['summary' => 'Sum']));

		$meta = $this->strategy->prepareVectorMetadata($entity, ['text' => 'x']);

		$this->assertSame('unknown', $meta['entity_id']);
		$this->assertSame('Sum', $meta['additional_metadata']['title']);
		$this->assertSame('Sum', $meta['additional_metadata']['description']);
		$this->assertNull($meta['additional_metadata']['register']);
	}

	public function testTheIdentifierIsTheIdOrUnknown(): void {
		$this->assertSame(9, $this->strategy->getEntityIdentifier(self::entity(['id' => 9])));
		$this->assertSame('unknown', $this->strategy->getEntityIdentifier(self::entity(['id' => null])));
	}
}
