<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Kernel\Contracts\ObjectReadCheckInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;

/**
 * An object read check under which nothing is readable.
 */
final class UnreadableObjects implements ObjectReadCheckInterface {
	/**
	 * @param ObjectRef $ref The object.
	 *
	 * @return bool
	 */
	public function isReadable(ObjectRef $ref): bool {
		return false;
	}//end isReadable()
}//end class
