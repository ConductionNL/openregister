<?php

/**
 * K29: the extending-form validator contract is bound and implemented.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Architecture
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Architecture;

use OCA\OpenRegister\Service\CoreServiceBindings;
use OCA\OpenRegister\Service\Schemas\ExtendingFormDeclaration;
use OCA\OpenRegister\Service\Schemas\ExtendingFormValidatorInterface;
use OCA\OpenRegister\Service\Schemas\SchemaDeclarationValidators;
use PHPUnit\Framework\TestCase;

/**
 * The Db save path reaches the extending-form validator through its interface.
 */
final class K29ExtendingFormBindingTest extends TestCase {

	public function testTheClassImplementsTheInterface(): void {
		$this->assertContains(ExtendingFormValidatorInterface::class, class_implements(ExtendingFormDeclaration::class));
	}//end testTheClassImplementsTheInterface()

	public function testTheInterfaceIsBoundInTheCoreMap(): void {
		$this->assertSame(ExtendingFormDeclaration::class, CoreServiceBindings::BINDINGS[ExtendingFormValidatorInterface::class]);
	}//end testTheInterfaceIsBoundInTheCoreMap()

	public function testTheFactoryHandsOutTheValidator(): void {
		$this->assertInstanceOf(ExtendingFormDeclaration::class, SchemaDeclarationValidators::extendingForm());
	}//end testTheFactoryHandsOutTheValidator()

	public function testTheDbLayerNamesOnlyTheInterface(): void {
		$source = (string) file_get_contents(__DIR__.'/../../../lib/Db/SchemaMapper/PropertyDeclarationRefusals.php');
		$this->assertDoesNotMatchRegularExpression('/\bExtendingFormDeclaration\b(?!Interface)/', $source);
	}//end testTheDbLayerNamesOnlyTheInterface()
}//end class
