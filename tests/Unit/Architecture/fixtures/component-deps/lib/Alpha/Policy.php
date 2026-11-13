<?php

/**
 * Sample file for the dependency reader pin: a plain use import with an alias.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace Fx\Alpha;

use Fx\Kernel\Contracts\Clock as C;

/* Service, in a block comment, is not a reference. */
final class Policy {
	public function __construct(private C $clock) {
	}
}
