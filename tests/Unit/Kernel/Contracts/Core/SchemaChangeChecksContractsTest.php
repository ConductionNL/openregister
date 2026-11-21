<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The schema API names only Core contracts for the checks other areas run on
 * a schema save: each is implemented by the class that did the work before,
 * and the Processes registrar binds the two services.
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-saving-a-schema-must-re-check-the-forms-that-submit-into-it
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Kernel\Contracts\Core;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Kernel\Contracts\Core\DeclarationRefusalInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\SchemaFormDependentsInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\SchemaRenameWarningsInterface;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationDeclarationException;
use OCA\OpenRegister\Service\Flow\FlowRegistrar;
use OCA\OpenRegister\Service\Flow\FlowTriggerRenameCheck;
use OCA\OpenRegister\Service\Form\FormDependentsCheck;
use OCA\OpenRegister\Service\Form\FormDependentsCheckInterface;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class SchemaChangeChecksContractsTest extends TestCase {
	public function testTheNotificationRefusalIsADeclarationRefusalWithItsErrors(): void {
		$errors = [['code' => 'unsafe-url', 'message' => 'The action url is not linked to.']];
		$refusal = new NotificationDeclarationException($errors);

		$this->assertInstanceOf(DeclarationRefusalInterface::class, $refusal);
		$this->assertSame($errors, $refusal->getErrors());
	}

	public function testTheRenameCheckAnswersTheRenameWarningsPort(): void {
		$this->assertTrue(is_a(FlowTriggerRenameCheck::class, SchemaRenameWarningsInterface::class, true));
		$this->assertSame(FlowTriggerRenameCheck::class, FlowRegistrar::BINDINGS[SchemaRenameWarningsInterface::class]);
	}

	public function testTheFormDependentsCheckAnswersTheFormDependentsPort(): void {
		$this->assertTrue(is_a(FormDependentsCheckInterface::class, SchemaFormDependentsInterface::class, true));
		$this->assertSame(FormDependentsCheck::class, FlowRegistrar::BINDINGS[SchemaFormDependentsInterface::class]);
		$this->assertSame(['claim', 'assess', 'apply'], get_class_methods(SchemaFormDependentsInterface::class));
	}
}
