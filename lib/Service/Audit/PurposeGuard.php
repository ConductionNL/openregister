<?php

/**
 * Binds a read of personal data to a declared, administered purpose.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Audit;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Decides whether a read needs a purpose, and refuses the ones that do not
 * carry one.
 *
 * The declaration lives on the schema, with the register as the fallback, in
 * the same shape and the same place as `x-openregister-processing-activity`
 * already uses. That is deliberate: the two annotations answer the two halves
 * of the same question, and an administrator who has found one has found the
 * other.
 *
 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
 */
class PurposeGuard {
	/**
	 * The schema and register annotation that turns doelbinding on.
	 *
	 * @var string
	 */
	public const ANNOTATION = 'x-openregister-purpose-required';

	/**
	 * The audit action a refused read is recorded under.
	 *
	 * @var string
	 */
	public const ACTION_REFUSED = 'purpose.query-refused';

	/**
	 * Constructor.
	 *
	 * @param PurposeRegistry  $registry The administered purpose list.
	 * @param PurposeContext   $context  The purpose this request declared.
	 * @param AuditTrailMapper $audit    Records the refusal.
	 * @param LoggerInterface  $logger   PSR-3 logger.
	 */
	public function __construct(
		private readonly PurposeRegistry $registry,
		private readonly PurposeContext $context,
		private readonly AuditTrailMapper $audit,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether a read of this schema has to name a purpose.
	 *
	 * BOTH halves are required: the schema (or its register) asks for
	 * doelbinding, AND the instance administers at least one purpose. The
	 * second half is not a loophole, it is the only way the first half can be
	 * satisfiable — an annotation with an empty purpose list would refuse every
	 * read of that schema with no value a caller could possibly name. It is
	 * logged as a warning so the gap is visible rather than silently permissive.
	 *
	 * @param Register|null $register The register being read.
	 * @param Schema|null   $schema   The schema being read.
	 *
	 * @return bool True when the read must name a purpose.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
	 */
	public function isRequired(?Register $register, ?Schema $schema): bool {
		if ($this->annotated(register: $register, schema: $schema) === false) {
			return false;
		}

		if ($this->registry->hasAdministeredPurposes() === true) {
			return true;
		}

		$this->logger->warning(
			message: '[Doelbinding] A schema asks for a declared purpose and the instance administers none, '
				. 'so the read runs unbound. Administer a purpose to close this.',
			context: [
				'schema' => $schema?->getId(),
				'register' => $register?->getId(),
			]
		);

		return false;
	}//end isRequired()

	/**
	 * Enforce doelbinding on a read, and remember the purpose it ran under.
	 *
	 * Returns null when the read needs no purpose, which is every read on an
	 * instance that has not turned doelbinding on. That null is the
	 * backwards-compatibility promise, and it is the only path that existed
	 * before this change.
	 *
	 * @param Register|null $register The register being read.
	 * @param Schema|null   $schema   The schema being read.
	 *
	 * @return string|null The accepted purpose code, or null when none was needed.
	 *
	 * @throws PurposeRefusedException When a purpose is needed and none is usable.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
	 */
	public function enforce(?Register $register, ?Schema $schema): ?string {
		if ($this->isRequired(register: $register, schema: $schema) === false) {
			return null;
		}

		$declared = $this->context->declared();

		try {
			$resolved = $this->registry->requirePurpose(code: $declared);
		} catch (PurposeRefusedException $refusal) {
			$this->recordRefusal(refusal: $refusal, register: $register, schema: $schema);
			throw $refusal;
		}

		$this->context->accept(purpose: $resolved['purpose'], activity: $resolved['activity']);

		return $resolved['purpose']->getCode();
	}//end enforce()

	/**
	 * Record the refusal on the trail.
	 *
	 * A refused read is the interesting event. "Who tried to read the BRP
	 * without naming a grondslag" is a question a functionaris
	 * gegevensbescherming asks, and a refusal that leaves no trace answers it
	 * with silence.
	 *
	 * Fail-soft: a refusal that cannot be recorded is still a refusal. The
	 * read does not become permitted because the bookkeeping failed.
	 *
	 * @param PurposeRefusedException $refusal  The refusal to record.
	 * @param Register|null           $register The register being read.
	 * @param Schema|null             $schema   The schema being read.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
	 */
	private function recordRefusal(
		PurposeRefusedException $refusal,
		?Register $register,
		?Schema $schema,
	): void {
		try {
			$this->audit->createPurposeRefusalEntry(
				rule: $refusal->getRule(),
				purpose: $refusal->getPurpose(),
				register: $register?->getId(),
				schema: $schema?->getId()
			);
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[Doelbinding] The refusal could not be recorded on the trail.',
				context: [
					'rule' => $refusal->getRule(),
					'error' => $e->getMessage(),
				]
			);
		}
	}//end recordRefusal()

	/**
	 * Whether the schema, or failing that its register, asks for doelbinding.
	 *
	 * @param Register|null $register The register being read.
	 * @param Schema|null   $schema   The schema being read.
	 *
	 * @return bool True when either carries the annotation.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
	 */
	private function annotated(?Register $register, ?Schema $schema): bool {
		if ($schema !== null && $this->readsTrue(configuration: $schema->getConfiguration()) === true) {
			return true;
		}

		if ($register !== null && $this->readsTrue(configuration: $register->getConfiguration()) === true) {
			return true;
		}

		return false;
	}//end annotated()

	/**
	 * Read the annotation out of a configuration array.
	 *
	 * Accepts the boolean and the string forms. JSON configuration written by
	 * hand carries `"true"` as often as `true`, and an annotation that silently
	 * means nothing because somebody quoted it is the failure mode this whole
	 * change exists to prevent elsewhere.
	 *
	 * @param mixed $configuration The entity's configuration column.
	 *
	 * @return bool True when the annotation is present and affirmative.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
	 */
	private function readsTrue(mixed $configuration): bool {
		if (is_array($configuration) === false) {
			return false;
		}

		$value = ($configuration[self::ANNOTATION] ?? null);
		if ($value === null) {
			return false;
		}

		if (is_bool($value) === true) {
			return $value;
		}

		if (is_string($value) === true) {
			return in_array(strtolower(trim($value)), ['true', '1', 'yes'], true);
		}

		return $value === 1;
	}//end readsTrue()
}//end class
