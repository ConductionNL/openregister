<?php

declare(strict_types=1);

/**
 * ObjectVersionHandler tests.
 *
 * Pins the behaviour that `@self.version` is stamped on create and bumped on
 * update — the field that, before this handler, nothing in the repository ever
 * wrote on an ObjectEntity.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

namespace Unit\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Object\ObjectVersionHandler;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ObjectVersionHandler.
 */
class ObjectVersionHandlerTest extends TestCase {

	private ObjectVersionHandler $handler;

	protected function setUp(): void {
		parent::setUp();
		$this->handler = new ObjectVersionHandler();
	}

	/**
	 * A new object with no version starts the sequence.
	 */
	public function testStampsInitialVersionOnAnUnversionedEntity(): void {
		$entity = new ObjectEntity();

		$this->handler->stampInitialVersion(entity: $entity);

		$this->assertSame('0.0.1', $entity->getVersion());
	}

	/**
	 * A version the caller brought — an import, a federation pull — is kept.
	 */
	public function testStampKeepsAnExistingParsableVersion(): void {
		$entity = new ObjectEntity();
		$entity->setVersion('2.4.9');

		$this->handler->stampInitialVersion(entity: $entity);

		$this->assertSame('2.4.9', $entity->getVersion());
	}

	/**
	 * An unusable stored version is replaced rather than kept, so the object
	 * ends up on the sequence instead of carrying a value no reader can order.
	 */
	public function testStampReplacesAnUnusableVersion(): void {
		$entity = new ObjectEntity();
		$entity->setVersion('not-a-version');

		$this->handler->stampInitialVersion(entity: $entity);

		$this->assertSame('0.0.1', $entity->getVersion());
	}

	/**
	 * A save is a patch.
	 */
	public function testBumpAdvancesThePatchComponent(): void {
		$entity = new ObjectEntity();
		$entity->setVersion('0.0.1');

		$this->handler->bumpVersion(entity: $entity);

		$this->assertSame('0.0.2', $entity->getVersion());
	}

	/**
	 * A stored minor/major is respected — the bump does not walk the object
	 * back onto the `0.0.x` line.
	 */
	public function testBumpPreservesMajorAndMinor(): void {
		$entity = new ObjectEntity();
		$entity->setVersion('1.4.2');

		$this->handler->bumpVersion(entity: $entity);

		$this->assertSame('1.4.3', $entity->getVersion());
	}

	/**
	 * The patch component carries past nine without borrowing into the minor:
	 * these are three independent integers, not a decimal.
	 */
	public function testBumpDoesNotCarryIntoTheMinor(): void {
		$entity = new ObjectEntity();
		$entity->setVersion('1.4.9');

		$this->handler->bumpVersion(entity: $entity);

		$this->assertSame('1.4.10', $entity->getVersion());
	}

	/**
	 * The row this whole handler exists for: a magic-table object that predates
	 * it and carries NULL. It joins the sequence rather than staying blank.
	 */
	public function testBumpStartsTheSequenceOnANullVersion(): void {
		$entity = new ObjectEntity();

		$this->handler->bumpVersion(entity: $entity);

		$this->assertSame('0.0.1', $entity->getVersion());
	}

	/**
	 * A two-part version is not arithmetic this handler will do: producing
	 * `1.0.1` from `1.0` would invent a patch component the source never had.
	 */
	public function testBumpRestartsOnATwoPartVersion(): void {
		$entity = new ObjectEntity();
		$entity->setVersion('1.0');

		$this->handler->bumpVersion(entity: $entity);

		$this->assertSame('0.0.1', $entity->getVersion());
	}

	/**
	 * A non-numeric component is refused for the same reason.
	 */
	public function testBumpRestartsOnANonNumericComponent(): void {
		$entity = new ObjectEntity();
		$entity->setVersion('1.0.x');

		$this->handler->bumpVersion(entity: $entity);

		$this->assertSame('0.0.1', $entity->getVersion());
	}

}//end class
