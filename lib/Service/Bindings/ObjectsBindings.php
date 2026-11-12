<?php

/**
 * The contract bindings of the objects core.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Bindings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Bindings;

use OCA\OpenRegister\Kernel\Contracts\EntityEventsInterface;
use OCA\OpenRegister\Service\Object\CorrectionTargets;
use OCA\OpenRegister\Service\Object\CorrectionTargetsInterface;
use OCA\OpenRegister\Service\Object\DraftRemovalFrame;
use OCA\OpenRegister\Service\Object\DraftRemovalFrameInterface;
use OCA\OpenRegister\Service\Object\RetentionDestruction;
use OCA\OpenRegister\Service\Object\RetentionDestructionInterface;
use OCA\OpenRegister\Service\Registers\EntityEventPublisher;

/**
 * Contract => service map for the objects core.
 *
 * ContractBindings::BINDINGS spreads this map, so an entry here is registered
 * as an alias exactly like an entry written in ContractBindings itself. It
 * exists so that lanes adding bindings for different areas edit different
 * files.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class ObjectsBindings {

	/**
	 * Contract => the service that implements it.
	 *
	 * @var array<class-string, class-string>
	 */
	public const BINDINGS = [
		CorrectionTargetsInterface::class    => CorrectionTargets::class,
		DraftRemovalFrameInterface::class    => DraftRemovalFrame::class,
		RetentionDestructionInterface::class => RetentionDestruction::class,
		EntityEventsInterface::class         => EntityEventPublisher::class,
	];
}//end class
