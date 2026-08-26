<?php

/**
 * One implementation of `{register}/{schema}` path resolution.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Trait
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Trait;

use OCA\OpenRegister\Exception\RegisterNotFoundException;
use OCA\OpenRegister\Exception\SchemaNotFoundException;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Resolves a `{register}/{schema}` path pair to numeric ids.
 *
 * WHY THIS IS A TRAIT AND NOT A THIRD COPY
 *
 * `ObjectsController` and `BulkController` each carried their own private
 * `resolveRegisterSchemaIds()`. They were identical until #2858 and #2860 fixed
 * one of them, and then they were not — so `GET /api/objects/19/9476` started
 * working while `POST /api/bulk/19/9475/save` kept answering
 * `404 Register not found: '19'` for a register that plainly exists.
 *
 * That is openregister#2820 surviving its own fix in the copy nobody edited.
 * Fixing the second copy would leave the same trap set for the third, so the
 * implementation now has one home and both controllers use it.
 *
 * Two things it must keep doing, both of them load-bearing:
 *
 * 1. `clearCurrents()` FIRST. `ObjectService` is shared within a request, and a
 *    schema ref left pending by an earlier caller is otherwise re-resolved
 *    inside whichever register THIS call names — a register it was never meant
 *    for. That is the #2820 leak.
 *
 * 2. Report a schema failure as a SCHEMA failure. `setRegister()` assigns
 *    `currentRegister` BEFORE re-resolving any pending ref, so if the entity
 *    changed the register resolved fine and the throw came from the schema
 *    side. Without that discriminator the endpoint blames a register that
 *    demonstrably exists, which is what made #2820 cost hours to find: every
 *    reasonable first move — check the row, the magic tables, the organisation
 *    filter, run the query in psql — investigates the wrong thing.
 */
trait ResolvesRegisterAndSchemaTrait {
	/**
	 * Resolve a register/schema path pair to numeric ids.
	 *
	 * @param string        $register      Register slug, uuid or numeric id.
	 * @param string        $schema        Schema slug, uuid or numeric id.
	 * @param ObjectService $objectService The request's object service.
	 *
	 * @return array{register: mixed, schema: mixed, registerEntity: mixed, schemaEntity: mixed}
	 *
	 * @throws RegisterNotFoundException When the register itself does not resolve.
	 * @throws SchemaNotFoundException   When the schema does not resolve in it.
	 *
	 * @spec openspec/specs/register-scoped-slug-resolution/spec.md
	 */
	protected function resolveRegisterAndSchema(
		string $register,
		string $schema,
		ObjectService $objectService,
	): array {
		// (1) Drop anything an earlier caller left on the shared service.
		$objectService->clearCurrents();

		$registerBefore = $objectService->getCurrentRegisterEntity();

		try {
			$objectService->setRegister(register: $register);
		} catch (DoesNotExistException $e) {
			// (2) Did the register resolve before the throw? If the current
			// entity moved, it did, and this is a schema failure wearing a
			// register's name.
			$registerAfter = $objectService->getCurrentRegisterEntity();
			if ($registerAfter === null || $registerAfter === $registerBefore) {
				throw new RegisterNotFoundException(
					registerSlugOrId: $register,
					code: 404,
					previous: $e
				);
			}

			throw new SchemaNotFoundException(schemaSlugOrId: $schema, code: 404, previous: $e);
		}

		try {
			$objectService->setSchema(schema: $schema);
		} catch (DoesNotExistException $e) {
			throw new SchemaNotFoundException(schemaSlugOrId: $schema, code: 404, previous: $e);
		}

		return [
			'register' => $objectService->getRegister(),
			'schema' => $objectService->getSchema(),
			'registerEntity' => $objectService->getCurrentRegisterEntity(),
			'schemaEntity' => $objectService->getCurrentSchemaEntity(),
		];
	}//end resolveRegisterAndSchema()
}//end trait
