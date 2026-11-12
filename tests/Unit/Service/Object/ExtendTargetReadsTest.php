<?php

/**
 * ExtendTargetReads: which extended targets a reader may see, per rule and per table.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Object\ExtendTargetReads;
use PHPUnit\Framework\TestCase;

/**
 * The rule switch, the refusal memo, and the two read paths.
 *
 * @covers \OCA\OpenRegister\Service\Object\ExtendTargetReads
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 */
class ExtendTargetReadsTest extends TestCase {

	/**
	 * An object in a register and schema.
	 *
	 * @param string $uuid     The uuid.
	 * @param string $register The register id.
	 * @param string $schema   The schema id.
	 *
	 * @return ObjectEntity
	 */
	private function object(string $uuid, string $register, string $schema): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRegister($register);
		$object->setSchema($schema);

		return $object;
	}//end object()

	/**
	 * Changing either flag reports a change and forgets earlier refusals; the same rule again changes nothing.
	 *
	 * @return void
	 */
	public function testARuleChangeForgetsRefusals(): void {
		$reads = new ExtendTargetReads($this->createMock(MagicMapperInterface::class), fn (): bool => true);
		$reads->refuse('a');

		$this->assertTrue($reads->checked());
		$this->assertFalse($reads->applyRule(true, true));
		$this->assertTrue($reads->refused('a'), 'the same rule keeps the memo');
		$this->assertTrue($reads->applyRule(false, true));
		$this->assertFalse($reads->checked());
		$this->assertFalse($reads->refused('a'));
		$reads->refuse(7);
		$this->assertTrue($reads->applyRule(false, false));
		$this->assertFalse($reads->refused(7));
	}//end testARuleChangeForgetsRefusals()

	/**
	 * With multitenancy the mapper is asked once per register and schema, and only the uuids it answers stay, in order.
	 *
	 * @return void
	 */
	public function testWithMultitenancyEachTableIsAskedOnce(): void {
		$a = $this->object(uuid: 'a', register: '1', schema: '2');
		$b = $this->object(uuid: 'b', register: '1', schema: '3');
		$c = $this->object(uuid: 'c', register: '1', schema: '2');
		$mapper = $this->createMock(MagicMapperInterface::class);
		$asked = [];
		$mapper->expects($this->exactly(2))->method('filterReadableUuids')->willReturnCallback(
			function (int $registerId, int $schemaId, array $uuids) use (&$asked): array {
				$asked[] = [$registerId, $schemaId, $uuids];
				return array_values(array_diff($uuids, ['c']));
			}
		);
		$reads = new ExtendTargetReads($mapper, fn (): bool => false);

		$this->assertSame([$a, $b], $reads->readable([$a, $b, $c]));
		$this->assertSame([[1, 2, ['a', 'c']], [1, 3, ['b']]], $asked);
	}//end testWithMultitenancyEachTableIsAskedOnce()

	/**
	 * Without multitenancy the injected check decides per object and the mapper is not asked.
	 *
	 * @return void
	 */
	public function testWithoutMultitenancyTheCheckDecides(): void {
		$a = $this->object(uuid: 'a', register: '1', schema: '2');
		$b = $this->object(uuid: 'b', register: '1', schema: '2');
		$mapper = $this->createMock(MagicMapperInterface::class);
		$mapper->expects($this->never())->method('filterReadableUuids');
		$reads = new ExtendTargetReads($mapper, fn (ObjectEntity $object): bool => $object->getUuid() === 'b');
		$reads->applyRule(true, false);

		$this->assertSame([$b], $reads->readable([$a, $b]));
		$this->assertSame([], $reads->readable([]));
	}//end testWithoutMultitenancyTheCheckDecides()
}//end class
