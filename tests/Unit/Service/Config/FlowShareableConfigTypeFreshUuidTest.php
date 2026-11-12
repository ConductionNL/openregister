<?php

/**
 * An imported flow gets a fresh v4 uuid of its own.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Config
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Config;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Service\Config\Types\FlowShareableConfigType;
use OCA\OpenRegister\Service\Flow\FlowCaller;
use OCA\OpenRegister\Service\Flow\FlowService;
use PHPUnit\Framework\TestCase;

/**
 * Two flows in one bundle, both carrying the sender's uuid, are installed
 * under two different, well-formed v4 uuids, neither of them the sender's.
 */
class FlowShareableConfigTypeFreshUuidTest extends TestCase {
	/**
	 * Each imported flow gets its own fresh v4 uuid.
	 *
	 * @return void
	 */
	public function testEachImportedFlowGetsAFreshV4Uuid(): void {
		$mapper = $this->createMock(FlowMapper::class);
		$mapper->method('insert')->willReturnCallback(static fn (Flow $flow): Flow => $flow);
		$caller = $this->createMock(FlowCaller::class);
		$caller->method('ownership')->willReturn(['owner' => 'installer', 'organisation' => 'org-here']);
		$type = new FlowShareableConfigType($mapper, $this->createMock(FlowService::class), $caller);

		$incoming = ['uuid' => 'sender-uuid', 'name' => 'Imported', 'trigger' => 'manual', 'nodes' => [], 'edges' => []];
		$installed = $type->deserialise(['type' => 'openregister.flows', 'flows' => [$incoming, $incoming]])['installed'];

		$this->assertCount(2, $installed);
		$this->assertNotSame($installed[0], $installed[1]);
		foreach ($installed as $uuid) {
			$this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid);
		}
	}//end testEachImportedFlowGetsAFreshV4Uuid()
}//end class
