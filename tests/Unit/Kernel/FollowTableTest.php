<?php

/**
 * The follow table is named once, by the Core contract the personal lens joins on.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Kernel
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Kernel;

use OCA\OpenRegister\Kernel\Contracts\Core\FollowTableInterface;
use OCA\OpenRegister\Notifications\Db\WatcherMapper;
use OCA\OpenRegister\Notifications\Db\WatcherMapperInterface;
use PHPUnit\Framework\TestCase;

/**
 * The watcher mapper's table is the Core-owned follow table name.
 *
 * @coversNothing
 */
final class FollowTableTest extends TestCase {

	public function testTheFollowTableKeepsItsName(): void {
		$this->assertSame('openregister_watchers', FollowTableInterface::TABLE);
	}

	public function testTheWatcherMapperUsesTheCoreName(): void {
		$this->assertSame(FollowTableInterface::TABLE, WatcherMapperInterface::TABLE);
		$this->assertSame(FollowTableInterface::TABLE, WatcherMapper::TABLE);
	}
}//end class
