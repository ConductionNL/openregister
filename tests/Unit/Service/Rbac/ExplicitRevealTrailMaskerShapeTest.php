<?php

/**
 * Entry-shape tests for ExplicitRevealTrailMasker.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use JsonSerializable;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Rbac\ExplicitRevealTrailMasker;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Raw rows, serialisable entries and entries the masker cannot read.
 *
 * @covers \OCA\OpenRegister\Service\Rbac\ExplicitRevealTrailMasker
 * @uses \OCA\OpenRegister\Service\Rbac\ExplicitReveal
 * @uses \OCA\OpenRegister\Service\Rbac\ExplicitRevealChangeMask
 */
class ExplicitRevealTrailMaskerShapeTest extends TestCase {

	/**
	 * An unreadable schema is looked up once, and every change of its entries is masked;
	 * a raw row's JSON text is masked and re-encoded.
	 *
	 * @return void
	 */
	public function testUnreadableSchemaMasksEverythingAndIsLookedUpOnce(): void {
		$mapper = $this->createMock(SchemaMapperInterface::class);
		$mapper->expects($this->once())->method('find')->willThrowException(new RuntimeException('deleted'));
		$masker = new ExplicitRevealTrailMasker(schemaMapper: $mapper);

		$result = $masker->mask(
			entries: [
				['schema' => 9, 'changed' => json_encode(['iban' => ['old' => 'NL91ABNA0417164300', 'new' => null]])],
				['schema' => '9', 'changed' => ['name' => 'Jan Jansen']],
			]
		);

		$this->assertSame(json_encode(['iban' => ['old' => '*****4300', 'new' => null]]), $result[0]['changed']);
		$this->assertSame(['name' => '*****nsen'], $result[1]['changed']);
	}//end testUnreadableSchemaMasksEverythingAndIsLookedUpOnce()

	/**
	 * A serialisable entry is read through jsonSerialize(); with no schema named everything is masked.
	 *
	 * @return void
	 */
	public function testSerialisableEntryWithoutSchemaIsMasked(): void {
		$mapper = $this->createMock(SchemaMapperInterface::class);
		$mapper->expects($this->never())->method('find');
		$entry = new class implements JsonSerializable {
			/**
			 * The row.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['schema' => '', 'changed' => ['bsn' => '123456782']];
			}
		};

		$result = (new ExplicitRevealTrailMasker(schemaMapper: $mapper))->mask(entries: ['e' => $entry]);

		$this->assertSame(['schema' => '', 'changed' => ['bsn' => '*****6782']], $result['e']);
	}//end testSerialisableEntryWithoutSchemaIsMasked()

	/**
	 * Entries it cannot read, and entries on a schema with no explicit-reveal property, pass unchanged.
	 *
	 * @return void
	 */
	public function testUnreadableEntriesAndUnaffectedSchemasPassUnchanged(): void {
		$plain = new Schema();
		$plain->setProperties(['name' => ['type' => 'string']]);
		$mapper = $this->createMock(SchemaMapperInterface::class);
		$mapper->method('find')->willReturn($plain);

		$entries = [
			'scalar',
			['schema' => 3, 'changed' => '{not json'],
			['schema' => 3, 'changed' => null],
			['schema' => 3, 'changed' => ['name' => 'Jan Jansen']],
		];

		$this->assertSame($entries, (new ExplicitRevealTrailMasker(schemaMapper: $mapper))->mask(entries: $entries));
	}//end testUnreadableEntriesAndUnaffectedSchemasPassUnchanged()
}//end class
