<?php

/**
 * Reads what the save-path lifecycle listeners judge out of an object and its schema.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
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

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Lifecycle\LifecycleTransitionResolver;
use Psr\Log\LoggerInterface;

/**
 * Finds an object's lifecycle declaration, and the transition an update amounts to.
 *
 * The schema is read without the tenant filter.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
class LifecycleEditReader {
	/**
	 * Constructor.
	 *
	 * @param SchemaMapperInterface       $schemaMapper       Schema lookup mapper.
	 * @param LifecycleTransitionResolver $transitionResolver Decides which declared transition an edit is.
	 * @param LoggerInterface             $logger             Logger for failed lookups.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		private readonly SchemaMapperInterface $schemaMapper,
		private readonly LifecycleTransitionResolver $transitionResolver,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The update of an object that declares a lifecycle, or null when there is nothing to judge.
	 *
	 * @param ObjectUpdatingEvent $event The update.
	 * @param string              $logAs Who to name in the warning of a failed lookup; empty keeps it silent.
	 *
	 * @return LifecycleEdit|null
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function editOf(ObjectUpdatingEvent $event, string $logAs = ''): ?LifecycleEdit {
		$stored = $event->getOldObject();
		if ($stored === null) {
			return null;
		}

		$declaration = $this->declarationOf(object: $event->getNewObject(), logAs: $logAs);
		if ($declaration === null) {
			return null;
		}

		return new LifecycleEdit(event: $event, stored: $stored, declaration: $declaration);
	}//end editOf()

	/**
	 * The lifecycle an object's schema declares, or null when it has none or the schema cannot be read.
	 *
	 * @param ObjectEntity $object The object.
	 * @param string       $logAs  Who to name in the warning of a failed lookup; empty keeps it silent.
	 *
	 * @return LifecycleDeclaration|null
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function declarationOf(ObjectEntity $object, string $logAs = ''): ?LifecycleDeclaration {
		$schema = $this->schemaOf(object: $object, logAs: $logAs);
		if ($schema === null) {
			return null;
		}

		$config = ($schema->getConfiguration() ?? []);
		$annotation = ($config['x-openregister-lifecycle'] ?? null);
		if (is_array($annotation) === true) {
			return new LifecycleDeclaration(schema: $schema, annotation: $annotation);
		}

		return null;
	}//end declarationOf()

	/**
	 * The declared transition this edit is, or null when none allows it.
	 *
	 * @param LifecycleEdit $edit An edit that changes the state to a text value.
	 *
	 * @return LifecycleAttempt|null
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function attemptOf(LifecycleEdit $edit): ?LifecycleAttempt {
		$matched = $this->transitionResolver->resolve(
			transitions: $edit->declaration->transitions(),
			uuid: $edit->uuid(),
			oldValue: $edit->getFromState(),
			newValue: $edit->getToState()
		);
		if ($matched === null) {
			return null;
		}

		return new LifecycleAttempt(edit: $edit, action: $matched[0], spec: $matched[1]);
	}//end attemptOf()

	/**
	 * Whether this edit is the in-flight write of declared state (a seed or example-set refresh).
	 *
	 * @param LifecycleEdit $edit The edit.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-refresh-writes-declared-state-as-the-system
	 */
	public function writesDeclaredState(LifecycleEdit $edit): bool {
		return $this->transitionResolver->writesDeclaredState(uuid: $edit->uuid());
	}//end writesDeclaredState()

	/**
	 * Look up the schema an object references, or null on failure.
	 *
	 * @param ObjectEntity $object The object.
	 * @param string       $logAs  Who to name in the warning of a failed lookup; empty keeps it silent.
	 *
	 * @return Schema|null
	 */
	private function schemaOf(ObjectEntity $object, string $logAs): ?Schema {
		$schemaRef = $object->getSchema();
		if ($schemaRef === null || $schemaRef === '') {
			return null;
		}

		return $this->find(schemaRef: $schemaRef, logAs: $logAs);
	}//end schemaOf()

	/**
	 * Read a schema across tenants, or null when that fails.
	 *
	 * @param string $schemaRef The object's schema reference.
	 * @param string $logAs     Who to name in the warning of a failed lookup; empty keeps it silent.
	 *
	 * @return Schema|null
	 */
	private function find(string $schemaRef, string $logAs): ?Schema {
		try {
			return $this->schemaMapper->find($schemaRef, _multitenancy: false);
		} catch (\Throwable $e) {
			if ($logAs !== '') {
				$this->logger->warning(sprintf('%s could not load schema "%s": %s', $logAs, $schemaRef, $e->getMessage()));
			}

			return null;
		}
	}//end find()
}//end class
