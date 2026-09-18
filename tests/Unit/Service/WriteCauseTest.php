<?php

/**
 * Why a write happened, in words a filter can use.
 *
 * 🔴 THE CLOSED VOCABULARY IS A SECURITY PROPERTY, NOT TIDINESS. A client that
 * can claim its write was a `migration` can hide a write: an administrator
 * filtering out the noise of a bulk load would filter out exactly the entry
 * somebody wanted buried. So a value outside the six is not stored, and the
 * test for that asserts the STORED value rather than that a call was refused —
 * an implementation that passed the word through and logged a warning would
 * satisfy a "was it rejected" test and still poison the filter.
 *
 * 🔴 THE STACK IS NOT A SINGLE VALUE. An import that fires a rule that cascades
 * is three causes deep, and the entry a write produces is caused by the
 * INNERMOST one. Flattening it would make the cascade inside an import read as
 * an import, and the import would then appear to have written rows it never
 * touched — a wrong answer that looks like a right one.
 *
 * 🔑 THE FRAME IS POPPED IN `finally`. A frame left behind by a throwing
 * operation labels every later write in the same request, and the request reads
 * as one long import. Its own test, because nothing else would notice.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\WriteCause;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\WriteCause
 *
 * @spec openspec/changes/runs-recorded-and-causes-named/specs/enhanced-audit-trail/spec.md
 */
final class WriteCauseTest extends TestCase {

	/**
	 * An ambient stack is shared, so every test starts from empty.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		WriteCause::reset();
	}

	/**
	 * And leaves nothing behind for the next one.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		WriteCause::reset();
		parent::tearDown();
	}

	/**
	 * 🔴 An unlabelled write is somebody's.
	 *
	 * Assuming otherwise would let a real person's change read as machinery,
	 * which is the direction that hides things.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/enhanced-audit-trail/spec.md#requirement-every-audit-entry-names-the-cause-of-the-write-req-rcn-001
	 */
	public function testAnUnlabelledWriteIsAPerson(): void {
		self::assertSame(
			['cause' => WriteCause::PERSON, 'run' => null],
			WriteCause::current()
		);
	}

	/**
	 * A frame names the cause and the run for the work inside it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/enhanced-audit-trail/spec.md#requirement-every-audit-entry-names-the-cause-of-the-write-req-rcn-001
	 */
	public function testAFrameNamesTheCauseAndTheRun(): void {
		$seen = WriteCause::as(
			WriteCause::IMPORT,
			'run-42',
			static fn (): array => WriteCause::current()
		);

		self::assertSame(['cause' => 'import', 'run' => 'run-42'], $seen);
		self::assertSame(
			WriteCause::PERSON,
			WriteCause::current()['cause'],
			'and the frame is gone afterwards'
		);
	}

	/**
	 * 🔴 The INNERMOST frame wins: a cascade inside an import is a cascade.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/enhanced-audit-trail/spec.md#requirement-every-audit-entry-names-the-cause-of-the-write-req-rcn-001
	 */
	public function testTheInnermostFrameWins(): void {
		$seen = WriteCause::as(
			WriteCause::IMPORT,
			'run-42',
			static fn (): array => WriteCause::as(
				WriteCause::CASCADE,
				'write-7',
				static fn (): array => WriteCause::current()
			)
		);

		self::assertSame(['cause' => 'cascade', 'run' => 'write-7'], $seen);
	}

	/**
	 * The outer frame is intact once the inner one returns.
	 *
	 * Without this, an implementation that simply replaced the value would pass
	 * the test above and leave the rest of the import labelled `cascade`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/enhanced-audit-trail/spec.md#requirement-every-audit-entry-names-the-cause-of-the-write-req-rcn-001
	 */
	public function testTheOuterFrameSurvivesTheInnerOne(): void {
		$after = WriteCause::as(
			WriteCause::IMPORT,
			'run-42',
			static function (): array {
				WriteCause::as(WriteCause::CASCADE, 'write-7', static fn (): bool => true);

				return WriteCause::current();
			}
		);

		self::assertSame(['cause' => 'import', 'run' => 'run-42'], $after);
	}

	/**
	 * 🔴 A throwing operation does not leave its frame behind.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/enhanced-audit-trail/spec.md#requirement-every-audit-entry-names-the-cause-of-the-write-req-rcn-001
	 */
	public function testAThrowingOperationDoesNotStrandItsFrame(): void {
		try {
			WriteCause::as(
				WriteCause::IMPORT,
				'run-42',
				static function (): void {
					throw new RuntimeException('the load failed halfway');
				}
			);
		} catch (RuntimeException $e) {
			// Expected; the assertion is what the stack looks like afterwards.
		}

		self::assertSame(
			WriteCause::PERSON,
			WriteCause::current()['cause'],
			'a stranded frame labels every later write in the request'
		);
	}

	/**
	 * 🔴 A word outside the vocabulary is not STORED, not merely refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/enhanced-audit-trail/spec.md#requirement-every-audit-entry-names-the-cause-of-the-write-req-rcn-001
	 */
	public function testAWordOutsideTheVocabularyIsNotStored(): void {
		$seen = WriteCause::as(
			'IMPORT-2026-batch-3',
			'run-42',
			static fn (): array => WriteCause::current()
		);

		self::assertSame(
			WriteCause::PERSON,
			$seen['cause'],
			'storing an undeclared word is how a closed vocabulary stops being closed'
		);
		// The RUN still travels: the caller's own identifier is not the thing
		// being policed, the vocabulary is.
		self::assertSame('run-42', $seen['run']);
	}

	/**
	 * The vocabulary is six words and normalisation is case-insensitive.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/enhanced-audit-trail/spec.md#requirement-every-audit-entry-names-the-cause-of-the-write-req-rcn-001
	 */
	public function testTheVocabularyIsSixWords(): void {
		self::assertCount(6, WriteCause::ALL);
		self::assertSame(WriteCause::MIGRATION, WriteCause::normalise(cause: ' Migration '));
		self::assertSame(WriteCause::PERSON, WriteCause::normalise(cause: 'whatever'));
	}

	/**
	 * 🔴 A client that tried to name its own cause is recorded.
	 *
	 * A caller trying to label its own writes is itself worth knowing about.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/enhanced-audit-trail/spec.md#requirement-every-audit-entry-names-the-cause-of-the-write-req-rcn-001
	 */
	public function testAClientAttemptIsRecorded(): void {
		self::assertFalse(WriteCause::clientAttempted());

		WriteCause::noteClientAttempt();

		self::assertTrue(WriteCause::clientAttempted());
	}
}//end class
