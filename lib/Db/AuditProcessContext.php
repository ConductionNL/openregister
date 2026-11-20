<?php
/**
 * AuditProcessContext: the flow run and lifecycle move in flight, as the audit trail asks it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCA\OpenRegister\Kernel\Contracts\Compliance\AuditProcessContextInterface;
use OCA\OpenRegister\Service\Lifecycle\AutoTransitionPassInterface;
use OCA\OpenRegister\Service\Lifecycle\GraphMoveCatalogue;
use OCA\OpenRegister\Service\Lifecycle\LifecycleActionContextInterface;
use OCP\IDBConnection;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Answers the audit trail from the ambient flow frame, the automatic-transition pass and the lifecycle action frame.
 *
 * Each collaborator is read through the container when asked: the audit trail
 * is built in contexts where the lifecycle services are not wired, and a
 * resolution failure means "nothing in flight", never a failed audit row.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class AuditProcessContext implements AuditProcessContextInterface {

	/**
	 * Constructor.
	 *
	 * @param IDBConnection      $db        The connection the flow attribution maps over.
	 * @param ContainerInterface $container Resolves the flow, pass and action frames when asked.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * Stamp the flow run, node and step in flight onto the row.
	 *
	 * @param AuditTrail $auditTrail The row being built.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-object-attribution/specs/flow-object-attribution/spec.md
	 */
	public function stampFlowFrame(AuditTrail $auditTrail): void {
		(new AuditFlowAttribution(db: $this->db, container: $this->container))->apply(auditTrail: $auditTrail);
	}//end stampFlowFrame()

	/**
	 * The automatic lifecycle action being applied right now, or null.
	 *
	 * @return string|null The action name, or null.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function automaticTransition(): ?string {
		try {
			return $this->container->get(AutoTransitionPassInterface::class)->applyingAction();
		} catch (Throwable $passUnavailable) {
			return null;
		}
	}//end automaticTransition()

	/**
	 * The reopen kind (GraphMoveCatalogue::REOPEN) when the move declared for the object is a reopen, otherwise null.
	 *
	 * @param string $uuid The object's uuid.
	 *
	 * @return string|null The kind, or null.
	 *
	 * @spec openspec/changes/lifecycle-over-reference-field/specs/object-lifecycle/spec.md#requirement-a-declared-reopen-is-the-only-move-out-of-a-terminal-state
	 */
	public function transitionKindFor(string $uuid): ?string {
		if ($uuid === '') {
			return null;
		}

		try {
			$declared = $this->container->get(LifecycleActionContextInterface::class)->declaredFor($uuid);
		} catch (Throwable $contextUnavailable) {
			return null;
		}

		if ($declared === GraphMoveCatalogue::REOPEN) {
			return GraphMoveCatalogue::REOPEN;
		}

		return null;
	}//end transitionKindFor()
}//end class
