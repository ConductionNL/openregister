<?php

/**
 * HydrateKnownFieldsTrait and IsoTimestampsTrait: hydrating declared fields and formatting timestamps.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
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

namespace OCA\OpenRegister\Tests\Unit\Db;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTime;
use OCA\OpenRegister\Db\HydrateKnownFieldsTrait;
use OCA\OpenRegister\Db\IsoTimestampsTrait;
use OCP\AppFramework\Db\Entity;
use PHPUnit\Framework\TestCase;

/**
 * An entity using both traits, with public windows onto the private formatters.
 *
 * @method string|null getTitle()
 * @method DateTime|null getCreated()
 */
class HydratableTimestampedEntity extends Entity {
	use HydrateKnownFieldsTrait;
	use IsoTimestampsTrait;

	/**
	 * The title.
	 *
	 * @var string|null
	 */
	protected ?string $title = null;

	/**
	 * When it was created.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $created = null;

	/**
	 * When it was last updated.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $updated = null;

	public function __construct() {
		$this->addType(fieldName: 'title', type: 'string');
		$this->addType(fieldName: 'created', type: 'datetime');
		$this->addType(fieldName: 'updated', type: 'datetime');
	}

	public function createdIso(): ?string {
		return $this->getCreatedFormatted();
	}

	public function updatedIso(): ?string {
		return $this->getUpdatedFormatted();
	}
}

/**
 * @covers \OCA\OpenRegister\Db\HydrateKnownFieldsTrait
 * @covers \OCA\OpenRegister\Db\IsoTimestampsTrait
 */
class EntityTraitsTest extends TestCase {

	public function testHydrateSetsDeclaredFieldsAndSkipsIdAndUnknownKeys(): void {
		$entity = new HydratableTimestampedEntity();
		$created = new DateTime('2026-03-01T10:00:00+00:00');

		$result = $entity->hydrate(['id' => 42, 'title' => 'Archive', 'created' => $created, 'colour' => 'red']);

		$this->assertSame($entity, $result);
		$this->assertNull($entity->getId());
		$this->assertSame('Archive', $entity->getTitle());
		$this->assertSame($created, $entity->getCreated());
		$this->assertSame(['title' => true, 'created' => true], $entity->getUpdatedFields());
	}

	public function testTimestampsFormatAsIso8601OrNull(): void {
		$entity = new HydratableTimestampedEntity();
		$this->assertNull($entity->createdIso());
		$this->assertNull($entity->updatedIso());

		$entity->hydrate(['created' => new DateTime('2026-03-01T10:00:00+02:00'), 'updated' => new DateTime('2026-04-02T08:30:00+00:00')]);

		$this->assertSame('2026-03-01T10:00:00+02:00', $entity->createdIso());
		$this->assertSame('2026-04-02T08:30:00+00:00', $entity->updatedIso());
	}
}
