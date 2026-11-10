<?php

/**
 * FlowSubjectItems turns a trigger subject into the first item of a run and
 * refreshes the subject's projection on exactly the items that carry it.
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

use OCA\OpenRegister\Service\Flow\FlowSubjectItems;
use OCA\OpenRegister\Service\Flow\LiveSubject;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowSubjectItems
 * @uses   \OCA\OpenRegister\Service\Flow\FlowItemShape
 */
class FlowSubjectItemsTest extends TestCase {
	/**
	 * A serialisable subject is read through jsonSerialize, ahead of getObject.
	 *
	 * @return void
	 */
	public function testSerialisableSubject(): void {
		$subject = new class implements \JsonSerializable {
			/**
			 * The record.
			 *
			 * @return array
			 */
			public function jsonSerialize(): array {
				return ['id' => 'a', 'via' => 'json'];
			}

			/**
			 * A record that must not be used.
			 *
			 * @return array
			 */
			public function getObject(): array {
				return ['via' => 'object'];
			}
		};
		$this->assertSame([['json' => ['id' => 'a', 'via' => 'json'], 'binary' => [], 'pairedItem' => null]], FlowSubjectItems::fromSubject($subject));
	}

	/**
	 * A subject with getObject only is read through it.
	 *
	 * @return void
	 */
	public function testObjectSubject(): void {
		$subject = new class {
			/**
			 * The record.
			 *
			 * @return array
			 */
			public function getObject(): array {
				return ['via' => 'object'];
			}
		};
		$this->assertSame(['via' => 'object'], FlowSubjectItems::fromSubject($subject)[0]['json']);
	}

	/**
	 * A plain object is read through its public properties.
	 *
	 * @return void
	 */
	public function testPlainSubject(): void {
		$subject       = new stdClass();
		$subject->name = 'n';
		$this->assertSame(['name' => 'n'], FlowSubjectItems::fromSubject($subject)[0]['json']);
	}

	/**
	 * The live record is merged into items whose @self id, id or uuid is the subject, and only those.
	 *
	 * @return void
	 */
	public function testRefreshMergesOnlySubjectItems(): void {
		$subject         = new stdClass();
		$subject->status = 'closed';
		$items           = [
			['json' => ['@self' => ['id' => ' s-1 '], 'status' => 'open']],
			['json' => ['id' => 's-1', 'status' => 'open', 'extra' => 1]],
			['json' => ['uuid' => 's-1', 'status' => 'open']],
			['json' => ['id' => 'other', 'status' => 'open']],
			['json' => ['@self' => ['id' => ''], 'id' => '  ', 'uuid' => 's-1', 'status' => 'open']],
			['json' => ['id' => 5, 'status' => 'open']],
			'not-an-item',
		];

		$out = FlowSubjectItems::refreshSubjectProjection($items, new LiveSubject($subject, ' s-1 '));

		$this->assertSame(
			['closed', 'closed', 'closed', 'open', 'closed', 'open'],
			array_map(static fn (array $i) => $i['json']['status'], array_slice($out, 0, 6))
		);
		$this->assertSame(1, $out[1]['json']['extra']);
		$this->assertSame('not-an-item', $out[6]);
	}

	/**
	 * A blank subject uuid, no items, or an empty live record leaves the items as they are.
	 *
	 * @return void
	 */
	public function testRefreshNoOps(): void {
		$subject         = new stdClass();
		$subject->status = 'closed';
		$items           = [['json' => ['id' => 's-1', 'status' => 'open']]];

		$this->assertSame($items, FlowSubjectItems::refreshSubjectProjection($items, new LiveSubject($subject, '  ')));
		$this->assertSame([], FlowSubjectItems::refreshSubjectProjection([], new LiveSubject($subject, 's-1')));
		$this->assertSame($items, FlowSubjectItems::refreshSubjectProjection($items, new LiveSubject(new stdClass(), 's-1')));
	}
}
