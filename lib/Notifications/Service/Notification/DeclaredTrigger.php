<?php

/**
 * OpenRegister DeclaredTrigger
 *
 * A rule's declared `trigger`, read once into its type and app event.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

/**
 * The parts of a declared `trigger` the validator asks about.
 *
 * A bare string names an app event; an object may name one in `event` and
 * declares its reserved `type`. Anything else has neither.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final class DeclaredTrigger {

	/**
	 * The declared `trigger.type`, empty when absent or the trigger is not an object.
	 *
	 * @var string
	 */
	public readonly string $type;

	/**
	 * The declared app event, null when none is named.
	 *
	 * @var mixed
	 */
	public readonly mixed $appEvent;

	/**
	 * The trigger object, null when the trigger is not one.
	 *
	 * @var array<string, mixed>|null
	 */
	public readonly ?array $spec;

	/**
	 * Constructor.
	 *
	 * @param mixed $trigger The declared trigger.
	 */
	public function __construct(mixed $trigger) {
		$spec     = null;
		$appEvent = null;
		if (is_string($trigger) === true) {
			$appEvent = $trigger;
		}

		if (is_array($trigger) === true) {
			$spec     = $trigger;
			$appEvent = ($trigger['event'] ?? null);
		}

		$this->spec     = $spec;
		$this->appEvent = $appEvent;
		$this->type     = (string)($spec['type'] ?? '');
	}//end __construct()
}//end class
