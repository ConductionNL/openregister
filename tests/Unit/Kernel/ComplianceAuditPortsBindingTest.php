<?php

/**
 * The audit trail's two Compliance-owned ports are bound by the areas that answer them.
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

use OCA\OpenRegister\Kernel\Contracts\Compliance\AccountConsumerInterface;
use OCA\OpenRegister\Kernel\Contracts\Compliance\TransitionInFlightInterface;
use OCA\OpenRegister\Service\Consumer\AccountConsumerLookup;
use OCA\OpenRegister\Service\Flow\FlowWiring;
use OCA\OpenRegister\Service\Integration\IntegrationWiring;
use OCA\OpenRegister\Service\Lifecycle\LifecycleActionContext;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * The audit trail asks the ports; Processes and Integrations answer them in their own wiring.
 */
final class ComplianceAuditPortsBindingTest extends TestCase {

	public function testTheLifecycleBindsTheTransitionInFlightToItsSharedActionContext(): void {
		$context = new RecordingRegistrationContext();
		FlowWiring::register(context: $context);

		$this->assertContains(['alias' => TransitionInFlightInterface::class, 'target' => LifecycleActionContext::class], $context->aliases);
		$this->assertContains(LifecycleActionContext::class, $context->services);
		$this->assertTrue(is_subclass_of(LifecycleActionContext::class, TransitionInFlightInterface::class));
	}

	public function testTheConsumerRegisterBindsTheAccountConsumer(): void {
		$this->assertSame(AccountConsumerLookup::class, IntegrationWiring::BINDINGS[AccountConsumerInterface::class]);
		$this->assertTrue(is_subclass_of(AccountConsumerLookup::class, AccountConsumerInterface::class));
	}

	public function testTheSameActionContextAnswersTheLifecycleAndTheAuditTrail(): void {
		$actions = new LifecycleActionContext();
		$actions->enterSystem('object-uuid', 'decidesk');
		$actions->declare('object-uuid', 'reopen');

		$port = $actions;
		$this->assertInstanceOf(TransitionInFlightInterface::class, $port);
		$this->assertSame('decidesk', $port->systemAppFor('object-uuid'));
		$this->assertSame('reopen', $port->declaredFor('object-uuid'));
		$this->assertNull($port->systemAppFor('other-uuid'));
		$this->assertNull($port->declaredFor('other-uuid'));
	}
}//end class
