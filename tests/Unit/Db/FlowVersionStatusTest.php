<?php

/**
 * FlowVersionStatusTest: whether a flow version may back a run or still be edited.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit mock helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\FlowVersion;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\FlowVersion
 */
class FlowVersionStatusTest extends TestCase {

	/**
	 * A version in a status.
	 *
	 * @param string|null $status The status.
	 *
	 * @return FlowVersion
	 */
	private static function version(?string $status): FlowVersion {
		$version = new FlowVersion();
		if ($status !== null) {
			$version->setStatus($status);
		}

		return $version;
	}//end version()

	public function testOnlyAPublishedVersionMayBackARun(): void {
		$this->assertTrue(self::version(FlowVersion::STATUS_PUBLISHED)->isPublished());
		$this->assertFalse(self::version(FlowVersion::STATUS_DRAFT)->isPublished());
		$this->assertFalse(self::version(FlowVersion::STATUS_DEPRECATED)->isPublished());
	}//end testOnlyAPublishedVersionMayBackARun()

	public function testOnlyADraftMayBeEdited(): void {
		$this->assertTrue(self::version(FlowVersion::STATUS_DRAFT)->isDraft());
		$this->assertFalse(self::version(FlowVersion::STATUS_PUBLISHED)->isDraft());
		$this->assertFalse(self::version(FlowVersion::STATUS_DEPRECATED)->isDraft());
	}//end testOnlyADraftMayBeEdited()
}//end class
