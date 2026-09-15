<?php

/**
 * OpenRegister - `immutable: true` on a property.
 *
 * Pins the one behaviour that separates immutable from readOnly: the first
 * value is accepted.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Object\ValidateObject
 */
final class ValidateObjectImmutableTest extends TestCase {

	/**
	 * The validator under test.
	 *
	 * @return ValidateObject The validator.
	 */
	private function validator(): ValidateObject {
		return new ValidateObject(
			$this->createMock(originalClassName: IAppConfig::class),
			$this->createMock(originalClassName: MagicMapper::class),
			$this->createMock(originalClassName: SchemaMapper::class),
			$this->createMock(originalClassName: IURLGenerator::class),
			$this->createMock(originalClassName: LoggerInterface::class),
			$this->createMock(originalClassName: IUserManager::class)
		);
	}//end validator()

	/**
	 * A schema with one immutable date property and one ordinary one.
	 *
	 * @return Schema The schema.
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->setTitle('Besluit');
		$schema->setProperties(
			[
				'vastgesteldOp' => [
					'type' => 'string',
					'format' => 'date',
					Schema::IMMUTABLE_PROPERTY_KEYWORD => true,
				],
				'toelichting' => ['type' => 'string'],
			]
		);

		return $schema;
	}//end schema()

	/**
	 * A vastgesteld besluit keeps its date.
	 *
	 * @return void
	 */
	public function testChangingAnImmutablePropertyIsRefused(): void {
		$violations = $this->validator()->validateImmutableConstraints(
			incomingObject: ['vastgesteldOp' => '2026-09-15'],
			existingObject: ['vastgesteldOp' => '2026-03-01'],
			schema: $this->schema()
		);

		$this->assertCount(1, $violations);
		$this->assertSame('vastgesteldOp', $violations[0]['property']);
		$this->assertStringContainsString('vastgesteldOp', $violations[0]['message']);
	}//end testChangingAnImmutablePropertyIsRefused()

	/**
	 * An unset immutable property can still be set, once.
	 *
	 * ⚠️ THE test. This is the whole difference from `readOnly`, which refuses
	 * every value that differs from what is stored — including the first one,
	 * so a property nobody filled in on create could never be filled in at all.
	 * An `immutable` that behaved like `readOnly` would pass every other test
	 * in this file.
	 *
	 * @return void
	 */
	public function testAnUnsetImmutablePropertyCanStillBeSet(): void {
		$violations = $this->validator()->validateImmutableConstraints(
			incomingObject: ['vastgesteldOp' => '2026-09-15'],
			existingObject: ['vastgesteldOp' => null, 'toelichting' => 'concept'],
			schema: $this->schema()
		);

		$this->assertSame([], $violations);
	}//end testAnUnsetImmutablePropertyCanStillBeSet()

	/**
	 * And once it is set, the next change is refused.
	 *
	 * @return void
	 */
	public function testTheSecondChangeIsRefused(): void {
		$schema = $this->schema();

		$first = $this->validator()->validateImmutableConstraints(
			incomingObject: ['vastgesteldOp' => '2026-09-15'],
			existingObject: ['vastgesteldOp' => ''],
			schema: $schema
		);
		$this->assertSame([], $first);

		$second = $this->validator()->validateImmutableConstraints(
			incomingObject: ['vastgesteldOp' => '2026-10-01'],
			existingObject: ['vastgesteldOp' => '2026-09-15'],
			schema: $schema
		);
		$this->assertCount(1, $second);
	}//end testTheSecondChangeIsRefused()

	/**
	 * Rewriting the same value is not a change.
	 *
	 * A form that posts every field back would otherwise be unable to edit any
	 * other property of a record with an immutable one.
	 *
	 * @return void
	 */
	public function testRewritingTheSameValueIsAccepted(): void {
		$violations = $this->validator()->validateImmutableConstraints(
			incomingObject: ['vastgesteldOp' => '2026-03-01', 'toelichting' => 'nieuw'],
			existingObject: ['vastgesteldOp' => '2026-03-01', 'toelichting' => 'oud'],
			schema: $this->schema()
		);

		$this->assertSame([], $violations);
	}//end testRewritingTheSameValueIsAccepted()

	/**
	 * A property that is not declared immutable is left alone.
	 *
	 * The control. A check that refused every change would pass the two
	 * refusal tests above and be indistinguishable from one that reads the
	 * keyword.
	 *
	 * @return void
	 */
	public function testAnOrdinaryPropertyIsNotAffected(): void {
		$violations = $this->validator()->validateImmutableConstraints(
			incomingObject: ['toelichting' => 'nieuw'],
			existingObject: ['toelichting' => 'oud'],
			schema: $this->schema()
		);

		$this->assertSame([], $violations);
	}//end testAnOrdinaryPropertyIsNotAffected()

	/**
	 * Creating an object is never refused: there is no prior value to violate.
	 *
	 * @return void
	 */
	public function testCreateIsNotAffected(): void {
		$violations = $this->validator()->validateImmutableConstraints(
			incomingObject: ['vastgesteldOp' => '2026-09-15'],
			existingObject: [],
			schema: $this->schema()
		);

		$this->assertSame([], $violations);
	}//end testCreateIsNotAffected()
}//end class
