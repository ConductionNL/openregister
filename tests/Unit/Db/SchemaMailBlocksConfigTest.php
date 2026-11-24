<?php

/**
 * Schema mailBlocks Config Round-Trip Test
 *
 * `mailBlocks` splits a schema into several Mail-panel blocks. It must survive
 * hydrate like any other kept configuration key, or the panel never sees it.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/mail-sidebar/spec.md
 */

declare(strict_types=1);

namespace Unit\Db;

use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\TestCase;

/**
 * The mailBlocks configuration key is kept.
 */
class SchemaMailBlocksConfigTest extends TestCase {

	/**
	 * A `mailBlocks` list supplied under `configuration` survives hydrate unchanged.
	 *
	 * @return void
	 */
	public function testMailBlocksSurviveHydrate(): void {
		$blocks = [
			['key' => 'case'],
			[
				'key' => 'queue',
				'title' => 'Work queue',
				'name' => 'work queue item',
				'template' => ['title' => '{{subject}}', 'assignee' => ''],
				'filter' => ['assignee' => 'IS NULL'],
				'appDialog' => false,
			],
		];

		$schema = new Schema();
		$schema->hydrate(
			object: [
				'title' => 'Case',
				'properties' => ['title' => ['type' => 'string']],
				'configuration' => ['linkedTypes' => ['mail'], 'mailBlocks' => $blocks],
			]
		);

		$this->assertSame(expected: $blocks, actual: ($schema->getConfiguration()['mailBlocks'] ?? null));
	}//end testMailBlocksSurviveHydrate()
}//end class
