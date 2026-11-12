<?php

/**
 * The JSON form of a flow: its typed fields, then the API defaults — uuid as
 * id, a boolean enabled, version 1, draft, async, and empty graph parts.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\Flow;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use DateTimeImmutable;
use OCA\OpenRegister\Db\Flow\FlowJson;
use PHPUnit\Framework\TestCase;

/**
 * FlowJson.
 *
 * @covers \OCA\OpenRegister\Db\Flow\FlowJson
 * @uses \OCA\OpenRegister\Db\TypedFieldsJson
 */
class FlowJsonTest extends TestCase {

	private const TYPES = ['uuid' => 'string', 'name' => 'string', 'enabled' => 'boolean', 'created' => 'datetime'];

	public function testAMinimalFlowGetsTheDefaults(): void {
		$json = (new FlowJson())->serialise(self::TYPES, ['uuid' => 'f-1', 'name' => 'Intake', 'enabled' => 0, 'created' => null]);

		$this->assertSame(
			[
				'uuid' => 'f-1',
				'name' => 'Intake',
				'enabled' => false,
				'created' => null,
				'id' => 'f-1',
				'version' => 1,
				'lifecycleStatus' => 'draft',
				'executionMode' => 'async',
				'nodes' => [],
				'edges' => [],
				'annotations' => [],
				'limits' => [],
			],
			$json
		);
	}//end testAMinimalFlowGetsTheDefaults()

	public function testStoredValuesOverrideTheDefaults(): void {
		$json = (new FlowJson())->serialise(
			self::TYPES,
			[
				'uuid' => 'f-2',
				'enabled' => '1',
				'created' => new DateTimeImmutable('2026-01-02T03:04:05+00:00'),
				'version' => '4',
				'lifecycleStatus' => 'published',
				'executionMode' => 'sync',
				'nodes' => [['id' => 'n1']],
				'edges' => [['from' => 'n1', 'to' => 'n2']],
				'annotations' => [['text' => 'x']],
				'limits' => ['maxSteps' => 10],
			]
		);

		$this->assertNull($json['name']);
		$this->assertSame('2026-01-02T03:04:05+00:00', $json['created']);
		$this->assertTrue($json['enabled']);
		$this->assertSame(4, $json['version']);
		$this->assertSame('published', $json['lifecycleStatus']);
		$this->assertSame('sync', $json['executionMode']);
		$this->assertSame([['id' => 'n1']], $json['nodes']);
		$this->assertSame([['from' => 'n1', 'to' => 'n2']], $json['edges']);
		$this->assertSame([['text' => 'x']], $json['annotations']);
		$this->assertSame(['maxSteps' => 10], $json['limits']);
	}//end testStoredValuesOverrideTheDefaults()
}//end class
