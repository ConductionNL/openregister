<?php

/**
 * OpenRegister Contexts Controller
 *
 * Read-only endpoints that serve dereferenceable JSON-LD `@context` documents
 * for registers and schemas. These are the URLs object serializations reference
 * in their `@context`. Context documents contain structure only (terms, IRIs,
 * coercions) — never object data.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
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

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\JsonLd\JsonLdContextService;
use OCA\OpenRegister\Service\RegisterScopedSchemaResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * Serves JSON-LD context documents.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/json-ld-output/spec.md
 */
class ContextsController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The application name.
	 * @param IRequest $request The current request.
	 * @param RegisterMapper $registerMapper The register mapper.
	 * @param SchemaMapper $schemaMapper The schema mapper.
	 * @param JsonLdContextService $contextService The JSON-LD context service.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly JsonLdContextService $contextService,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Return the register-wide JSON-LD context document.
	 *
	 * @param string $register The register slug or identifier.
	 *
	 * @return DataResponse The `{"@context": {...}}` document, or 404/304.
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @PublicPage
	 *
	 * @spec openspec/specs/json-ld-output/spec.md
	 */
	#[AnonRateLimit(limit: 120, period: 60)]
	public function register(string $register): DataResponse {
		try {
			$registerEntity = $this->registerMapper->find($register);
		} catch (DoesNotExistException|\Exception $e) {
			return new DataResponse(['error' => 'Register not found'], Http::STATUS_NOT_FOUND);
		}

		$schemas = $this->loadSchemas(register: $registerEntity);
		$contextMap = $this->contextService->buildRegisterContext(register: $registerEntity, schemas: $schemas);
		$etag = $this->buildEtag(register: $registerEntity, schemas: $schemas);

		return $this->contextResponse(document: ['@context' => $contextMap], etag: $etag);
	}//end register()

	/**
	 * Return a per-schema JSON-LD context document.
	 *
	 * @param string $register The register slug or identifier.
	 * @param string $schema The schema slug or identifier.
	 *
	 * @return DataResponse The `{"@context": {...}}` document, or 404/304.
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @PublicPage
	 *
	 * @spec openspec/specs/json-ld-output/spec.md
	 */
	#[AnonRateLimit(limit: 120, period: 60)]
	public function schema(string $register, string $schema): DataResponse {
		try {
			$registerEntity = $this->registerMapper->find($register);
		} catch (DoesNotExistException|\Exception $e) {
			return new DataResponse(['error' => 'Register not found'], Http::STATUS_NOT_FOUND);
		}

		// REGISTER-SCOPED. The two refs used to be resolved INDEPENDENTLY, so the
		// `{register}` segment named a register whose context document then
		// described a schema from somewhere else entirely — a JSON-LD `@context`
		// is a published contract, and publishing another app's term definitions
		// under this register's URL is a durable, cacheable wrong answer.
		//
		// The sibling {@see loadSchemas()} was already scoped: it walks
		// `$register->getSchemas()`. This is the same boundary, applied to the
		// single-schema variant.
		try {
			$schemaEntity = (new RegisterScopedSchemaResolver(
				registerMapper: $this->registerMapper,
				schemaMapper: $this->schemaMapper
			))->resolveSchemaWithin(register: $registerEntity, schemaRef: $schema);
		} catch (DoesNotExistException|\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}

		$contextMap = $this->contextService->buildSchemaContext(register: $registerEntity, schema: $schemaEntity);
		$etag = $this->buildEtag(register: $registerEntity, schemas: [$schemaEntity]);

		return $this->contextResponse(document: ['@context' => $contextMap], etag: $etag);
	}//end schema()

	/**
	 * Load the schema entities belonging to a register, skipping any that fail
	 * to resolve (a stale id in register.schemas must not 500 the document).
	 *
	 * @param Register $register The register.
	 *
	 * @return array<Schema> The resolvable schema entities.
	 */
	private function loadSchemas(Register $register): array {
		$schemas = [];
		foreach ($register->getSchemas() as $schemaId) {
			try {
				$schemas[] = $this->schemaMapper->find($schemaId);
			} catch (\Throwable $e) {
				// Skip unresolvable schema references; the context document
				// covers the schemas that actually exist.
				continue;
			}
		}

		return $schemas;
	}//end loadSchemas()

	/**
	 * Build a weak ETag from the register/schema `updated` timestamps so a
	 * conditional GET can short-circuit when nothing changed.
	 *
	 * @param Register $register The register.
	 * @param array<Schema> $schemas The schemas covered by the document.
	 *
	 * @return string The ETag value (quoted).
	 */
	private function buildEtag(Register $register, array $schemas): string {
		$parts = [];
		$updated = $register->getUpdated();
		if ($updated !== null) {
			$parts[] = $updated->format('U');
		} else {
			$parts[] = '0';
		}

		foreach ($schemas as $schema) {
			if (($schema instanceof Schema) === false) {
				continue;
			}

			$schemaUpdated = $schema->getUpdated();
			if ($schemaUpdated !== null) {
				$schemaStamp = $schemaUpdated->format('U');
			} else {
				$schemaStamp = '0';
			}

			$parts[] = ((string)$schema->getId()) . '-' . $schemaStamp;
		}

		return '"' . md5(implode(':', $parts)) . '"';
	}//end buildEtag()

	/**
	 * Build a JSON-LD context DataResponse with ETag/Cache-Control headers,
	 * honouring a matching `If-None-Match` with a 304.
	 *
	 * @param array<string, mixed> $document The `{"@context": {...}}` document.
	 * @param string $etag The ETag value.
	 *
	 * @return DataResponse The response (200 or 304), with JSON-LD headers.
	 */
	private function contextResponse(array $document, string $etag): DataResponse {
		$ifNoneMatch = $this->request->getHeader('If-None-Match');
		if ($ifNoneMatch !== '' && trim($ifNoneMatch) === $etag) {
			$notModified = new DataResponse([], Http::STATUS_NOT_MODIFIED);
			$notModified->addHeader('ETag', $etag);
			$notModified->addHeader('Cache-Control', 'public, max-age=3600');
			return $notModified;
		}

		$response = new DataResponse($document);
		$response->addHeader('Content-Type', 'application/ld+json');
		$response->addHeader('ETag', $etag);
		$response->addHeader('Cache-Control', 'public, max-age=3600');
		return $response;
	}//end contextResponse()
}//end class
