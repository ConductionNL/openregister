<?php

/**
 * The soft-uniqueness alert.
 *
 * Covers the warning on create and on update, the object not colliding with
 * itself, and the case the spec is most particular about: a holder the caller
 * may not read is reported as existing and never named.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Quality
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/duplicate-merge-and-dismissed-pairs/specs/duplicate-detection/spec.md#requirement-a-nominated-property-warns-when-its-value-already-exists-req-dmd-004
 */

declare(strict_types=1);

namespace Unit\Service\Quality;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Quality\UniqueHintAnnotationValidator;
use OCA\OpenRegister\Service\Quality\UniqueHintChecker;
use OCA\OpenRegister\Service\Quality\UniqueHintWarnings;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class UniqueHintCheckerTest extends TestCase {

	/**
	 * Object read path.
	 *
	 * @var ObjectService&MockObject
	 */
	private $objectService;

	/**
	 * The collector the controller drains.
	 *
	 * @var UniqueHintWarnings
	 */
	private UniqueHintWarnings $warnings;

	/**
	 * Checker under test.
	 *
	 * @var UniqueHintChecker
	 */
	private UniqueHintChecker $checker;

	/**
	 * Wire the checker over a real collector and validator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectService::class);
		$this->warnings = new UniqueHintWarnings();
		$this->checker = new UniqueHintChecker(
			$this->objectService,
			new UniqueHintAnnotationValidator(),
			$this->warnings,
			$this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * Build a stored object.
	 *
	 * @param string $uuid Object uuid.
	 *
	 * @return ObjectEntity
	 */
	private function stored(string $uuid): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setObject(['kvkNumber' => '69599084']);

		return $object;
	}//end stored()

	/**
	 * The schema configuration nominating `kvkNumber`.
	 *
	 * @return array<string, mixed>
	 */
	private function configuration(): array {
		return ['x-openregister-unique-hint' => ['kvkNumber']];
	}//end configuration()

	/**
	 * Answer the RBAC-scoped read with one list and the unscoped read with
	 * another, so the two can be told apart.
	 *
	 * @param array<int, ObjectEntity> $scoped What the caller may see.
	 * @param array<int, ObjectEntity> $unscoped What exists.
	 *
	 * @return void
	 */
	private function answerReads(array $scoped, array $unscoped): void {
		$this->objectService->method('findAll')->willReturnCallback(
			static function (...$args) use ($scoped, $unscoped): array {
				// findAll(query, _rbac, _multitenancy, ...) — the second
				// argument is the scoping flag this checker toggles.
				$rbac = ($args[1] ?? true);
				return ($rbac === true) ? $scoped : $unscoped;
			}
		);
	}//end answerReads()

	/**
	 * A second record on the same KvK number is noticed at once, and the
	 * warning names the record already holding it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/duplicate-merge-and-dismissed-pairs/specs/duplicate-detection/spec.md#requirement-a-nominated-property-warns-when-its-value-already-exists-req-dmd-004
	 */
	public function testASecondRecordOnTheSameValueWarns(): void {
		$this->answerReads([$this->stored('first')], [$this->stored('first')]);

		$this->checker->check($this->configuration(), 1, 1, ['kvkNumber' => '69599084']);

		$warnings = $this->warnings->drain();
		$this->assertCount(1, $warnings);
		$this->assertSame('kvkNumber', $warnings[0]['property']);
		$this->assertSame(['first'], $warnings[0]['matches']);
		$this->assertTrue($warnings[0]['visible']);
	}//end testASecondRecordOnTheSameValueWarns()

	/**
	 * The warning fires on an update too.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/duplicate-merge-and-dismissed-pairs/specs/duplicate-detection/spec.md#requirement-a-nominated-property-warns-when-its-value-already-exists-req-dmd-004
	 */
	public function testTheWarningFiresOnAnUpdate(): void {
		$this->answerReads([$this->stored('first')], [$this->stored('first')]);

		$this->checker->check($this->configuration(), 1, 1, ['kvkNumber' => '69599084'], 'second');

		$this->assertSame(['first'], $this->warnings->drain()[0]['matches']);
	}//end testTheWarningFiresOnAnUpdate()

	/**
	 * A record keeping the value it already holds does not collide with
	 * itself, so re-saving an unchanged record is silent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/duplicate-merge-and-dismissed-pairs/specs/duplicate-detection/spec.md#requirement-a-nominated-property-warns-when-its-value-already-exists-req-dmd-004
	 */
	public function testAnObjectDoesNotCollideWithItself(): void {
		$this->answerReads([$this->stored('first')], [$this->stored('first')]);

		$this->checker->check($this->configuration(), 1, 1, ['kvkNumber' => '69599084'], 'first');

		$this->assertSame([], $this->warnings->drain());
	}//end testAnObjectDoesNotCollideWithItself()

	/**
	 * A holder the caller may not read is reported as existing and is not
	 * named, so the alert works across a boundary without becoming a way to
	 * read across it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/duplicate-merge-and-dismissed-pairs/specs/duplicate-detection/spec.md#requirement-a-nominated-property-warns-when-its-value-already-exists-req-dmd-004
	 */
	public function testAnUnreadableHolderIsReportedWithoutBeingNamed(): void {
		$this->answerReads([], [$this->stored('hidden')]);

		$this->checker->check($this->configuration(), 1, 1, ['kvkNumber' => '69599084']);

		$warnings = $this->warnings->drain();
		$this->assertCount(1, $warnings);
		$this->assertFalse($warnings[0]['visible']);
		$this->assertSame([], $warnings[0]['matches']);
	}//end testAnUnreadableHolderIsReportedWithoutBeingNamed()

	/**
	 * A value nobody else holds is silent, which is the control that proves
	 * the assertions above are reporting a match and not a fixture.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/duplicate-merge-and-dismissed-pairs/specs/duplicate-detection/spec.md#requirement-a-nominated-property-warns-when-its-value-already-exists-req-dmd-004
	 */
	public function testAnUnheldValueIsSilent(): void {
		$this->answerReads([], []);

		$this->checker->check($this->configuration(), 1, 1, ['kvkNumber' => '00000000']);

		$this->assertSame([], $this->warnings->drain());
	}//end testAnUnheldValueIsSilent()

	/**
	 * A schema nominating nothing never reads the register.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/duplicate-merge-and-dismissed-pairs/specs/duplicate-detection/spec.md#requirement-a-nominated-property-warns-when-its-value-already-exists-req-dmd-004
	 */
	public function testNoNominationReadsNothing(): void {
		$this->objectService->expects($this->never())->method('findAll');

		$this->checker->check([], 1, 1, ['kvkNumber' => '69599084']);

		$this->assertSame([], $this->warnings->drain());
	}//end testNoNominationReadsNothing()

	/**
	 * An empty value is not a collision: every record leaving the field blank
	 * would otherwise warn about every other one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/duplicate-merge-and-dismissed-pairs/specs/duplicate-detection/spec.md#requirement-a-nominated-property-warns-when-its-value-already-exists-req-dmd-004
	 */
	public function testAnEmptyValueIsNotACollision(): void {
		$this->objectService->expects($this->never())->method('findAll');

		$this->checker->check($this->configuration(), 1, 1, ['kvkNumber' => '']);

		$this->assertSame([], $this->warnings->drain());
	}//end testAnEmptyValueIsNotACollision()

	/**
	 * The collector is drained on read, so a bulk save cannot hand row four
	 * hundred the warnings of the rows before it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/duplicate-merge-and-dismissed-pairs/specs/duplicate-detection/spec.md#requirement-a-nominated-property-warns-when-its-value-already-exists-req-dmd-004
	 */
	public function testWarningsAreDrainedOnRead(): void {
		$this->answerReads([$this->stored('first')], [$this->stored('first')]);

		$this->checker->check($this->configuration(), 1, 1, ['kvkNumber' => '69599084']);

		$this->assertCount(1, $this->warnings->drain());
		$this->assertSame([], $this->warnings->drain());
	}//end testWarningsAreDrainedOnRead()
}//end class
