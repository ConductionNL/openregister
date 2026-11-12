<?php

/**
 * Tests for TransferContext.
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

use OCA\OpenRegister\Service\Edepot\TransferContext;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Edepot\TransferContext
 */
class TransferContextTest extends TestCase {

	public function testTheContextReadsTheListFormat(): void {
		$this->assertSame('zip', TransferContext::formatOf(transferList: []));
		$context = TransferContext::now(transferList: ['uuid' => 'u', 'packageFormat' => 'bagit'], transportName: 'sftp');

		$this->assertSame('u', $context->transferUuid);
		$this->assertSame('bagit', $context->packageFormat);
		$this->assertSame('sftp', $context->transportName);
	}
}//end class
