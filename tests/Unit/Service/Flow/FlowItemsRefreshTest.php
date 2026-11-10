<?php

/**
 * The subject-projection refresh on stored items.
 *
 * The seam between "items survive the pause" (flow-runs REQ-FR-003) and
 * "a resumed step reads the subject as it stands": only the item that IS the
 * subject is touched, and on it the live subject's fields win while the keys
 * earlier steps produced survive.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowItems;
use PHPUnit\Framework\TestCase;

/**
 * Branch coverage for {@see FlowItems::refreshSubjectProjection()}.
 *
 * @covers \OCA\OpenRegister\Service\Flow\FlowItems
 */
class FlowItemsRefreshTest extends TestCase {

	/**
	 * A subject serialising like ObjectEntity: identity under `@self`.
	 *
	 * @param array $fields The serialised fields.
	 *
	 * @return object The subject.
	 */
	private function subject(array $fields): object {
		return new class ($fields) {
			public function __construct(
				private readonly array $fields,
			) {
			}

			public function jsonSerialize(): array {
				return $this->fields;
			}
		};
	}//end subject()

	/**
	 * The live subject's fields win on the subject item; step keys survive;
	 * an item carrying another object's identity is untouched.
	 *
	 * @return void
	 */
	public function testLiveFieldsWinAndStepKeysSurviveOnTheSubjectItemOnly(): void {
		$items = [
			FlowItems::item(json: ['@self' => ['id' => 'case-1'], 'id' => 'case-1', 'description' => null, 'stepProduced' => 'kept']),
			FlowItems::item(json: ['@self' => ['id' => 'other-9'], 'id' => 'other-9', 'description' => 'theirs']),
		];

		$refreshed = FlowItems::refreshSubjectProjection(
			items: $items,
			subject: $this->subject(fields: ['@self' => ['id' => 'case-1'], 'id' => 'case-1', 'description' => 'now supplied']),
			subjectUuid: 'case-1'
		);

		$this->assertSame('now supplied', $refreshed[0]['json']['description']);
		$this->assertSame('kept', $refreshed[0]['json']['stepProduced']);
		$this->assertSame('theirs', $refreshed[1]['json']['description'], 'another object\'s item is never smeared');
	}//end testLiveFieldsWinAndStepKeysSurviveOnTheSubjectItemOnly()

	/**
	 * Identity falls back to a flat `id`, then a flat `uuid`; an item with no
	 * identity at all is never treated as the subject.
	 *
	 * @return void
	 */
	public function testIdentityFallsBackToFlatKeysAndAbsenceMatchesNothing(): void {
		$items = [
			FlowItems::item(json: ['id' => 'case-1', 'x' => 'old']),
			FlowItems::item(json: ['uuid' => 'case-1', 'x' => 'old']),
			FlowItems::item(json: ['x' => 'old']),
		];

		$refreshed = FlowItems::refreshSubjectProjection(
			items: $items,
			subject: $this->subject(fields: ['x' => 'new']),
			subjectUuid: 'case-1'
		);

		$this->assertSame('new', $refreshed[0]['json']['x'], 'flat id matches');
		$this->assertSame('new', $refreshed[1]['json']['x'], 'flat uuid matches');
		$this->assertSame('old', $refreshed[2]['json']['x'], 'no identity, no refresh');
	}//end testIdentityFallsBackToFlatKeysAndAbsenceMatchesNothing()

	/**
	 * The no-ops: an empty subject uuid, an empty item list, a subject that
	 * serialises to nothing, and a non-array member all pass through.
	 *
	 * @return void
	 */
	public function testTheNoOpBranchesPassThrough(): void {
		$item = FlowItems::item(json: ['id' => 'case-1', 'x' => 'old']);

		$this->assertSame(
			[$item],
			FlowItems::refreshSubjectProjection(items: [$item], subject: $this->subject(fields: ['x' => 'new']), subjectUuid: '  '),
			'no subject uuid, no refresh'
		);
		$this->assertSame(
			[],
			FlowItems::refreshSubjectProjection(items: [], subject: $this->subject(fields: ['x' => 'new']), subjectUuid: 'case-1'),
			'nothing to refresh'
		);
		$this->assertSame(
			[$item],
			FlowItems::refreshSubjectProjection(items: [$item], subject: new \stdClass(), subjectUuid: 'case-1'),
			'a subject with no fields refreshes nothing'
		);
		$this->assertSame(
			['junk'],
			FlowItems::refreshSubjectProjection(items: ['junk'], subject: $this->subject(fields: ['x' => 'new']), subjectUuid: 'case-1'),
			'a non-array member passes through'
		);
	}//end testTheNoOpBranchesPassThrough()
	/**
	 * A subject exposing only getObject() (an ObjectEntity-like store shape)
	 * still yields its fields for the refresh.
	 *
	 * @return void
	 */
	public function testASubjectExposingGetObjectIsRead(): void {
		$subject = new class () {
			public function getObject(): array {
				return ['id' => 'case-1', 'x' => 'new'];
			}
		};

		$refreshed = FlowItems::refreshSubjectProjection(
			items: [FlowItems::item(json: ['id' => 'case-1', 'x' => 'old'])],
			subject: $subject,
			subjectUuid: 'case-1'
		);

		$this->assertSame('new', $refreshed[0]['json']['x']);
	}//end testASubjectExposingGetObjectIsRead()
}//end class
