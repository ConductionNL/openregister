<?php

/**
 * DeclaredActionCheck: save-time check of the actions a schema declares.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\SchemaMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\SchemaMapper;

use Closure;
use InvalidArgumentException;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionDeclarations;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionDefinitionInterface;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionValidatorInterface;
use Psr\Log\LoggerInterface;

/**
 * Refuses a declared action bound to a flow nobody can run.
 *
 * @spec openspec/changes/macro-flows-with-next-item/specs/declared-actions/spec.md#requirement-a-declared-action-may-run-a-manual-flow-as-a-macro
 */
final class DeclaredActionCheck {

	/**
	 * Constructor.
	 *
	 * @param ObjectActionValidatorInterface|Closure|null $objectActions Verifies every declared action; a Closure returns it on first use.
	 * @param LoggerInterface                    $logger        Says which half of the check did not run.
	 */
	public function __construct(
		private readonly ObjectActionValidatorInterface|Closure|null $objectActions,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Refuse a declared action bound to a flow nobody can run.
	 *
	 * Three of the refusals are questions about the flow (it exists, it is
	 * published, it has a manual trigger) and all three are SILENT at run time.
	 * An action bound to a missing flow appears in the menu, does nothing when
	 * clicked, and looks exactly like a flow that ran and changed nothing. The
	 * handler cannot tell those apart and cannot fix either, so the refusal
	 * belongs here, in front of the author who can.
	 *
	 * @param Schema $schema The schema being saved.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When a macro binding cannot run or a declared action is malformed.
	 *
	 * @spec openspec/changes/macro-flows-with-next-item/specs/declared-actions/spec.md#requirement-a-declared-action-may-run-a-manual-flow-as-a-macro
	 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-a-declared-action-may-carry-inputs-a-precondition-surfaces-and-one-body
	 */
	public function check(Schema $schema): void {
		$validator = $this->objectActions;
		if ($validator instanceof Closure) {
			$validator = $validator();
		}

		if ($validator instanceof ObjectActionValidatorInterface) {
			$this->refuse(refusals: $validator->refusals(schema: $schema));
			return;
		}

		$this->checkShapeOnly(schema: $schema);
	}//end check()

	/**
	 * With no validator wired the shape is still checked, and the save says
	 * which half did not run rather than reporting a clean pass.
	 *
	 * @param Schema $schema The schema being saved.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When a declared action is malformed.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) The shape refusals are pure readers of the declaration.
	 */
	private function checkShapeOnly(Schema $schema): void {
		$configuration = ($schema->getConfiguration() ?? []);
		$this->refuse(
			refusals: array_merge(
				ObjectActionDeclarations::refusals(configuration: $configuration, authorization: ($schema->getAuthorization() ?? [])),
				ObjectActionDeclarations::macroRefusals(configuration: $configuration)
			)
		);

		if ($this->runnable(configuration: $configuration) !== []) {
			$this->logger->warning('[SchemaMapper] Declared actions saved without verifying their flows: no validator is wired');
		}
	}//end checkShapeOnly()

	/**
	 * The declared actions that have a body to run.
	 *
	 * @param array<string, mixed> $configuration The schema configuration.
	 *
	 * @return array<ObjectActionDefinitionInterface>
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectActionDeclarations::parse is the named constructor of the value objects.
	 */
	private function runnable(array $configuration): array {
		return array_filter(
			ObjectActionDeclarations::parse(configuration: $configuration),
			static fn (ObjectActionDefinitionInterface $definition): bool => $definition->isRunnable()
		);
	}//end runnable()

	/**
	 * Refuse the save with the given reasons, if there are any.
	 *
	 * @param array<int, string> $refusals The reasons.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When there is a reason.
	 */
	private function refuse(array $refusals): void {
		if ($refusals !== []) {
			throw new InvalidArgumentException(implode(' ', $refusals));
		}
	}//end refuse()
}//end class
