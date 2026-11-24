<?php

/**
 * Sample file for the dependency reader pin: group use, and a bare name in its own namespace.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace Fx\Alpha;

use Fx\Kernel\{Contracts\Clock as KernelClock, Contracts\Missing};

final class Service {
	public function __construct(private KernelClock $clock, private Policy $policy) {
	}
}
