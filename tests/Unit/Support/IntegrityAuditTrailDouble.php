<?php

/**
 * OpenRegister IntegrityAuditTrailDouble
 *
 * The audit trail mapper as the referential-integrity tests stub it: every
 * public method is stubbed except recordIntegrityAction(), which runs for real
 * on the stubbed expiry and insert, so a test that captures the inserted row
 * still sees the row the integrity service causes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Support;

use OCA\OpenRegister\Db\AuditTrailMapper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Builds the audit trail mapper double of the referential-integrity tests.
 */
final class IntegrityAuditTrailDouble {

	/**
	 * The mapper with every public method stubbed but recordIntegrityAction().
	 *
	 * @param TestCase $test The test that owns the double.
	 *
	 * @return AuditTrailMapper&MockObject
	 */
	public static function create(TestCase $test): AuditTrailMapper&MockObject {
		return $test->getMockBuilder(AuditTrailMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(self::stubbedMethods())
			->getMock();
	}//end create()

	/**
	 * Every public instance method of the mapper except recordIntegrityAction().
	 *
	 * @return list<string>
	 */
	private static function stubbedMethods(): array {
		$methods = [];
		foreach ((new ReflectionClass(AuditTrailMapper::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
			if ($method->isStatic() === true || $method->isConstructor() === true || $method->isFinal() === true || $method->getName() === 'recordIntegrityAction') {
				continue;
			}

			$methods[] = $method->getName();
		}

		return $methods;
	}//end stubbedMethods()
}//end class
