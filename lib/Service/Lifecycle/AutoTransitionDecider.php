<?php

/**
 * OpenRegister AutoTransitionDecider
 *
 * Decides one object's automatic move against the object as stored.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Lifecycle
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

namespace OCA\OpenRegister\Service\Lifecycle;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Deferral\DeferredEntryObjectResolver;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The deciding half of {@see AutoTransitionRunner}: re-read, then select.
 *
 * The object resolver is taken from the container at the moment of the
 * decision, for the same recursion reason the runner resolves its engine
 * lazily; see {@see AutoTransitionRunner}.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class AutoTransitionDecider {

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface    $container    Resolves the deferred-entry object resolver lazily.
	 * @param SchemaMapperInterface $schemaMapper Reads the schema's lifecycle annotation.
	 * @param AutoTransitionSelector $selector    Picks the one eligible move, or none.
	 * @param LoggerInterface       $logger       Logs an object that could not be re-read.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly SchemaMapperInterface $schemaMapper,
		private readonly AutoTransitionSelector $selector,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The move this object is eligible for right now, or null.
	 *
	 * @param array{uuid: string, register: string, schema: string} $entry    The object's identity.
	 * @param array<string, mixed>                                  $previous The object before the triggering write.
	 *
	 * @return AutoTransitionDecision|null The decided move, or null.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function decide(array $entry, array $previous): ?AutoTransitionDecision {
		$object = $this->resolveObject(entry: $entry);
		if ($object === null) {
			return null;
		}

		$declaration = $this->annotationFor(schema: $entry['schema']);
		if ($declaration === null) {
			return null;
		}

		$subject = new AutoTransitionSubject(uuid: $entry['uuid'], register: $entry['register'], schema: $entry['schema'], schemaSlug: $declaration[1]);
		return $this->decisionFor(subject: $subject, annotation: $declaration[0], object: $object, previous: $previous);
	}//end decide()

	/**
	 * Ask the selector for the move and bind it to the object's current version.
	 *
	 * @param AutoTransitionSubject $subject    The object the move is decided for.
	 * @param array<string, mixed>  $annotation The schema's lifecycle annotation.
	 * @param ObjectEntity          $object     The object as stored.
	 * @param array<string, mixed>  $previous   The object before the triggering write.
	 *
	 * @return AutoTransitionDecision|null The decided move, or null.
	 */
	private function decisionFor(AutoTransitionSubject $subject, array $annotation, ObjectEntity $object, array $previous): ?AutoTransitionDecision {
		$candidate = $this->selector->select(
			annotation: $annotation,
			objectData: ($object->getObject() ?? []),
			previous: $previous,
			schemaSlug: $subject->schemaSlug
		);
		if ($candidate === null) {
			return null;
		}

		return new AutoTransitionDecision(subject: $subject, candidate: $candidate, version: $object->getVersion(), updated: $object->getUpdated());
	}//end decisionFor()

	/**
	 * Re-read the object as stored, or null when it is gone or soft-deleted.
	 *
	 * @param array{uuid: string, register: string, schema: string} $entry The object's identity.
	 *
	 * @return ObjectEntity|null The stored object, or null.
	 */
	private function resolveObject(array $entry): ?ObjectEntity {
		try {
			$resolver = $this->container->get(DeferredEntryObjectResolver::class);
			return $resolver->resolve(entry: $entry);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[AutoTransitionRunner] Could not re-read the object to decide an automatic transition.',
				['uuid' => $entry['uuid'], 'schema' => $entry['schema'], 'error' => $e->getMessage()]
			);
			return null;
		}
	}//end resolveObject()

	/**
	 * The schema's lifecycle annotation and the schema's slug.
	 *
	 * @param string $schema The object's schema reference.
	 *
	 * @return array{0: array<string, mixed>, 1: string}|null The annotation and slug, or null when there is none.
	 */
	private function annotationFor(string $schema): ?array {
		try {
			$entity = $this->schemaMapper->find($schema, _multitenancy: false);
		} catch (Throwable $e) {
			return null;
		}

		$annotation = (($entity->getConfiguration() ?? [])['x-openregister-lifecycle'] ?? null);
		if (is_array($annotation) === false || $annotation === []) {
			return null;
		}

		return [$annotation, (string)($entity->getSlug() ?? '')];
	}//end annotationFor()
}//end class
