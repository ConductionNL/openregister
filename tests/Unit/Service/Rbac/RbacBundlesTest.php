<?php

/**
 * The RBAC constructor bundles stay bundles: final, at most four members, no behaviour.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Service\Rbac\GrantReportSources;
use OCA\OpenRegister\Service\Rbac\ObjectLookup;
use OCA\OpenRegister\Service\Rbac\OwnershipGuards;
use OCA\OpenRegister\Service\Rbac\OwnershipWriters;
use OCA\OpenRegister\Service\Rbac\PrincipalDirectory;
use OCA\OpenRegister\Service\Rbac\ReachSources;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class RbacBundlesTest extends TestCase {

	/**
	 * One row per bundle.
	 *
	 * @return array<string, array{0: class-string}>
	 */
	public static function bundles(): array {
		$rows = [];
		foreach ([GrantReportSources::class, ObjectLookup::class, OwnershipGuards::class, OwnershipWriters::class, PrincipalDirectory::class, ReachSources::class] as $class) {
			$rows[$class] = [$class];
		}

		return $rows;
	}

	/**
	 * A bundle is final, takes at most four collaborators, exposes each as a public readonly property, and has no other method.
	 *
	 * @param class-string $class The bundle.
	 *
	 * @return void
	 */
	#[DataProvider('bundles')]
	public function testABundleOnlyCarriesItsCollaborators(string $class): void {
		$reflection = new \ReflectionClass($class);
		$parameters = $reflection->getConstructor()->getParameters();

		$this->assertTrue($reflection->isFinal(), $class . ' is final');
		$this->assertLessThanOrEqual(4, count($parameters), $class . ' bundles at most four collaborators');
		foreach ($parameters as $parameter) {
			$this->assertTrue($parameter->isPromoted(), $parameter->getName() . ' is promoted');
			$this->assertTrue($reflection->getProperty($parameter->getName())->isReadOnly(), $parameter->getName() . ' is readonly');
			$this->assertTrue($reflection->getProperty($parameter->getName())->isPublic(), $parameter->getName() . ' is public');
		}

		$this->assertSame(['__construct'], array_map(static fn (\ReflectionMethod $m): string => $m->getName(), $reflection->getMethods()));
	}
}
