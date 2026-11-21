<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Service\Rbac\ClassificationScale;
use OCA\OpenRegister\Service\Rbac\ClearanceResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A principal's clearance from declared group mappings.
 *
 * @covers \OCA\OpenRegister\Service\Rbac\ClearanceResolver
 * @uses   \OCA\OpenRegister\Service\Rbac\ClassificationScale
 */
final class ClearanceResolverTest extends TestCase {

	/**
	 * A ZGW scale with two group mappings.
	 *
	 * @param array<string, mixed> $extra Extra block entries.
	 *
	 * @return ClassificationScale
	 */
	private static function scale(array $extra=[]): ClassificationScale {
		return ClassificationScaleTest::zgw(
			$extra + ['clearances' => [['group' => 'medewerkers', 'level' => 'intern'], ['group' => 'recherche', 'level' => 'geheim'], ['group' => 'stale', 'level' => 'gone']]]
		);
	}

	public function testTheHighestMappedGroupWins(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');
		$resolver = new ClearanceResolver($logger);
		$this->assertSame('geheim', $resolver->resolve(self::scale(), 'anna', ['medewerkers', 'recherche']));
		$this->assertSame('geheim', $resolver->resolve(self::scale(), 'anna', ['recherche', 'medewerkers']));
		$this->assertSame('intern', $resolver->resolve(self::scale(), 'bob', ['medewerkers', 'stale']));
	}

	public function testAnUnmappedUserTakesTheDeclaredDefaultAndIsLogged(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->logicalAnd(
			$this->stringContains('"carol"'),
			$this->stringContains('"intern"')
		));
		$this->assertSame('intern', (new ClearanceResolver($logger))->resolve(self::scale(['default' => 'intern']), 'carol', ['other']));
	}

	public function testWithoutADefaultTheLowestLevelIsTaken(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('"anonymous"'));
		$this->assertSame('openbaar', (new ClearanceResolver($logger))->resolve(self::scale(), null, []));
	}

	public function testAScaleWithNoMappingsIsTheDefaultWithoutNoise(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');
		$this->assertSame('openbaar', (new ClearanceResolver($logger))->resolve(ClassificationScaleTest::zgw(), 'dave', ['recherche']));
	}

	public function testAnAdministratorTakesTheTopLevel(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');
		$this->assertSame('zeer_geheim', (new ClearanceResolver($logger))->forAdministrator(self::scale()));
	}
}
