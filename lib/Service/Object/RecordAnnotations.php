<?php

/**
 * OpenRegister RecordAnnotations
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use Closure;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Archival\ArchivalDecisionResolver;
use OCA\OpenRegister\Service\Archival\RetentionEvaluator;
use OCA\OpenRegister\Service\ExternalLink\ExternalLinkResolver;
use Psr\Log\LoggerInterface;

/**
 * Attaches what a schema's annotations say about a rendered record.
 *
 * Each block is an ornament on the record: a failure is a debug line, because a
 * record that cannot be read at all is the far worse outcome.
 *
 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
 */
final class RecordAnnotations {

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Logs a block that failed.
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
	 */
	public function __construct(private readonly LoggerInterface $logger) {
	}//end __construct()

	/**
	 * Attach the annotation-driven `_retention.annotation` block.
	 *
	 * @param ObjectEntity $entity The entity being rendered.
	 * @param Schema|null  $schema The resolved schema.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
	 */
	public function attachRetention(ObjectEntity $entity, ?Schema $schema): void {
		$annotation = (($schema?->getConfiguration() ?? [])['x-openregister-archival'] ?? null);
		if (is_array($annotation) === false || $entity->getCreated() === null) {
			return;
		}

		$this->guarded(
			failure: 'archival retention compute failed for ' . (string)$entity->getUuid(),
			step: fn () => $this->evaluateRetention(entity: $entity, annotation: $annotation)
		);
	}//end attachRetention()

	/**
	 * Evaluate the annotation and put the result on the entity's retention.
	 *
	 * @param ObjectEntity $entity     The entity being rendered.
	 * @param array        $annotation The `x-openregister-archival` annotation.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
	 */
	private function evaluateRetention(ObjectEntity $entity, array $annotation): void {
		$retention = ($entity->getRetention() ?? []);
		$retention['annotation'] = (new RetentionEvaluator(logger: $this->logger))->evaluate(
			annotation: $annotation,
			row: self::objectOf(entity: $entity),
			createdAt: $entity->getCreated()
		);
		$entity->setRetention($retention);
	}//end evaluateRetention()

	/**
	 * Attach the archival decision, when there is one.
	 *
	 * @param ObjectEntity $entity The entity being rendered.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
	 */
	public function attachDecision(ObjectEntity $entity): void {
		$this->guarded(
			failure: 'archival decision resolve failed for ' . (string)$entity->getUuid(),
			step: function () use ($entity): void {
				$decision = (new ArchivalDecisionResolver())->resolve(entity: $entity);
				if ($decision !== null) {
					$entity->setArchivalRetention($decision);
				}
			}
		);
	}//end attachDecision()

	/**
	 * Attach the links out of this record, under `@self.externalLinks`.
	 *
	 * A schema declaring no links, or a record none applies to, gets no key at all.
	 *
	 * @param ObjectEntity $entity The rendered object.
	 * @param Schema|null  $schema Its schema.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/api-surface-governance/spec.md#requirement-a-schema-declares-links-out-of-its-objects-req-avs-001
	 */
	public function attachExternalLinks(ObjectEntity $entity, ?Schema $schema): void {
		$declarations = (($schema?->getConfiguration() ?? [])[ExternalLinkResolver::ANNOTATION] ?? null);
		if (is_array($declarations) === false || $declarations === []) {
			return;
		}

		$this->guarded(
			failure: 'external link resolution failed for ' . (string)$entity->getUuid(),
			step: static fn () => self::resolveLinks(entity: $entity, declarations: $declarations)
		);
	}//end attachExternalLinks()

	/**
	 * Resolve the declared links and put those that apply on the entity.
	 *
	 * @param ObjectEntity $entity       The rendered object.
	 * @param array        $declarations The declared links.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/api-surface-governance/spec.md#requirement-a-schema-declares-links-out-of-its-objects-req-avs-001
	 */
	private static function resolveLinks(ObjectEntity $entity, array $declarations): void {
		$objectData = self::objectOf(entity: $entity);
		$links = (new ExternalLinkResolver())->resolve(declarations: $declarations, object: $objectData);
		if ($links !== []) {
			$entity->setObject(self::withLinks(objectData: $objectData, links: $links));
		}
	}//end resolveLinks()

	/**
	 * The object data with the links on its `@self`.
	 *
	 * @param array $objectData The object data.
	 * @param array $links      The links.
	 *
	 * @return array The object data.
	 *
	 * @spec openspec/specs/api-surface-governance/spec.md#requirement-a-schema-declares-links-out-of-its-objects-req-avs-001
	 */
	private static function withLinks(array $objectData, array $links): array {
		if (is_array($objectData['@self'] ?? null) === false) {
			$objectData['@self'] = [];
		}

		$objectData['@self']['externalLinks'] = $links;

		return $objectData;
	}//end withLinks()

	/**
	 * The entity's data, always as an array.
	 *
	 * @param ObjectEntity $entity The entity.
	 *
	 * @return array The data.
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
	 */
	private static function objectOf(ObjectEntity $entity): array {
		$objectData = $entity->getObject();
		if (is_array($objectData) === false) {
			return [];
		}

		return $objectData;
	}//end objectOf()

	/**
	 * Run one block; a failure becomes a debug line.
	 *
	 * @param string  $failure What failed, and for which record.
	 * @param Closure $step    The block.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
	 */
	private function guarded(string $failure, Closure $step): void {
		try {
			$step();
		} catch (\Throwable $e) {
			$this->logger->debug(sprintf('[RenderObject] %s: %s', $failure, $e->getMessage()));
		}
	}//end guarded()
}//end class
