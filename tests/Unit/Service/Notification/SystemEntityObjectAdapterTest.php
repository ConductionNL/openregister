<?php

/**
 * SystemEntityObjectAdapter Unit Test
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/openregister-system-notifications/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Notification;

use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Service\Notification\SystemEntityObjectAdapter;
use OCA\OpenRegister\Service\Notification\SystemSchemaRules;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SystemEntityObjectAdapter.
 */
class SystemEntityObjectAdapterTest extends TestCase {

	/**
	 * Adapter exposes the system slug as its schema reference.
	 */
	public function testAdapterSetsSchemaToSystemSlug(): void {
		$source = new Source();
		$source->setUuid('test-uuid-123');
		$source->setTitle('Test Source');

		$adapter = new SystemEntityObjectAdapter(entity: $source, systemSlug: SystemSchemaRules::SLUG_SOURCE);

		$this->assertSame(SystemSchemaRules::SLUG_SOURCE, $adapter->getSchema());
	}//end testAdapterSetsSchemaToSystemSlug()

	/**
	 * Adapter exposes the entity's UUID.
	 */
	public function testAdapterExposesEntityUuid(): void {
		$source = new Source();
		$source->setUuid('abc-def-456');
		$source->setTitle('My Source');

		$adapter = new SystemEntityObjectAdapter(entity: $source, systemSlug: SystemSchemaRules::SLUG_SOURCE);

		$this->assertSame('abc-def-456', $adapter->getUuid());
	}//end testAdapterExposesEntityUuid()

	/**
	 * Adapter exposes the entity's title as name.
	 */
	public function testAdapterExposesEntityTitleAsName(): void {
		$source = new Source();
		$source->setUuid('uuid-1');
		$source->setTitle('My Source Title');

		$adapter = new SystemEntityObjectAdapter(entity: $source, systemSlug: SystemSchemaRules::SLUG_SOURCE);

		$this->assertSame('My Source Title', $adapter->getName());
	}//end testAdapterExposesEntityTitleAsName()

	/**
	 * Adapter populates the object payload from the entity's jsonSerialize data.
	 */
	public function testAdapterPopulatesObjectFromJsonSerialize(): void {
		$source = new Source();
		$source->setUuid('uuid-payload');
		$source->setTitle('Payload Source');

		$adapter = new SystemEntityObjectAdapter(entity: $source, systemSlug: SystemSchemaRules::SLUG_SOURCE);

		$objectData = $adapter->getObject();
		$this->assertIsArray($objectData);
	}//end testAdapterPopulatesObjectFromJsonSerialize()

	/**
	 * Adapter register is null — system entities have no register reference.
	 */
	public function testAdapterRegisterIsNull(): void {
		$source = new Source();
		$source->setUuid('uuid-no-reg');

		$adapter = new SystemEntityObjectAdapter(entity: $source, systemSlug: SystemSchemaRules::SLUG_SOURCE);

		$this->assertNull($adapter->getRegister());
	}//end testAdapterRegisterIsNull()

}//end class
