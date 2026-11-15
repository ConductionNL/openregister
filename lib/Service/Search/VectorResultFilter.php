<?php

/**
 * VectorResultFilter — keeps only the vector hits the caller may open.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Search
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/search-file-text-and-vector-facade
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Search;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Files\Service\File\FileReadScope;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Search\Service\ViewService;
use OCP\IUserSession;
use Throwable;

/**
 * One read-rights filter for every vector result.
 *
 * A `file` hit is kept when the caller can open the file, an `object` hit
 * when the object read check (RBAC and multitenancy) finds the object for the
 * caller. A hit of any other type is dropped: unknown means unreadable. Each
 * distinct entity is checked once per call, however many chunks of it rank.
 *
 * @spec openspec/changes/search-file-text-and-vector-facade
 */
class VectorResultFilter {

	/**
	 * How many candidates a search asks for per result it returns, so a page
	 * the filter thinned is still full when it can be.
	 */
	public const OVER_FETCH = 3;

	/**
	 * Wire the read checks.
	 *
	 * @param FileReadScope             $files       Whether the caller can open a file.
	 * @param ObjectOperationsInterface $objects     Finds an object as the caller.
	 * @param ViewService               $views       Resolves a view the caller may use.
	 * @param IUserSession              $userSession The signed-in caller.
	 */
	public function __construct(
		private readonly FileReadScope $files,
		private readonly ObjectOperationsInterface $objects,
		private readonly ViewService $views,
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * Keep the hits the caller may open, in their original order.
	 *
	 * @param array<int, array<string, mixed>> $results Hits carrying `entity_type` and `entity_id`.
	 *
	 * @return array<int, array<string, mixed>> The readable hits.
	 *
	 * @spec openspec/changes/search-file-text-and-vector-facade
	 */
	public function readable(array $results): array {
		if ($results === [] || $this->userSession->getUser() === null) {
			return [];
		}

		$verdicts = [];
		$readable = [];
		foreach ($results as $result) {
			$key = ($result['entity_type'] ?? '') . ':' . (string)($result['entity_id'] ?? '');
			if (array_key_exists($key, $verdicts) === false) {
				$verdicts[$key] = $this->mayRead(result: $result);
			}

			if ($verdicts[$key] === true) {
				$readable[] = $result;
			}
		}

		return $readable;
	}//end readable()

	/**
	 * Narrow object hits to the registers and schemas of the given views.
	 *
	 * File hits are not in any register and pass. A view the caller may not
	 * use, or that does not exist, contributes nothing, so naming only such
	 * views leaves no object hits.
	 *
	 * @param array<int, array<string, mixed>> $results Hits.
	 * @param array<int, int|string>           $viewIds The view ids; empty means no narrowing.
	 *
	 * @return array<int, array<string, mixed>> The hits inside the views.
	 *
	 * @spec openspec/changes/search-file-text-and-vector-facade
	 */
	public function inViews(array $results, array $viewIds): array {
		if ($viewIds === []) {
			return $results;
		}

		$scopes = $this->viewScopes(viewIds: $viewIds);

		return array_values(
			array_filter(
				$results,
				fn (array $result): bool => ($result['entity_type'] ?? null) !== 'object'
					|| $this->insideAnyScope(metadata: $this->metadataOf(result: $result), scopes: $scopes)
			)
		);
	}//end inViews()

	/**
	 * Whether the caller may open the entity one hit points at.
	 *
	 * @param array<string, mixed> $result One hit.
	 *
	 * @return bool
	 */
	private function mayRead(array $result): bool {
		$entityId = $result['entity_id'] ?? null;
		if (is_int($entityId) === false && (is_string($entityId) === false || $entityId === '')) {
			return false;
		}

		return match ($result['entity_type'] ?? null) {
			'file' => $this->files->readableResults(results: [$result]) !== [],
			'object' => $this->mayReadObject(id: $entityId, metadata: $this->metadataOf(result: $result)),
			default => false,
		};
	}//end mayRead()

	/**
	 * Whether the object read check finds the object for the caller.
	 *
	 * @param int|string           $id       The object id or uuid.
	 * @param array<string, mixed> $metadata The hit's metadata, which may place it in a register and schema.
	 *
	 * @return bool
	 */
	private function mayReadObject(int|string $id, array $metadata): bool {
		try {
			$object = $this->objects->find(
				id: $id,
				options: new ObjectFindOptions(
					register: $this->scalarOf(value: $metadata['register'] ?? null),
					schema: $this->scalarOf(value: $metadata['schema'] ?? null),
					render: false,
					audit: false
				),
				_rbac: true,
				_multitenancy: true
			);
		} catch (Throwable $e) {
			return false;
		}

		return $object instanceof ObjectEntity;
	}//end mayReadObject()

	/**
	 * The registers and schemas of each usable view.
	 *
	 * @param array<int, int|string> $viewIds The view ids.
	 *
	 * @return array<int, array{registers: string[], schemas: string[]}>
	 */
	private function viewScopes(array $viewIds): array {
		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null) {
			return [];
		}

		$scopes = [];
		foreach ($viewIds as $viewId) {
			try {
				$query = ($this->views->find(id: $viewId, owner: $uid)->getQuery() ?? []);
			} catch (Throwable $e) {
				continue;
			}

			$scopes[] = [
				'registers' => $this->idList(value: $query['registers'] ?? []),
				'schemas' => $this->idList(value: $query['schemas'] ?? []),
			];
		}

		return $scopes;
	}//end viewScopes()

	/**
	 * Whether a hit's register and schema fall inside one of the scopes.
	 *
	 * An empty register or schema list in a view does not narrow on it.
	 *
	 * @param array<string, mixed>                                      $metadata The hit's metadata.
	 * @param array<int, array{registers: string[], schemas: string[]}> $scopes   The views' scopes.
	 *
	 * @return bool
	 */
	private function insideAnyScope(array $metadata, array $scopes): bool {
		$register = (string)($this->scalarOf(value: $metadata['register'] ?? null) ?? '');
		$schema = (string)($this->scalarOf(value: $metadata['schema'] ?? null) ?? '');

		foreach ($scopes as $scope) {
			$registerFits = ($scope['registers'] === [] || in_array($register, $scope['registers'], true) === true);
			$schemaFits = ($scope['schemas'] === [] || in_array($schema, $scope['schemas'], true) === true);
			if ($registerFits === true && $schemaFits === true) {
				return true;
			}
		}

		return false;
	}//end insideAnyScope()

	/**
	 * A hit's metadata as an array.
	 *
	 * @param array<string, mixed> $result One hit.
	 *
	 * @return array<string, mixed>
	 */
	private function metadataOf(array $result): array {
		$metadata = ($result['metadata'] ?? []);
		if (is_array($metadata) === false) {
			return [];
		}

		return $metadata;
	}//end metadataOf()

	/**
	 * A register or schema reference as an int or string, or null.
	 *
	 * @param mixed $value The stored reference.
	 *
	 * @return int|string|null
	 */
	private function scalarOf(mixed $value): int|string|null {
		if (is_int($value) === true || (is_string($value) === true && $value !== '')) {
			return $value;
		}

		return null;
	}//end scalarOf()

	/**
	 * A view's register or schema list as strings.
	 *
	 * @param mixed $value One id, or a list of ids.
	 *
	 * @return string[]
	 */
	private function idList(mixed $value): array {
		if (is_array($value) === false) {
			$value = [$value];
		}

		$ids = [];
		foreach ($value as $id) {
			if (is_int($id) === true || (is_string($id) === true && $id !== '')) {
				$ids[] = (string)$id;
			}
		}

		return $ids;
	}//end idList()
}//end class
