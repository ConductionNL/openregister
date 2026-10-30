<?php

/**
 * Lenses on the list fast path.
 *
 * A plain list read of a magic-mapped schema bypasses renderEntity, where a
 * schema's declared lenses are resolved, so a lens column (dossiq's caseObject
 * `objectTitle` / `objectStatus`) came back absent from the list while the same
 * object read on its own carried it. These tests pin the row helper the fast
 * paths call.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/list-fast-path-resolves-lenses/specs/object-lenses/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Hinge\LensResolver;
use OCA\OpenRegister\Service\Object\RenderObject;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Object\RenderObject
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Schema
 */
class RenderObjectLensRowsTest extends TestCase {

	/**
	 * A schema, with or without the caseObject lenses.
	 *
	 * @param bool $withLenses Whether it declares lenses.
	 *
	 * @return Schema
	 */
	private function schema(bool $withLenses): Schema {
		$schema = new Schema();
		$schema->setSlug('caseObject');
		$configuration = [];
		if ($withLenses === true) {
			$configuration['x-openregister-lenses'] = [
				'objectTitle' => ['through' => 'objectUrl', 'property' => 'name'],
			];
		}

		$schema->setConfiguration($configuration);

		return $schema;
	}//end schema()

	/**
	 * The renderer over a schema mapper answering `$schema` and the given lens resolver.
	 *
	 * @param Schema $schema The schema every row names.
	 * @param LensResolver&MockObject $lenses The lens resolver.
	 *
	 * @return RenderObject
	 */
	private function renderer(Schema $schema, LensResolver&MockObject $lenses): RenderObject {
		$schemaMapper = $this->createMock(originalClassName: SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($schema);

		return new RenderObject(
			fileMapper: $this->createMock(originalClassName: \OCA\OpenRegister\Db\FileMapper::class),
			objectEntityMapper: $this->createMock(originalClassName: \OCA\OpenRegister\Db\MagicMapper::class),
			registerMapper: $this->createMock(originalClassName: \OCA\OpenRegister\Db\RegisterMapper::class),
			schemaMapper: $schemaMapper,
			systemTagManager: $this->createMock(originalClassName: \OCP\SystemTag\ISystemTagManager::class),
			systemTagMapper: $this->createMock(originalClassName: \OCP\SystemTag\ISystemTagObjectMapper::class),
			cacheHandler: $this->createMock(originalClassName: \OCA\OpenRegister\Service\Object\CacheHandler::class),
			objectCacheService: $this->createMock(originalClassName: \OCA\OpenRegister\Service\Object\CacheHandler::class),
			propertyRbacHandler: $this->createMock(originalClassName: \OCA\OpenRegister\Service\PropertyRbacHandler::class),
			logger: $this->createMock(originalClassName: \Psr\Log\LoggerInterface::class),
			fileService: $this->createMock(originalClassName: \OCA\OpenRegister\Service\FileService::class),
			computedFieldHandler: $this->createMock(originalClassName: \OCA\OpenRegister\Service\Object\SaveObject\ComputedFieldHandler::class),
			translationHandler: $this->createMock(originalClassName: \OCA\OpenRegister\Service\Object\TranslationHandler::class),
			linkedEntityEnricher: $this->createMock(originalClassName: \OCA\OpenRegister\Service\Object\LinkedEntityEnricher::class),
			calculationEvaluator: $this->createMock(originalClassName: \OCA\OpenRegister\Service\Calculation\CalculationEvaluator::class),
			urnService: $this->createMock(originalClassName: \OCA\OpenRegister\Service\UrnService::class),
			translationStatusService: $this->createMock(originalClassName: \OCA\OpenRegister\Service\TranslationStatusService::class),
			translationMapper: $this->createMock(originalClassName: \OCA\OpenRegister\Db\TranslationMapper::class),
			languageService: $this->createMock(originalClassName: \OCA\OpenRegister\Service\LanguageService::class),
			lensResolver: $lenses,
		);
	}//end renderer()

	/**
	 * A row of a list.
	 *
	 * @return ObjectEntity
	 */
	private function row(): ObjectEntity {
		$row = new ObjectEntity();
		$row->setSchema('186');
		$row->setObject(['objectUrl' => 'http://x/api/objects/public-space/street-light/57507f0e', 'objectType' => 'Lichtmast']);

		return $row;
	}//end row()

	/**
	 * A list row of a schema with lenses carries them, resolved under the call's rbac.
	 *
	 * @return void
	 */
	public function testARowOfASchemaWithLensesCarriesThem(): void {
		$lenses = $this->createMock(originalClassName: LensResolver::class);
		$lenses->expects($this->once())->method('apply')
			->with($this->isInstanceOf(className: Schema::class), $this->arrayHasKey(key: 'objectUrl'), true)
			->willReturnCallback(static fn (?Schema $schema, array $data, bool $_rbac): array => $data + ['objectTitle' => 'Lichtmast 14']);
		$rows = [$this->row()];

		$this->renderer(schema: $this->schema(withLenses: true), lenses: $lenses)->applyLensesToRows(rows: $rows, _rbac: true);

		$this->assertSame(expected: 'Lichtmast 14', actual: $rows[0]->getObject()['objectTitle']);
	}//end testARowOfASchemaWithLensesCarriesThem()

	/**
	 * A schema without lenses costs no lens lookup.
	 *
	 * @return void
	 */
	public function testARowOfASchemaWithoutLensesIsLeftAlone(): void {
		$lenses = $this->createMock(originalClassName: LensResolver::class);
		$lenses->expects($this->never())->method('apply');
		$rows = [$this->row()];

		$this->renderer(schema: $this->schema(withLenses: false), lenses: $lenses)->applyLensesToRows(rows: $rows, _rbac: false);

		$this->assertArrayNotHasKey(key: 'objectTitle', array: $rows[0]->getObject());
	}//end testARowOfASchemaWithoutLensesIsLeftAlone()
}//end class
