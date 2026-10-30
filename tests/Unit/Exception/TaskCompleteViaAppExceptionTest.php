<?php

/**
 * Unit test for the refusal of a completion past the app a task names.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/a-portal-task-names-the-app-that-completes-it/specs/flow-portal-task/spec.md#requirement-a-task-may-name-the-app-action-that-completes-it
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Exception;

use OCA\OpenRegister\Exception\TaskCompleteViaAppException;
use OCA\OpenRegister\Exception\TaskConflictException;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see TaskCompleteViaAppException}.
 *
 * @covers \OCA\OpenRegister\Exception\TaskCompleteViaAppException
 */
class TaskCompleteViaAppExceptionTest extends TestCase {

	/**
	 * It is a conflict, names the action in its message, and hands the route back.
	 *
	 * @return void
	 */
	public function testItNamesTheAppAndTheAction(): void {
		$refusal = new TaskCompleteViaAppException(app: 'dossiq', action: 'completeChecklistTask');

		$this->assertInstanceOf(TaskConflictException::class, $refusal);
		$this->assertStringContainsString('completeChecklistTask', $refusal->getMessage());
		$this->assertStringContainsString('dossiq', $refusal->getMessage());
		$this->assertSame(['app' => 'dossiq', 'action' => 'completeChecklistTask'], $refusal->completeVia());
	}//end testItNamesTheAppAndTheAction()
}//end class
