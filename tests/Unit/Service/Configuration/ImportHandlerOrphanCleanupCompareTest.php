<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A same-version re-import that differs only in `orphanCleanup` is a change.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/openregister
 *
 * @spec openspec/specs/referential-integrity/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Service\Configuration\ImportHandler;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\Service\Configuration\ImportHandler
 */
class ImportHandlerOrphanCleanupCompareTest extends TestCase {

	public function testTheFlagIsAComparedConfigurationKey(): void {
		$compared = new ReflectionMethod(ImportHandler::class, 'isComparedConfigurationKey');

		$this->assertTrue($compared->invoke(null, 'orphanCleanup'));
	}
}//end class
