<?php

declare(strict_types=1);

/**
 * ObjectEntity Archival Retention Tests
 *
 * Verifies that the _retention block appears in @self when set by the render layer
 * and is absent when not set.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 *
 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-6.2
 */

namespace Unit\Db;

use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ObjectEntity archival retention metadata.
 */
class ObjectEntityArchivalRetentionTest extends TestCase {

	private ObjectEntity $entity;

	protected function setUp(): void {
		parent::setUp();
		$this->entity = new ObjectEntity();
	}

	/**
	 * _retention is absent when archivalRetention has not been set.
	 */
	public function testRetentionBlockAbsentWhenNotSet(): void {
		$serialized = $this->entity->jsonSerialize();

		// _retention should NOT appear in the @self block.
		$selfBlock = $serialized['@self'] ?? [];
		$this->assertArrayNotHasKey('_retention', $selfBlock);
	}

	/**
	 * _retention appears in @self when setArchivalRetention() is called.
	 */
	public function testRetentionBlockPresentWhenSet(): void {
		$retention = [
			'effectiveRetention' => 'PT1H',
			'matchedRule' => 0,
			'expiresAt' => '2026-01-01T01:00:00+00:00',
		];

		$this->entity->setArchivalRetention(retention: $retention);

		$serialized = $this->entity->jsonSerialize();
		$selfBlock = $serialized['@self'] ?? [];

		$this->assertArrayHasKey('_retention', $selfBlock);
		$this->assertSame($retention, $selfBlock['_retention']);
	}

	/**
	 * getArchivalRetention() returns null by default.
	 */
	public function testGetterReturnsNullByDefault(): void {
		$this->assertNull($this->entity->getArchivalRetention());
	}

	/**
	 * Setter and getter round-trip.
	 */
	public function testSetterGetterRoundTrip(): void {
		$retention = [
			'effectiveRetention' => 'P30D',
			'matchedRule' => null,
			'expiresAt' => '2026-01-31T00:00:00+00:00',
		];

		$this->entity->setArchivalRetention(retention: $retention);
		$this->assertSame($retention, $this->entity->getArchivalRetention());
	}
}//end class
