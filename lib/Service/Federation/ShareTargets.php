<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Resolves what a federated share points at, the way serving the share resolves it.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Federation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/federation-scope-enforcement/spec.md#requirement-a-share-never-grants-more-than-its-creator-holds-req-fse-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Federation;

use OCA\OpenRegister\Db\FederatedShare;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\RegisterScopedSchemaResolverInterface;
use Throwable;

/**
 * A register or schema is resolved whoever asks; who may share it is the manage gate's call. Only the object is read as the caller.
 *
 * @spec openspec/specs/federation-scope-enforcement/spec.md#requirement-a-share-never-grants-more-than-its-creator-holds-req-fse-005
 */
class ShareTargets {

	/**
	 * Constructor.
	 *
	 * @param RegisterScopedSchemaResolverInterface $resolver      Resolves the share's register, and its schema inside that register.
	 * @param ObjectOperationsInterface                $objectService Reads the share's object as the caller.
	 */
	public function __construct(
		private readonly RegisterScopedSchemaResolverInterface $resolver,
		private readonly ObjectOperationsInterface $objectService,
	) {
	}//end __construct()

	/**
	 * The schema the share serves, or null when it names no register or schema, or they do not resolve.
	 *
	 * Resolved inside the share's register, as serving does: a slug is not unique across
	 * registers, so a global lookup could judge a different schema than the token reaches.
	 *
	 * @param FederatedShare $share The share.
	 *
	 * @return Schema|null The schema.
	 *
	 * @spec openspec/specs/federation-scope-enforcement/spec.md#requirement-a-share-never-grants-more-than-its-creator-holds-req-fse-005
	 */
	public function schema(FederatedShare $share): ?Schema {
		$register = (string)$share->getRegister();
		$schema   = (string)$share->getSchema();
		if ($register === '' || $schema === '') {
			return null;
		}

		try {
			return $this->resolver->resolvePair(registerRef: $register, schemaRef: $schema)['schema'];
		} catch (Throwable $e) {
			return null;
		}
	}//end schema()

	/**
	 * The register the share names, or null when it names none or it does not resolve.
	 *
	 * @param FederatedShare $share The share.
	 *
	 * @return Register|null The register.
	 *
	 * @spec openspec/specs/federation-scope-enforcement/spec.md#requirement-a-share-never-grants-more-than-its-creator-holds-req-fse-005
	 */
	public function register(FederatedShare $share): ?Register {
		$named = (string)$share->getRegister();
		if ($named === '') {
			return null;
		}

		try {
			return $this->resolver->resolveRegister(registerRef: $named);
		} catch (Throwable $e) {
			return null;
		}
	}//end register()

	/**
	 * Whether the caller's own read reaches the one object an object-scope share names.
	 *
	 * @param FederatedShare $share The share.
	 *
	 * @return bool True when the caller reads that object in the share's register and schema.
	 *
	 * @spec openspec/specs/federation-scope-enforcement/spec.md#requirement-a-share-never-grants-more-than-its-creator-holds-req-fse-005
	 */
	public function callerReadsObject(FederatedShare $share): bool {
		$parts = explode('/', rtrim((string)$share->getObjectUri(), '/'));
		$id    = (string)end($parts);
		if ($id === '') {
			return false;
		}

		try {
			$object = $this->objectService->find(
				id: $id,
				register: $share->getRegister(),
				schema: $share->getSchema(),
				_rbac: true,
				_multitenancy: true,
				_render: false,
				_audit: false
			);
		} catch (Throwable $e) {
			return false;
		}

		return $object !== null;
	}//end callerReadsObject()
}//end class
