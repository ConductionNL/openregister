<?php

/**
 * ReferenceFilterGuard: a filtered reference only accepts an object matching the record being saved.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject
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

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Exception\ReferenceValidationException;
use OCA\OpenRegister\Service\Object\SaveObject\ReferenceFilterGuard;
use OCA\OpenRegister\Service\Object\SaveObject\ReferencePropertyRun;
use OCA\OpenRegister\Service\Object\SaveObject\SaveLookups;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Each decision the guard makes.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\ReferenceFilterGuard
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ReferencePropertyRun
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SaveLookups
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ReferenceText
 * @uses \OCA\OpenRegister\Service\Schemas\ReferenceFilterDeclaration
 * @uses \OCA\OpenRegister\Exception\BrokenReference
 * @uses \OCA\OpenRegister\Exception\ReferenceValidationException
 * @uses \OCA\OpenRegister\Exception\ValidationException
 * @uses \OCA\OpenRegister\Formats\UuidFormat
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 */
class ReferenceFilterGuardTest extends TestCase {

	/**
	 * The object mapper.
	 *
	 * @var MagicMapperInterface&MockObject
	 */
	private MagicMapperInterface $mapper;

	/**
	 * The register mapper behind the lookups.
	 *
	 * @var RegisterMapperInterface&MockObject
	 */
	private RegisterMapperInterface $registers;

	/**
	 * The schema mapper behind the lookups.
	 *
	 * @var SchemaMapperInterface&MockObject
	 */
	private SchemaMapperInterface $schemas;

	/**
	 * Build the boundaries: one schema "contact" with id 5.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->mapper = $this->createMock(MagicMapperInterface::class);
		$this->registers = $this->createMock(RegisterMapperInterface::class);
		$this->schemas = $this->createMock(SchemaMapperInterface::class);
		$contact = new Schema();
		$contact->setId(5);
		$contact->setSlug('contact');
		$this->schemas->method('findAll')->willReturn([$contact]);
		$this->schemas->method('find')->willThrowException(new RuntimeException('not by id'));
	}//end setUp()

	/**
	 * The guard under test.
	 *
	 * @return ReferenceFilterGuard
	 */
	private function guard(): ReferenceFilterGuard {
		return new ReferenceFilterGuard(new SaveLookups($this->schemas, $this->registers), $this->mapper);
	}//end guard()

	/**
	 * A run for property contactPerson filtered by the record's organisation.
	 *
	 * @param array<string, mixed> $data      The record being saved.
	 * @param array<int, mixed>    $condition The filter conditions.
	 *
	 * @return ReferencePropertyRun
	 */
	private function propertyRun(array $data, array $condition = [['field' => 'organisation', 'from' => 'organisation']]): ReferencePropertyRun {
		$run = ReferencePropertyRun::forSave(data: $data, register: '3');
		$run->propertyName = 'contactPerson';
		$run->property = ['$ref' => 'contact', 'x-openregister-reference-filter' => $condition];
		$run->ref = 'contact';
		$run->uuid = 'c-1';

		return $run;
	}//end run()

	/**
	 * The referenced object the mapper answers.
	 *
	 * @param array<string, mixed> $data Its data.
	 *
	 * @return void
	 */
	private function referenced(array $data): void {
		$object = new ObjectEntity();
		$object->setObject($data);
		$this->mapper->method('find')->willReturn($object);
	}//end referenced()

	/**
	 * A property without a filter is not read at all.
	 *
	 * @return void
	 */
	public function testAnUnfilteredPropertyIsNotChecked(): void {
		$run = $this->propertyRun(data: []);
		$run->property = ['$ref' => 'contact'];
		$this->mapper->expects($this->never())->method('find');

		$this->guard()->assertMatches($run);
	}//end testAnUnfilteredPropertyIsNotChecked()

	/**
	 * A record that does not answer the filter's operand is refused, naming what is needed, before anything is read.
	 *
	 * @return void
	 */
	public function testAnUnansweredOperandIsRefused(): void {
		$this->mapper->expects($this->never())->method('find');

		try {
			$this->guard()->assertMatches($this->propertyRun(data: ['organisation' => '']));
			$this->fail('expected a refusal');
		} catch (ReferenceValidationException $e) {
			$this->assertSame(
				"'contactPerson' is filtered on organisation, and this record answers none of them, so nothing may be chosen for it yet.",
				$e->getMessage()
			);
		}
	}//end testAnUnansweredOperandIsRefused()

	/**
	 * A matching referenced object passes; one belonging elsewhere is refused with the property, field and uuid.
	 *
	 * @return void
	 */
	public function testTheReferencedObjectMustMatch(): void {
		$this->referenced(data: ['organisation' => 'org-1']);
		$this->mapper->expects($this->exactly(2))->method('find')->with('c-1', null, null, false, false, false);
		$guard = $this->guard();

		$guard->assertMatches($this->propertyRun(data: ['organisation' => 'org-1']));
		$this->expectException(ReferenceValidationException::class);
		$this->expectExceptionMessage("'contactPerson' only accepts an object whose 'organisation' matches this record. 'c-1' does not.");
		$guard->assertMatches($this->propertyRun(data: ['organisation' => 'org-2']));
	}//end testTheReferencedObjectMustMatch()

	/**
	 * The neq and in operators decide by their own rule; an eq operand that is a list matches nothing.
	 *
	 * @return void
	 */
	public function testNeqAndInOperators(): void {
		$this->referenced(data: ['status' => 'open', 'kind' => 2]);
		$guard = $this->guard();
		$neq = [['field' => 'status', 'from' => 'excluded', 'op' => 'neq']];
		$in = [['field' => 'kind', 'from' => 'kinds', 'op' => 'in']];
		$outcomes = [];
		foreach ([[['excluded' => 'closed'], $neq], [['excluded' => 'open'], $neq], [['kinds' => [1, 2]], $in], [['kinds' => [3]], $in], [['wanted' => ['open']], [['field' => 'status', 'from' => 'wanted']]]] as [$data, $condition]) {
			try {
				$guard->assertMatches($this->propertyRun(data: $data, condition: $condition));
				$outcomes[] = 'pass';
			} catch (ReferenceValidationException $e) {
				$outcomes[] = 'refused';
			}
		}

		$this->assertSame(['pass', 'refused', 'pass', 'refused', 'refused'], $outcomes, 'an eq operand that is itself a list never matches');
	}//end testNeqAndInOperators()

	/**
	 * A target register is resolved through the lookups and passed to the read.
	 *
	 * @return void
	 */
	public function testATargetRegisterIsPassedToTheRead(): void {
		$register = new Register();
		$register->setId(9);
		$this->registers->expects($this->once())->method('find')->with(9)->willReturn($register);
		$object = new ObjectEntity();
		$object->setObject(['organisation' => 'org-1']);
		$this->mapper->expects($this->once())->method('find')->with('c-1', $this->identicalTo($register))->willReturn($object);
		$run = $this->propertyRun(data: ['organisation' => 'org-1']);
		$run->targetRegister = '9';

		$this->guard()->assertMatches($run);
	}//end testATargetRegisterIsPassedToTheRead()

	/**
	 * An unknown target schema, an unreadable object, or a read that throws leaves existence to its own check: no refusal.
	 *
	 * @return void
	 */
	public function testAnUnreadableReferenceIsNotRefusedHere(): void {
		$run = $this->propertyRun(data: ['organisation' => 'org-2']);
		$run->ref = 'unknown-schema';
		$this->mapper->expects($this->exactly(2))->method('find')
			->willReturnOnConsecutiveCalls(null, $this->throwException(new RuntimeException('gone')));
		$guard = $this->guard();

		$guard->assertMatches($run);
		$guard->assertMatches($this->propertyRun(data: ['organisation' => 'org-2']));
		$guard->assertMatches($this->propertyRun(data: ['organisation' => 'org-2']));
	}//end testAnUnreadableReferenceIsNotRefusedHere()
}//end class
