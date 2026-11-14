<?php

/**
 * A bulk action written against the BulkStep signature, implementing only BulkActionInterface.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Compat\Fixtures
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Compat\Fixtures;

use OCA\OpenRegister\BulkAction\BulkActionInterface;
use OCA\OpenRegister\BulkAction\BulkActionResult;
use OCA\OpenRegister\BulkAction\BulkStep;

class StepFormBulkAction implements BulkActionInterface {
	use BulkActionMetadata;

	public ?BulkStep $received = null;

	public function apply(BulkStep $step): BulkActionResult {
		$this->received = $step;
		return BulkActionResult::applied(reason: 'step');
	}
}
