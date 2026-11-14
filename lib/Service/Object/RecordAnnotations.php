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
use DateTimeInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Archival\ArchivalDecisionResolver;
use OCA\OpenRegister\Service\Archival\RetentionEvaluator;
use OCA\OpenRegister\Service\Confidentiality\ConfidentialityAnnotationValidator;
use OCA\OpenRegister\Service\Confidentiality\ConfidentialityRule;
use OCA\OpenRegister\Service\ExternalLink\ExternalLinkResolver;
use OCA\OpenRegister\Service\Schemas\SchemaDeclarationValidators;
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
	 * Put the object's key on `@self.key` when its schema declares an identity.
	 *
	 * The key is built from the rendered values, after the read strip, so a
	 * reader who may not see one of the identity properties is not shown it
	 * through the key either; such a reader, like an object missing a value,
	 * gets no key.
	 *
	 * @param ObjectEntity $entity The rendered object.
	 * @param Schema|null  $schema Its schema.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/modelling-composite-identity/specs/objects-crud/spec.md
	 */
	public function attachKey(ObjectEntity $entity, ?Schema $schema): void {
		if ($schema === null) {
			return;
		}

		$this->guarded(
			failure: 'identity key failed for ' . (string)$entity->getUuid(),
			step: static function () use ($entity, $schema): void {
				$evaluator = SchemaDeclarationValidators::uniqueConstraints();
				$identity = $evaluator->identity(configuration: $schema->getConfiguration());
				if ($identity === null) {
					return;
				}

				$entity->setIdentityKey($evaluator->keyOf(identity: $identity, object: self::objectOf(entity: $entity)));
			}
		);
	}//end attachKey()

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
	 * Attach the resolved classification under `@self.confidentiality`.
	 *
	 * Read from the stored object, before any caller projection or property
	 * strip, so the mirror states the object's real tier, ground and release
	 * to every caller who may read the object at all. A schema without the
	 * annotation gets no key. A faulty annotation still gets the mirror, with
	 * no tier and `released` false, matching the fail-closed rule.
	 *
	 * @param ObjectEntity      $entity The rendered object.
	 * @param Schema|null       $schema Its schema.
	 * @param array             $stored The stored object data.
	 * @param DateTimeInterface $now    The moment a release is measured against.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/confidentiality-classification-primitive/specs/confidentiality-classification/spec.md#requirement-the-resolved-classification-must-be-exposed-on-render-as-selfconfidentiality
	 */
	public function attachConfidentiality(ObjectEntity $entity, ?Schema $schema, array $stored, DateTimeInterface $now): void {
		$block = (($schema?->getConfiguration() ?? [])[ConfidentialityAnnotationValidator::ANNOTATION] ?? null);
		if ($block === null) {
			return;
		}

		$this->guarded(
			failure: 'confidentiality mirror failed for ' . (string)$entity->getUuid(),
			step: static function () use ($entity, $schema, $block, $stored, $now): void {
				$rule = ConfidentialityRule::fromBlock(block: $block, properties: ($schema?->getProperties() ?? []));
				$entity->setConfidentiality($rule->describe(object: $stored, now: $now));
			}
		);
	}//end attachConfidentiality()

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
