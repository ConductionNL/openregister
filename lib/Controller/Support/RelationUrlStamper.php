<?php

/**
 * OpenRegister relation url stamper
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use Psr\Log\LoggerInterface;

/**
 * Stamps the url an object opens at on each record of a relation envelope.
 *
 * Asks the deep link registry, the resolver the unified-search provider uses,
 * and falls back to OpenRegister's own object route. A failure omits the url
 * of that record and leaves the record itself as it is.
 *
 * @spec openspec/specs/object-interactions/spec.md
 */
final class RelationUrlStamper {

	/**
	 * Constructor.
	 *
	 * @param LinkedDataServices $linkedData The deep link registry and url generator.
	 * @param LoggerInterface    $logger     The logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly LinkedDataServices $linkedData,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The envelope with a url on each record that has one.
	 *
	 * @param array $result The relation envelope ({results, total, ...}).
	 *
	 * @return array The envelope, its serializable records as arrays.
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	public function stamped(array $result): array {
		if ($this->linkedData->deepLinkRegistry === null || is_array($result['results'] ?? null) === false) {
			return $result;
		}

		foreach ($result['results'] as $index => $record) {
			$row = $this->rowOf(record: $record);
			if ($row !== null) {
				$result['results'][$index] = $this->stampedRow(row: $row);
			}
		}

		return $result;
	}//end stamped()

	/**
	 * A record as an array.
	 *
	 * @param mixed $record The record: an array, or an entity that serializes.
	 *
	 * @return array|null The row, or null for anything else.
	 */
	private function rowOf(mixed $record): ?array {
		if (is_object($record) === true && method_exists($record, 'jsonSerialize') === true) {
			$record = $record->jsonSerialize();
		}

		if (is_array($record) === true) {
			return $record;
		}

		return null;
	}//end rowOf()

	/**
	 * A row with its url, when it identifies an object and a url resolves.
	 *
	 * @param array $row The row.
	 *
	 * @return array The row.
	 */
	private function stampedRow(array $row): array {
		$identity = $this->identityOf(row: $row);
		if ($identity === null) {
			return $row;
		}

		try {
			$url = $this->urlOf(row: $row, identity: $identity);
			if ($url !== null) {
				$row['url'] = $url;
			}
		} catch (\Throwable $e) {
			$this->logger->debug('Relation URL resolution failed: '.$e->getMessage());
		}

		return $row;
	}//end stampedRow()

	/**
	 * The uuid, register and schema of a row.
	 *
	 * @param array $row The row.
	 *
	 * @return array{uuid: mixed, id: mixed, register: mixed, schema: mixed}|null Null when one is missing.
	 */
	private function identityOf(array $row): ?array {
		$self     = ($row['@self'] ?? []);
		$uuid     = ($self['id'] ?? ($row['id'] ?? null));
		$register = ($self['register'] ?? null);
		$schema   = ($self['schema'] ?? null);

		if ($uuid === null || is_numeric($register) === false || is_numeric($schema) === false) {
			return null;
		}

		return ['uuid' => $uuid, 'id' => $uuid, 'register' => $register, 'schema' => $schema];
	}//end identityOf()

	/**
	 * The url of a row: the registered deep link, or else the object route.
	 *
	 * @param array                                                         $row      The row.
	 * @param array{uuid: mixed, id: mixed, register: mixed, schema: mixed} $identity The row's identity.
	 *
	 * @return string|null The url.
	 */
	private function urlOf(array $row, array $identity): ?string {
		$url = $this->linkedData->deepLinkRegistry->resolveUrl(
			registerId: (int)$identity['register'],
			schemaId: (int)$identity['schema'],
			objectData: array_merge($row, $identity)
		);

		return ($url ?? $this->routeUrl(identity: $identity));
	}//end urlOf()

	/**
	 * The url of OpenRegister's own object route.
	 *
	 * @param array{uuid: mixed, id: mixed, register: mixed, schema: mixed} $identity The row's identity.
	 *
	 * @return string|null The url, or null without a url generator.
	 */
	private function routeUrl(array $identity): ?string {
		return $this->linkedData->relationUrlGenerator?->linkToRoute(
			'openregister.objects.show',
			['register' => $identity['register'], 'schema' => $identity['schema'], 'id' => $identity['uuid']]
		);
	}//end routeUrl()
}//end class
