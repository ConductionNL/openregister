<?php

/**
 * What a watcher row says when the API returns it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Notifications\Db
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notifications\Db;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

use DateTime;
use OCA\OpenRegister\Notifications\Db\Watcher;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Notifications\Db\Watcher
 */
final class WatcherSerializationTest extends TestCase {

	public function testTheRowSaysWhoWatchesWhatAndSince(): void {
		$watcher = new Watcher();
		$watcher->setId(5);
		$watcher->setUserId('alice');
		$watcher->setObjectUuid('obj-1');
		$watcher->setRegister('r');
		$watcher->setSchema('s');
		$watcher->setNotify(false);
		$watcher->setCreated(new DateTime('2026-03-04T05:06:07+00:00'));

		$this->assertSame(
			[
				'id' => 5,
				'userId' => 'alice',
				'objectUuid' => 'obj-1',
				'register' => 'r',
				'schema' => 's',
				'notify' => false,
				'created' => '2026-03-04T05:06:07+00:00',
			],
			$watcher->jsonSerialize()
		);
	}//end testTheRowSaysWhoWatchesWhatAndSince()

	public function testAFreshRowNotifiesAndHasNoCreationTime(): void {
		$serialized = (new Watcher())->jsonSerialize();

		$this->assertTrue($serialized['notify']);
		$this->assertNull($serialized['created']);
		$this->assertNull($serialized['id']);
	}//end testAFreshRowNotifiesAndHasNoCreationTime()

	public function testAnUnsetNotifyStillNotifies(): void {
		$watcher = new Watcher();
		$watcher->setNotify(null);

		$this->assertTrue($watcher->notifies());
	}//end testAnUnsetNotifyStillNotifies()
}//end class
