<?php

/**
 * The refusal raised when an agent's tool grants resolve to nothing.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Capability
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Capability;

use OCA\OpenRegister\Service\Capability\ToolGrantResolutionException;
use OCA\OpenRegister\Service\Capability\ToolGrantResolver;
use PHPUnit\Framework\TestCase;

/**
 * The refusal is a 422 that names the dead grants and the explicit no-tools sentinel.
 *
 * @covers \OCA\OpenRegister\Service\Capability\ToolGrantResolutionException
 */
final class ToolGrantResolutionExceptionTest extends TestCase {

	/**
	 * The grants are re-indexed and named in the message with the sentinel to use instead.
	 *
	 * @return void
	 */
	public function testTheRefusalNamesTheGrantsAndTheSentinel(): void {
		$refusal = new ToolGrantResolutionException(grants: [3 => 'tool.missing', 7 => 'other.gone']);

		$this->assertSame(['tool.missing', 'other.gone'], $refusal->getGrants());
		$this->assertSame(422, $refusal->getCode());
		$this->assertStringContainsString('resolve to no tools: tool.missing, other.gone.', $refusal->getMessage());
		$this->assertStringContainsString('should be granted "' . ToolGrantResolver::NO_TOOLS_SENTINEL . '" instead', $refusal->getMessage());
	}//end testTheRefusalNamesTheGrantsAndTheSentinel()
}//end class
