<?php

/**
 * Sample file for the dependency reader pin: a Kernel contract that names a non-Kernel class.
 *
 * A docblock mentioning \Fx\Alpha\Service is not a reference.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace Fx\Kernel\Contracts;

// Service is named in a line comment only.
interface Clock {
	public function policy(): \Fx\Alpha\Policy;
}
