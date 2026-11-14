<?php

/**
 * OpenRegister IntegrationProviderInvoker
 *
 * Calls an integration provider operation in whichever form the provider implements.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
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

namespace OCA\OpenRegister\Service\Integration;

use OCA\OpenRegister\Exception\NotImplementedException;
use OCA\OpenRegister\Kernel\Compat\ImplementationForm;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;

/**
 * Runs an integration provider's operations, written in either published form.
 *
 * The released form takes the register, schema and object id as strings
 * where the current form takes one ObjectRef; the remaining arguments are
 * the same in both.
 *
 * @spec openspec/specs/integration-registry/spec.md
 */
final class IntegrationProviderInvoker {

	/**
	 * Constructor
	 *
	 * @param ImplementationForm $form Tells the two forms apart.
	 *
	 * @spec openspec/specs/integration-registry/spec.md
	 */
	public function __construct(
		private readonly ImplementationForm $form=new ImplementationForm(),
	) {
	}//end __construct()

	/**
	 * List the linked things for one object.
	 *
	 * @param IntegrationProvider $provider The provider.
	 * @param ObjectRef           $ref      The owning object.
	 * @param array<string,mixed> $filters  Optional list filters.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws NotImplementedException When the provider declares no list().
	 *
	 * @spec openspec/specs/integration-registry/spec.md
	 */
	public function list(IntegrationProvider $provider, ObjectRef $ref, array $filters=[]): array {
		return $this->call(provider: $provider, operation: 'list', ref: $ref, arguments: [$filters]);
	}//end list()

	/**
	 * Get a single linked thing.
	 *
	 * @param IntegrationProvider $provider The provider.
	 * @param ObjectRef           $ref      The owning object.
	 * @param string              $entityId Linked-thing id.
	 *
	 * @return array<string,mixed>
	 *
	 * @throws NotImplementedException When the provider declares no get().
	 *
	 * @spec openspec/specs/integration-registry/spec.md
	 */
	public function get(IntegrationProvider $provider, ObjectRef $ref, string $entityId): array {
		return $this->call(provider: $provider, operation: 'get', ref: $ref, arguments: [$entityId]);
	}//end get()

	/**
	 * Create / attach a linked thing.
	 *
	 * @param IntegrationProvider $provider The provider.
	 * @param ObjectRef           $ref      The owning object.
	 * @param array<string,mixed> $payload  New linked-thing fields.
	 *
	 * @return array<string,mixed>
	 *
	 * @throws NotImplementedException When the provider declares no create().
	 *
	 * @spec openspec/specs/integration-registry/spec.md
	 */
	public function create(IntegrationProvider $provider, ObjectRef $ref, array $payload): array {
		return $this->call(provider: $provider, operation: 'create', ref: $ref, arguments: [$payload]);
	}//end create()

	/**
	 * Update a linked thing.
	 *
	 * @param IntegrationProvider $provider The provider.
	 * @param ObjectRef           $ref      The owning object.
	 * @param string              $entityId Linked-thing id.
	 * @param array<string,mixed> $payload  Update payload.
	 *
	 * @return array<string,mixed>
	 *
	 * @throws NotImplementedException When the provider declares no update().
	 *
	 * @spec openspec/specs/integration-registry/spec.md
	 */
	public function update(IntegrationProvider $provider, ObjectRef $ref, string $entityId, array $payload): array {
		return $this->call(provider: $provider, operation: 'update', ref: $ref, arguments: [$entityId, $payload]);
	}//end update()

	/**
	 * Delete / unlink a linked thing.
	 *
	 * @param IntegrationProvider $provider The provider.
	 * @param ObjectRef           $ref      The owning object.
	 * @param string              $entityId Linked-thing id.
	 *
	 * @return void
	 *
	 * @throws NotImplementedException When the provider declares no delete().
	 *
	 * @spec openspec/specs/integration-registry/spec.md
	 */
	public function delete(IntegrationProvider $provider, ObjectRef $ref, string $entityId): void {
		$this->call(provider: $provider, operation: 'delete', ref: $ref, arguments: [$entityId]);
	}//end delete()

	/**
	 * Call one operation in the form the provider declares it.
	 *
	 * @param IntegrationProvider $provider  The provider.
	 * @param string              $operation `list`, `get`, `create`, `update` or `delete`.
	 * @param ObjectRef           $ref       The owning object.
	 * @param list<mixed>         $arguments The arguments after the owning object.
	 *
	 * @return mixed What the provider returned.
	 *
	 * @throws NotImplementedException When the provider does not declare the operation.
	 */
	private function call(IntegrationProvider $provider, string $operation, ObjectRef $ref, array $arguments): mixed {
		if (method_exists($provider, $operation) === false) {
			throw new NotImplementedException(
				message: sprintf('Provider %s does not support %s()', $provider->getId(), $operation)
			);
		}

		$method = [$provider, $operation];
		if ($provider instanceof RefIntegrationProvider
			|| ($provider instanceof LegacyIntegrationProvider === false
			&& $this->form->takesObject(implementer: $provider, method: $operation, parameterClass: ObjectRef::class) === true)
		) {
			return $method($ref, ...$arguments);
		}

		return $method((string)$ref->register, (string)$ref->schema, $ref->id, ...$arguments);
	}//end call()
}//end class
