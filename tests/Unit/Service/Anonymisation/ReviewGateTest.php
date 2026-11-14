<?php

/**
 * ReviewGateTest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Anonymisation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/redaction-release-safeguards/tasks.md#task-2.3
 */

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Anonymisation;

use DateTime;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Db\RedactionReviewMark;
use OCA\OpenRegister\Db\RedactionReviewMarkMapper;
use OCA\OpenRegister\Exception\ReviewRequiredException;
use OCA\OpenRegister\Kernel\Contracts\DocumentProcessingHandlerInterface;
use OCA\OpenRegister\Service\Anonymisation\RedactionReviewGate;
use OCA\OpenRegister\Service\Anonymisation\RedactionRunFingerprint;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Settings\FileSettingsHandler;
use OCP\Files\File;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * With review required, anonymised output waits for a decision on every detection and a matching mark.
 *
 * @covers \OCA\OpenRegister\Service\Anonymisation\RedactionReviewGate
 * @covers \OCA\OpenRegister\Service\Anonymisation\RedactionRunFingerprint
 * @covers \OCA\OpenRegister\Exception\ReviewRequiredException
 * @covers \OCA\OpenRegister\Service\FileService
 * @uses   \OCA\OpenRegister\Service\Settings\FileSettingsHandler
 * @uses   \OCA\OpenRegister\Db\RedactionReviewMark
 * @uses   \OCA\OpenRegister\Service\File\DocumentAnonymization
 */
class ReviewGateTest extends TestCase {

	private const JAN = ['relation_id' => 1, 'entity_type' => 'PERSON', 'entity_value' => 'Jan Jansen', 'position_start' => 10, 'redaction_decision' => 'redact'];

	private const BSN = ['relation_id' => 2, 'entity_type' => 'BSN', 'entity_value' => '123456782', 'position_start' => 40, 'redaction_decision' => 'release'];

	/**
	 * The gate over file 42's detections, with the latest mark on it and the setting as given.
	 *
	 * @param list<array<string, mixed>> $detections The current run.
	 */
	private function gate(array $detections, ?RedactionReviewMark $mark, bool $required = true): RedactionReviewGate {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn((string) json_encode(['anonymisation' => ['requireReview' => $required]]));
		$relations = $this->createMock(EntityRelationMapper::class);
		$relations->method('findDetectionsForReview')->with(42)->willReturn($detections);
		$marks = $this->createMock(RedactionReviewMarkMapper::class);
		$marks->method('findLatestForFile')->with(42)->willReturn($mark);

		return new RedactionReviewGate(settings: new FileSettingsHandler($appConfig), relations: $relations, marks: $marks);
	}//end gate()

	/**
	 * A mark by j.devries on 2026-10-01 over these detections.
	 *
	 * @param list<array<string, mixed>> $detections The run that was checked.
	 */
	private static function mark(array $detections): RedactionReviewMark {
		$mark = new RedactionReviewMark();
		$mark->setFileId(42);
		$mark->setRunFingerprint(RedactionRunFingerprint::of(detections: $detections));
		$mark->setCheckedBy('j.devries');
		$mark->setCheckedAt(new DateTime('2026-10-01T09:00:00+00:00'));
		return $mark;
	}//end mark()

	private function refusal(RedactionReviewGate $gate): ReviewRequiredException {
		try {
			$gate->assertMayAnonymise(fileId: 42);
		} catch (ReviewRequiredException $e) {
			return $e;
		}

		$this->fail('The gate let the file through');
	}//end refusal()

	public function testADirectServiceCallIsRefusedWithoutWriting(): void {
		$handler = $this->createMock(DocumentProcessingHandlerInterface::class);
		$handler->expects($this->never())->method('anonymize');

		$service = $this->getMockBuilder(FileService::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
		(new ReflectionProperty(FileService::class, 'documentProcessingHandler'))->setValue($service, $handler);
		(new ReflectionProperty(FileService::class, 'reviewGate'))->setValue($service, $this->gate(detections: [self::JAN], mark: null));

		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn(42);

		try {
			$service->anonymizeDocument(node: $node, entities: [['text' => 'Jan Jansen', 'entityType' => 'PERSON', 'key' => 'p1']]);
			$this->fail('A direct call wrote an unreviewed file');
		} catch (ReviewRequiredException $e) {
			$this->assertSame(
				['error' => 'review-required', 'reason' => 'no-mark', 'undecided' => 0, 'message' => $e->getMessage()],
				$e->toRefusal()
			);
			$this->assertStringContainsString('must be checked', $e->getMessage());
		}
	}//end testADirectServiceCallIsRefusedWithoutWriting()

	public function testAnUndecidedEntityRefuses(): void {
		$undecided = ['redaction_decision' => 'undecided'] + self::BSN;
		$e = $this->refusal($this->gate(detections: [self::JAN, $undecided], mark: self::mark(detections: [self::JAN, $undecided])));

		$this->assertSame('undecided-entities', $e->reason);
		$this->assertSame(1, $e->undecided);
		$this->assertStringContainsString('1 of its detections have no decision', $e->getMessage());
	}//end testAnUndecidedEntityRefuses()

	public function testADifferentRunMakesTheMarkStale(): void {
		$newName = ['relation_id' => 3, 'entity_type' => 'PERSON', 'entity_value' => 'Piet Pietersen', 'position_start' => 80, 'redaction_decision' => 'redact'];
		$e = $this->refusal($this->gate(detections: [self::JAN, self::BSN, $newName], mark: self::mark(detections: [self::JAN, self::BSN])));

		$this->assertSame('stale-mark', $e->reason);
		$this->assertSame('This document must be checked again: it was checked by j.devries on 2026-10-01, before the detection changed.', $e->getMessage());
	}//end testADifferentRunMakesTheMarkStale()

	public function testTheSameFindingsInAnotherOrderKeepTheMark(): void {
		$this->gate(detections: [self::BSN, self::JAN], mark: self::mark(detections: [self::JAN, self::BSN]))->assertMayAnonymise(fileId: 42);

		// A changed decision is not a changed finding: the mark still holds.
		$this->gate(detections: [['redaction_decision' => 'release'] + self::JAN, self::BSN], mark: self::mark(detections: [self::JAN, self::BSN]))->assertMayAnonymise(fileId: 42);
		$this->addToAssertionCount(2);
	}//end testTheSameFindingsInAnotherOrderKeepTheMark()

	public function testWithTheSettingOffNothingChanges(): void {
		$this->gate(detections: [['redaction_decision' => 'undecided'] + self::JAN], mark: null, required: false)->assertMayAnonymise(fileId: 42);
		$this->addToAssertionCount(1);
	}//end testWithTheSettingOffNothingChanges()
}//end class
