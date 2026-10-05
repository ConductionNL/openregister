<?php

/**
 * The configuration preview survives duplicate seed slugs and comes to rest
 * after an import (live pass O12 and O13, 5 Oct).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/config-preview-duplicates-and-rest/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Configuration\FetchHandler;
use OCA\OpenRegister\Service\Configuration\PreviewHandler;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * One local register `filinq` and schema `consent`, as the live configuration 8 had.
 */
final class PreviewHandlerComesToRestTest extends TestCase {

	private RegisterMapper $registerMapper;

	private SchemaMapper $schemaMapper;

	private FetchHandler $fetchHandler;

	private MagicMapper $objectMapper;

	private PreviewHandler $handler;

	private Schema $schema;

	/**
	 * Set up the handler over the local register and schema.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$register = new Register();
		$register->setId(4);
		$register->setSlug('filinq');
		$register->setVersion('1.0.0');

		$this->schema = new Schema();
		$this->schema->setId(9);
		$this->schema->setSlug('consent');
		$this->schema->setVersion('1.0.0');
		$this->schema->setProperties(['title' => ['type' => 'string'], 'colour' => ['type' => 'string']]);

		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->registerMapper->method('findAll')->willReturn([$register]);
		$this->registerMapper->method('find')->willThrowException(new DoesNotExistException('none'));
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->schemaMapper->method('findAll')->willReturn([$this->schema]);
		$this->schemaMapper->method('find')->willThrowException(new DoesNotExistException('none'));
		$this->fetchHandler = $this->createMock(FetchHandler::class);
		$this->objectMapper = $this->createMock(MagicMapper::class);

		$this->handler = new PreviewHandler(
			$this->registerMapper,
			$this->schemaMapper,
			$this->createMock(LoggerInterface::class),
			$this->fetchHandler,
			$this->objectMapper
		);
	}//end setUp()

	/**
	 * Preview a remote document.
	 *
	 * @param array<string, mixed> $components The remote components.
	 *
	 * @return array<string, mixed>
	 */
	private function preview(array $components): array {
		$this->fetchHandler->method('fetchRemoteConfiguration')->willReturn(['components' => $components]);

		$configuration = new Configuration();
		$configuration->setId(8);

		return $this->handler->previewConfigurationChanges($configuration);
	}//end preview()

	/**
	 * A remote seed object.
	 *
	 * @param string $slug     Object slug.
	 * @param string $register Register slug.
	 * @param string $schema   Schema slug.
	 *
	 * @return array<string, mixed>
	 */
	private function seed(string $slug, string $register='filinq', string $schema='consent'): array {
		return [
			'title' => 'Bakker',
			'@self' => ['register' => $register, 'schema' => $schema, 'slug' => $slug, 'version' => '1.0.1'],
		];
	}//end seed()

	/**
	 * O12: two live rows with one seed slug make that row a skip with the reason, not a 500.
	 *
	 * Live: GET /api/configurations/8/preview answered 500, "Multiple objects
	 * found with same identifier" (MagicMapper::find()).
	 *
	 * @return void
	 */
	public function testADuplicateSeedSlugIsAFindingNotAFailure(): void {
		$this->objectMapper->method('find')->willReturnCallback(
			static function (string|int $identifier) {
				if ($identifier === 'standing-consent-verbal-bakker') {
					throw new MultipleObjectsReturnedException('Multiple objects found with same identifier');
				}

				throw new DoesNotExistException('none');
			}
		);

		$preview = $this->preview(
			components: ['objects' => [$this->seed(slug: 'standing-consent-verbal-bakker'), $this->seed(slug: 'other')]]
		);

		self::assertIsArray($preview);
		self::assertSame(['skip', 'create'], array_column($preview['objects'], 'action'));
		self::assertStringContainsString('standing-consent-verbal-bakker', $preview['objects'][0]['reason']);
		self::assertStringContainsString('more than one', $preview['objects'][0]['reason']);
	}//end testADuplicateSeedSlugIsAFindingNotAFailure()

	/**
	 * O13a: a property the schema does not declare is discarded by the import, so it is not a change.
	 *
	 * Live: after the import every new preview still said "update" with
	 * `notInSchema: null -> x1`; the import logged "Discarding 1 property the
	 * schema does not declare".
	 *
	 * @return void
	 */
	public function testAnUndeclaredPropertyIsDiscardedNotAChange(): void {
		$stored = new ObjectEntity();
		$stored->setUuid('11111111-2222-3333-4444-555555555555');
		$stored->setVersion('1.0.0');
		$stored->setObject(['title' => 'Bakker', 'colour' => 'blue']);
		$this->objectMapper->method('find')->willReturn($stored);

		$remote = $this->seed(slug: 'livepass-lane15-s1');
		$remote['colour'] = 'blue';
		$remote['notInSchema'] = 'x1';

		$row = $this->preview(components: ['objects' => [$remote]])['objects'][0];

		self::assertSame([], $row['changes']);
		self::assertSame(['notInSchema'], $row['discarded']);
		self::assertSame('skip', $row['action'], 'Nothing the import would write differs: the preview is at rest.');
	}//end testAnUndeclaredPropertyIsDiscardedNotAChange()

	/**
	 * O13b: an object whose register and schema the same import creates is a create, not a skip.
	 *
	 * Live: the first preview listed the register and schema as "create" and
	 * their objects as "skip: Register or schema not found locally", while the
	 * import then created them.
	 *
	 * @return void
	 */
	public function testAnObjectUnderARegisterAndSchemaThisImportCreatesIsACreate(): void {
		$this->objectMapper->expects(self::never())->method('find');

		$preview = $this->preview(
			components: [
				'registers' => ['livepass-lane15-reg' => ['title' => 'Reg', 'version' => '1.0.0']],
				'schemas'   => ['livepass-lane15-seed' => ['title' => 'Seed', 'version' => '1.0.0']],
				'objects'   => [
					$this->seed(slug: 'livepass-lane15-s1', register: 'livepass-lane15-reg', schema: 'livepass-lane15-seed'),
					$this->seed(slug: 'nowhere', register: 'livepass-lane15-reg', schema: 'unknown'),
				],
			]
		);

		self::assertSame('create', $preview['registers'][0]['action']);
		self::assertSame('create', $preview['schemas'][0]['action']);
		self::assertSame(['create', 'skip'], array_column($preview['objects'], 'action'));
		self::assertArrayNotHasKey('reason', $preview['objects'][0]);
	}//end testAnObjectUnderARegisterAndSchemaThisImportCreatesIsACreate()
}//end class
