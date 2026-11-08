<?php

/**
 * AccessDeclarationChecks: the save-time checks of what decides who reaches which objects.
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

use Exception;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Rbac\AuthorizationDeclarationValidators;
use OCA\OpenRegister\Service\Rbac\DepartmentMatrixCompilerInterface;
use OCA\OpenRegister\Service\Rbac\HierarchyGrantExpanderInterface;
use OCA\OpenRegister\Service\Rbac\RevealCollectorInterface;
use Psr\Log\LoggerInterface;

/**
 * Refuses the authorization declarations that would be silent afterwards.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class AccessDeclarationChecks {

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Receives the notice about unknown keys.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Refuse `audit: true` on a property nobody is kept out of (row 5.6).
	 *
	 * THE REFUSAL IS THE POINT OF THE FEATURE. An audited reveal answers "who
	 * saw the BSN", and it can only answer it while the entries are rare. A
	 * property with no `read` rule is shown to every reader of the object, so
	 * auditing it writes an entry per reader per object per request for a
	 * value nobody was ever kept from, and the handful of entries that matter
	 * are then somewhere inside several million that do not. A trail nobody can
	 * search is the same as no trail, arrived at by a route that looks like
	 * diligence.
	 *
	 * @param Schema $schema The schema being saved.
	 *
	 * @return void
	 *
	 * @throws Exception When a property audits a reveal it cannot restrict.
	 *
	 * @spec openspec/changes/sensitive-field-reveal-audit/specs/row-field-level-security/spec.md
	 */
	public function checkRevealAudit(Schema $schema): void {
		$offenders = array_filter($schema->getProperties(), fn (mixed $config): bool => $this->auditsWithoutRead(config: $config));
		if ($offenders !== []) {
			throw new Exception(
				'authorization.audit is only meaningful on a property with a read rule, and these have none: '
				. implode(', ', array_map('strval', array_keys($offenders)))
			);
		}
	}//end checkRevealAudit()

	/**
	 * Refuse a broken `authorization.matrix` at save (row B13).
	 *
	 * THIS ONE THROWS for the same reason the hierarchy validator does: the
	 * block decides who reaches which objects, and every way of getting it
	 * wrong is silent afterwards. A matrix on `afdeling` where the schema
	 * declares `department` compiles to a condition on a column that does not
	 * exist, which the SQL builder answers by DROPPING the predicate, and a
	 * rule meant to narrow a group to its own department becomes an
	 * unconditional grant to the whole group. There is no error anywhere on
	 * that path; there is only a group that can suddenly read everything.
	 *
	 * @param Schema $schema The schema being saved.
	 *
	 * @return void
	 *
	 * @throws Exception When the matrix cannot be compiled.
	 *
	 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
	 */
	public function checkDepartmentMatrix(Schema $schema): void {
		$authorization = $schema->getAuthorization();
		if (is_array($authorization) === false || array_key_exists(DepartmentMatrixCompilerInterface::KEY, $authorization) === false) {
			return;
		}

		$findings = AuthorizationDeclarationValidators::departmentMatrix(declaredActions: $schema->declaredActionNames())->validate(
			properties: $schema->getProperties(),
			authorization: $authorization
		);
		if (count($findings) > 0) {
			$messages = array_map(static fn (array $finding) => $finding['message'], $findings);
			throw new Exception('authorization.matrix: ' . implode(' ', $messages));
		}
	}//end checkDepartmentMatrix()

	/**
	 * Refuse a broken `x-openregister-hierarchy` declaration at save.
	 *
	 * THIS ONE THROWS, and the reason is what the annotation does: it names the
	 * edge a GRANT travels down. An author who points it at the wrong property
	 * has not written a cosmetic mistake. `assignee` on a case references a
	 * USER, so a hierarchy declared over it would hand everybody who may read
	 * one object every object filed to the same person, and from that moment on
	 * it is indistinguishable from working inheritance. The save is the only
	 * point at which the two can be told apart.
	 *
	 * An unknown key inside the block is surfaced and ignored: it declares
	 * nothing, so dropping it loses nothing, and refusing it would cost the
	 * register every object of that schema at import time.
	 *
	 * @param Schema $schema The schema being saved.
	 *
	 * @return void
	 *
	 * @throws Exception When the declaration cannot be honoured.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function checkHierarchy(Schema $schema): void {
		$annotation = new SchemaAnnotation(schema: $schema, key: HierarchyGrantExpanderInterface::ANNOTATION);
		if ($annotation->hasValue() === false) {
			return;
		}

		$validator = AuthorizationDeclarationValidators::hierarchy();
		$split = $validator->partition(findings: $validator->validate($this->hierarchyShape(annotation: $annotation, schema: $schema)));
		if (count($split['warnings']) > 0) {
			$this->logger->warning($annotation->faults()->unknownKeysNotice(warnings: $split['warnings']));
		}

		$annotation->faults()->refuse(errors: $split['errors']);
	}//end checkHierarchy()

	/**
	 * The hierarchy with the schema's identities, as the validator reads it.
	 *
	 * The id and the uuid, because the import path rewrites every `$ref` to the
	 * resolved schema id before the schema reaches this validator. Without them
	 * a self-reference that was written as the slug arrives as "169" and the
	 * whole schema is refused. See identitiesOf().
	 *
	 * @param SchemaAnnotation $annotation The annotation.
	 * @param Schema           $schema     The schema being saved.
	 *
	 * @return array<string, mixed>
	 */
	private function hierarchyShape(SchemaAnnotation $annotation, Schema $schema): array {
		return $annotation->shape() + [
			'slug' => (string)($schema->getSlug() ?? ''),
			'id' => (string)($schema->getId() ?? ''),
			'uuid' => (string)($schema->getUuid() ?? ''),
			'title' => (string)($schema->getTitle() ?? ''),
		];
	}//end hierarchyShape()

	/**
	 * Whether a property audits its reveal while no `read` rule keeps anyone out.
	 *
	 * @param mixed $config The property definition.
	 *
	 * @return bool
	 */
	private function auditsWithoutRead(mixed $config): bool {
		$authorization = null;
		if (is_array($config) === true) {
			$authorization = ($config['authorization'] ?? null);
		}

		if (is_array($authorization) === false || ($authorization[RevealCollectorInterface::AUDIT_KEY] ?? null) !== true) {
			return false;
		}

		$read = ($authorization['read'] ?? null);

		return is_array($read) === false || count($read) === 0;
	}//end auditsWithoutRead()
}//end class
