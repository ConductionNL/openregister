<?php

/**
 * Tests for TransferPipeline.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\Edepot
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Edepot;

use OCA\OpenRegister\Service\Edepot\EdepotTransferService;
use OCA\OpenRegister\Service\Edepot\TransferPipeline;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers \OCA\OpenRegister\Service\Edepot\TransferPipeline
 */
class TransferPipelineTest extends TestCase {

	public function testTheServiceTakesItsStepsAsOneBundleOfAtMostFour(): void {
		$service  = (new ReflectionClass(EdepotTransferService::class))->getConstructor();
		$pipeline = (new ReflectionClass(TransferPipeline::class))->getConstructor();

		$this->assertNotNull($service);
		$this->assertNotNull($pipeline);
		$this->assertLessThanOrEqual(3, $service->getNumberOfParameters());
		$this->assertLessThanOrEqual(4, $pipeline->getNumberOfParameters());
	}
}//end class
