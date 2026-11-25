<?php

/**
 * A refused flow signal and what it carries.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Exception
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/unit-test-coverage/specs/unit-test-coverage/spec.md#requirement-test-all-exception-and-format-classes
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Exception;

use OCA\OpenRegister\Exception\FlowSignalRefused;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FlowSignalRefused::class)]
final class FlowSignalRefusedTest extends TestCase {

	public function testItCarriesTheReasonRunAndActor(): void {
		$refusal = new FlowSignalRefused(
			reason: FlowSignalRefused::NOT_ASSIGNEE,
			message: 'Only the assignee may signal this step.',
			runUuid: 'run-1',
			actorUid: 'bob'
		);

		self::assertSame('not-assignee', $refusal->getReason());
		self::assertSame('Only the assignee may signal this step.', $refusal->getMessage());
		self::assertSame('run-1', $refusal->getRunUuid());
		self::assertSame('bob', $refusal->getActorUid());
	}

	public function testWithoutRunOrActorTheyAreEmpty(): void {
		$refusal = new FlowSignalRefused(reason: FlowSignalRefused::RUN_NOT_FOUND, message: 'No such run.');

		self::assertSame('run-not-found', $refusal->getReason());
		self::assertSame('', $refusal->getRunUuid());
		self::assertNull($refusal->getActorUid());
		self::assertInstanceOf(\RuntimeException::class, $refusal);
	}

	public function testTheReasonsAreDistinct(): void {
		$reasons = [FlowSignalRefused::RUN_NOT_FOUND, FlowSignalRefused::NOT_ASSIGNEE, FlowSignalRefused::NOT_SUSPENDED];

		self::assertSame($reasons, array_values(array_unique($reasons)));
		self::assertSame('not-suspended', FlowSignalRefused::NOT_SUSPENDED);
	}
}
