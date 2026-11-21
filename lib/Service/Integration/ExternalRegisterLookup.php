<?php

/**
 * ExternalRegisterLookup — the answer the external-register leaf renders:
 * one record of a sourced schema, read by key, with the reason when there
 * is none.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/external-register-view-leaf/specs/object-source-providers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Object\PermissionCheckInterface;
use OCA\OpenRegister\Service\ObjectSource\OpenConnectorHttpProvider;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads one external-register record for the leaf, through the schema's
 * object source, and says why when there is none.
 *
 * The record is read live and never copied into the host object. The
 * caller's read right on the sourced schema is asked first, every time, and a
 * refusal is the same null as a schema that does not exist, so the endpoint
 * cannot be used to learn which registers are configured. Only then is a
 * cached answer reused, for as long as
 * {@see ExternalRegisterDegrade::cacheSecondsFor()} allows.
 *
 * @spec openspec/changes/external-register-view-leaf/specs/object-source-providers/spec.md
 */
class ExternalRegisterLookup implements ExternalRegisterLookupInterface {

	/**
	 * The object-source provider this leaf reads through.
	 *
	 * @var string
	 */
	private const PROVIDER = OpenConnectorHttpProvider::ID;

	/**
	 * Constructor.
	 *
	 * @param RegisterMapperInterface   $registers   Resolves the register.
	 * @param SchemaMapperInterface     $schemas     Resolves the sourced schema.
	 * @param PermissionCheckInterface  $permissions The caller's read right on the schema.
	 * @param OpenConnectorHttpProvider $http        Reads the record from the source.
	 * @param ExternalRegisterDegrade   $degrade     Names the state of the read.
	 * @param ICacheFactory             $caches      Holds an answer as long as its state allows.
	 * @param ITimeFactory              $time        When the record was fetched.
	 * @param LoggerInterface           $logger      Logs a lookup that could not be resolved.
	 *
	 * @spec openspec/changes/external-register-view-leaf/specs/object-source-providers/spec.md
	 */
	public function __construct(
		private readonly RegisterMapperInterface $registers,
		private readonly SchemaMapperInterface $schemas,
		private readonly PermissionCheckInterface $permissions,
		private readonly OpenConnectorHttpProvider $http,
		private readonly ExternalRegisterDegrade $degrade,
		private readonly ICacheFactory $caches,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The record of a sourced schema by key, or null when the caller may not
	 * read that schema or it does not exist.
	 *
	 * @param string $register The register id or slug.
	 * @param string $schema   The sourced schema id or slug.
	 * @param string $key      The key, as the host object holds it.
	 * @param bool   $refresh  Read the source again, ignoring a cached answer.
	 *
	 * @return array{state:string,adminActionable:bool,record:array<string,mixed>|null,fetchedAt:string,source:string|null,cacheSeconds:int}|null
	 *
	 * @spec openspec/changes/external-register-view-leaf/specs/object-source-providers/spec.md#requirement-an-external-register-record-renders-on-another-object
	 */
	public function read(string $register, string $schema, string $key, bool $refresh=false): ?array {
		$schemaEntity = $this->readableSchema(register: $register, schema: $schema);
		if ($schemaEntity === null) {
			return null;
		}

		$cacheKey = hash('sha256', $register . "\n" . $schema . "\n" . trim($key));
		$cache    = $this->caches->createDistributed('openregister-external-register');
		if ($refresh === false) {
			$cached = $this->cached(cache: $cache, cacheKey: $cacheKey);
			if ($cached !== null) {
				return $cached;
			}
		}

		$answer = $this->answer(schema: $schemaEntity, key: $key);
		if ($answer['cacheSeconds'] > 0) {
			$cache->set($cacheKey, $answer, $answer['cacheSeconds']);
		}

		return $answer;
	}//end read()

	/**
	 * The schema, when it exists in the register and the caller may read it.
	 *
	 * @param string $register The register id or slug.
	 * @param string $schema   The schema id or slug.
	 *
	 * @return Schema|null The schema, or null either way it cannot be read.
	 */
	private function readableSchema(string $register, string $schema): ?Schema {
		try {
			$registerEntity = $this->registers->find(id: $register);
			$schemaEntity   = $this->schemas->find(id: $schema);
		} catch (Throwable $e) {
			$this->logger->info('[ExternalRegister] no such register or schema: ' . $e->getMessage());
			return null;
		}

		$allowed = $this->permissions->hasPermission(
			schema: $schemaEntity,
			action: 'read',
			register: (string)$registerEntity->getId()
		);
		if ($allowed !== true) {
			return null;
		}

		return $schemaEntity;
	}//end readableSchema()

	/**
	 * A cached answer, when there is one in the expected shape.
	 *
	 * @param ICache $cache    The cache.
	 * @param string $cacheKey The answer's key.
	 *
	 * @return array<string,mixed>|null The answer, or null when none is held.
	 */
	private function cached(ICache $cache, string $cacheKey): ?array {
		$cached = $cache->get($cacheKey);
		if (is_array($cached) === false || isset($cached['state']) === false) {
			return null;
		}

		return $cached;
	}//end cached()

	/**
	 * Read the source and name the state.
	 *
	 * A schema that is not served by the HTTP object-source provider has no
	 * external register behind it: that is the source-absent state, explained,
	 * not an error.
	 *
	 * @param Schema $schema The sourced schema.
	 * @param string $key    The key.
	 *
	 * @return array{state:string,adminActionable:bool,record:array<string,mixed>|null,fetchedAt:string,source:string|null,cacheSeconds:int}
	 */
	private function answer(Schema $schema, string $key): array {
		$declaration = $schema->getObjectSource();
		$config      = [];
		$lookup      = ['key' => trim($key), 'appInstalled' => false];
		if ($declaration !== null && ($declaration['provider'] ?? null) === self::PROVIDER) {
			$config = (array)($declaration['config'] ?? []);
			$lookup = $this->http->lookup(config: $config, key: $key);
		}

		$evaluated = $this->degrade->evaluate(lookup: $lookup);
		$source    = trim((string)($config['source'] ?? ''));
		if ($source === '') {
			$source = null;
		}

		return [
			'state' => $evaluated['state'],
			'adminActionable' => $evaluated['adminActionable'],
			'record' => $evaluated['record'],
			'fetchedAt' => gmdate('Y-m-d\TH:i:s\Z', $this->time->getTime()),
			'source' => $source,
			'cacheSeconds' => $this->degrade->cacheSecondsFor(state: $evaluated['state']),
		];
	}//end answer()
}//end class
