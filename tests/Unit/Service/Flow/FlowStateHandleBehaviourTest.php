<?php

/**
 * FlowStateHandle tracks which keys a run wrote and whether the state changed,
 * so only a changed state is persisted.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 * @author   Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowStateHandle;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowStateHandle
 */
class FlowStateHandleBehaviourTest extends TestCase {
	/**
	 * Written keys list only keys set in this run that still exist, as strings.
	 *
	 * @return void
	 */
	public function testWrittenKeysTrackSetKeysStillPresent(): void {
		$handle = new FlowStateHandle(['loaded' => 1]);
		$handle->set('a', 1);
		$handle->set('7', 2);
		$handle->set('gone', 3);
		$handle->forget('gone');

		$this->assertSame(['a', '7'], $handle->writtenKeys());
		$this->assertTrue($handle->isDirty());
	}

	/**
	 * Forgetting a stored key removes it and dirties; forgetting an absent one does neither.
	 *
	 * @return void
	 */
	public function testForget(): void {
		$handle = new FlowStateHandle(['a' => null]);
		$handle->forget('missing');
		$this->assertFalse($handle->isDirty());
		$this->assertTrue($handle->has('a'));

		$handle->forget('a');
		$this->assertTrue($handle->isDirty());
		$this->assertFalse($handle->has('a'));
		$this->assertSame([], $handle->writtenKeys());
	}

	/**
	 * Get falls back to the default, serialisation gives the values, null construction is empty.
	 *
	 * @return void
	 */
	public function testReadsAndSerialisation(): void {
		$handle = new FlowStateHandle(['a' => 1]);
		$this->assertSame(1, $handle->get('a'));
		$this->assertSame('d', $handle->get('b', 'd'));
		$this->assertSame('{"a":1}', json_encode($handle));
		$this->assertSame([], (new FlowStateHandle(null))->all());
	}
}
