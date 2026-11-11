<?php

/**
 * ObjectWriteMatcher: a match that fills the scan limit is reported as that many or more, never as an exact count.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteMatcher;
use OCA\OpenRegister\Service\Flow\Nodes\ObjectWritePlan;
use OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteTemplate;
use OCP\IL10N;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteMatcher
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteMatchFilters
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\ObjectWritePlan
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteTemplate
 */
class ObjectWriteMatcherScanLimitBehaviourTest extends TestCase {

	private function matcher(int $rows): ObjectWriteMatcher {
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('runAs')->willReturnCallback(static fn (IUser $user, callable $operation) => $operation());
		$objects->method('findAll')->willReturn(array_map(static fn (int $n): ObjectEntity => new ObjectEntity(), range(1, $rows)));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));

		return new ObjectWriteMatcher($objects, new ObjectWriteTemplate(), $l10n);
	}//end matcher()

	private function plan(): ObjectWritePlan {
		$register = new Register();
		$register->setId(1);
		$schema = new Schema();
		$schema->setId(2);
		$schema->setProperties(['sourceId' => ['type' => 'string']]);
		$plan = new ObjectWritePlan(config: [], owner: $this->createMock(IUser::class), register: $register, schema: $schema);
		$plan->pairs = [['property' => 'sourceId', 'value' => '{{ id }}']];

		return $plan;
	}//end plan()

	public function testAFullScanIsReportedAsThatManyOrMore(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The match resolved to 100 or more objects; it must resolve to exactly one.');

		$this->matcher(rows: 100)->find($this->plan(), ['id' => 'r1']);
	}//end testAFullScanIsReportedAsThatManyOrMore()

	public function testBelowTheLimitTheExactCountIsReported(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The match resolved to 99 objects; it must resolve to exactly one.');

		$this->matcher(rows: 99)->find($this->plan(), ['id' => 'r1']);
	}//end testBelowTheLimitTheExactCountIsReported()
}//end class
