<?php

/**
 * HistoryEntryRedactor: the property rules read the object's own metadata.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Audit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Audit;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Audit\HistoryEntryRedactor;
use OCA\OpenRegister\Service\Audit\ReadableObject;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The probe the history redactor asks of the property rules carries the record's `@self`.
 *
 * @covers \OCA\OpenRegister\Service\Audit\HistoryEntryRedactor
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Schema
 */
class HistoryEntryRedactorRecordTest extends TestCase {

	/**
	 * A rule on any metadata field reads the record, not a `@self` or `_` key of the data.
	 *
	 * @return void
	 */
	public function testTheProbeCarriesTheRecordsMetadata(): void {
		$seen = null;
		$properties = $this->createMock(PropertyRbacHandler::class);
		$properties->method('filterReadableProperties')->willReturnCallback(
			static function (Schema $schema, array $object) use (&$seen): array {
				$seen = $object;
				unset($object['secret']);
				return $object;
			}
		);

		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$object->setRegister('7');
		$object->setSchema('9');
		$object->setOrganisation('org-b');
		$object->setOwner('bob');
		$object->setObject(['title' => 't', 'secret' => 's', '@self' => ['organisation' => 'org-a', 'schema' => '1']]);

		$method = new ReflectionMethod(HistoryEntryRedactor::class, 'hiddenProperties');
		$hidden = $method->invoke(new HistoryEntryRedactor($properties), new ReadableObject($object, new Schema()), []);

		$this->assertSame(['secret'], $hidden);
		$this->assertSame('org-b', $seen['@self']['organisation']);
		$this->assertSame('bob', $seen['@self']['owner']);
		$this->assertSame('9', $seen['@self']['schema']);
		$this->assertSame('7', $seen['@self']['register']);
	}//end testTheProbeCarriesTheRecordsMetadata()
}//end class
