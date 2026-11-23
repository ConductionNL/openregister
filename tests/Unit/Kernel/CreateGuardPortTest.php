<?php

/**
 * The Core create-guard port is implemented by Search's duplicate policy and bound in Search's own wiring.
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

use OCA\OpenRegister\Kernel\Contracts\CreateGuardInterface;
use OCA\OpenRegister\Search\SearchWiring;
use OCA\OpenRegister\Search\Service\Quality\DedupCreatePolicy;
use OCA\OpenRegister\Search\Service\Quality\DedupCreatePolicyInterface;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * The port is owned by Core, the duplicate policy implements it, and both ids reach the one policy.
 */
final class CreateGuardPortTest extends TestCase {

	public function testThePolicyInterfaceExtendsTheCoreGuard(): void {
		$this->assertTrue(is_subclass_of(DedupCreatePolicyInterface::class, CreateGuardInterface::class));
		$this->assertTrue(is_subclass_of(DedupCreatePolicy::class, CreateGuardInterface::class));
		$this->assertSame(['guardCreate', 'recordOverride'], get_class_methods(CreateGuardInterface::class));
		$this->assertSame(['guardCreate', 'recordOverride'], get_class_methods(DedupCreatePolicyInterface::class));
	}

	public function testTheConstantsKeepTheirValues(): void {
		$this->assertSame('_dedupOverride', CreateGuardInterface::OVERRIDE_KEY);
		$this->assertSame('_dedupOverride', DedupCreatePolicyInterface::OVERRIDE_KEY);
		$this->assertSame('dedup.overridden', DedupCreatePolicyInterface::OVERRIDE_ACTION);
		$this->assertSame('dedup.overridden', DedupCreatePolicy::OVERRIDE_ACTION);
	}

	public function testSearchBindsBothIdsToThePolicy(): void {
		$context = new RecordingRegistrationContext();
		SearchWiring::register(context: $context);

		$this->assertContains(['alias' => CreateGuardInterface::class, 'target' => DedupCreatePolicy::class], $context->aliases);
		$this->assertContains(['alias' => DedupCreatePolicyInterface::class, 'target' => DedupCreatePolicy::class], $context->aliases);
	}
}//end class
