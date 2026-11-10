<?php

/**
 * ObjectWriteConfigValidator: an integer identifier is read as its digits, and bulk off is accepted while a non-boolean bulk is refused.
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

use OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteConfigValidator;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteConfigValidator
 */
class ObjectWriteConfigValidatorBulkBehaviourTest extends TestCase {

	private ObjectWriteConfigValidator $validator;

	protected function setUp(): void {
		parent::setUp();
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$this->validator = new ObjectWriteConfigValidator($l10n);
	}//end setUp()

	public function testAnIntegerIdentifierIsItsDigits(): void {
		self::assertSame('7', $this->validator->identifier(7));
		self::assertSame('cases', $this->validator->identifier(' cases '));
		self::assertSame('', $this->validator->identifier(7.5));
	}//end testAnIntegerIdentifierIsItsDigits()

	public function testBulkOffIsAcceptedAndANonBooleanBulkIsRefused(): void {
		$config = ['register' => 'r', 'schema' => 's', 'operation' => 'create', 'fields' => ['a' => 1]];
		$this->validator->validate($config + ['bulk' => false]);

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('"bulk" must be true or false, as a boolean.');
		$this->validator->validate($config + ['bulk' => 'yes']);
	}//end testBulkOffIsAcceptedAndANonBooleanBulkIsRefused()
}//end class
