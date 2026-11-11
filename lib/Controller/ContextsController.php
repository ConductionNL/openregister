<?php

/**
 * OpenRegister Contexts Controller
 *
 * Read-only endpoints that serve dereferenceable JSON-LD `@context` documents
 * for registers and schemas. These are the URLs object serializations reference
 * in their `@context`. Context documents contain structure only (terms, IRIs,
 * coercions) — never object data. An anonymous caller is served only the
 * schemas it may read: a register's context covers its published schemas,
 * and a register or schema with none is not found.
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

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Controller\Support\ContextsControllerServices;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Service\JsonLd\JsonLdContextServiceInterface;
use OCA\OpenRegister\Service\Object\PermissionCheckInterface;
use OCA\OpenRegister\Service\RegisterScopedSchemaResolverInterface;
use OCA\OpenRegister\Service\RegisterScopedSchemaResolvers;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Serves JSON-LD context documents.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/json-ld-output/spec.md
 */
class ContextsController extends Controller {
	/**
	 * The register and schema mappers.
	 *
	 * @var SchemaCatalogInterface
	 */
	private readonly SchemaCatalogInterface $catalog;

	/**
	 * The JSON-LD context service.
	 *
	 * @var JsonLdContextServiceInterface
	 */
	private readonly JsonLdContextServiceInterface $contextService;

	/**
	 * The session, to tell a signed-in caller from an anonymous one.
	 *
	 * @var IUserSession
	 */
	private readonly IUserSession $userSession;

	/**
	 * Answers whether a schema is readable anonymously.
	 *
	 * @var PermissionCheckInterface
	 */
	private readonly PermissionCheckInterface $permissions;

	/**
	 * Constructor.
	 *
	 * @param IRequest                      $request        The current request.
	 * @param ContextsControllerServices $services The controller's collaborators.
	 */
	public function __construct(
		IRequest $request,
		ContextsControllerServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->catalog = $services->catalog;
		$this->contextService = $services->contextService;
		$this->userSession = $services->userSession;
		$this->permissions = $services->permissions;
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
			$registerEntity = $this->catalog->registers()->find($register);
		} catch (DoesNotExistException|\Exception $e) {
			return new DataResponse(['error' => 'Register not found'], Http::STATUS_NOT_FOUND);
		}

		$schemas = $this->visibleSchemas(schemas: $this->loadSchemas(register: $registerEntity));
		if ($schemas === [] && $this->isAnonymous() === true) {
			return new DataResponse(['error' => 'Register not found'], Http::STATUS_NOT_FOUND);
		}

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
			$registerEntity = $this->catalog->registers()->find($register);
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
			$schemaEntity = (RegisterScopedSchemaResolvers::over(
				registerMapper: $this->catalog->registers(),
				schemaMapper: $this->catalog->schemas()
			))->resolveSchemaWithin(register: $registerEntity, schemaRef: $schema);
		} catch (DoesNotExistException|\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}

		if ($this->visibleSchemas(schemas: [$schemaEntity]) === []) {
			return new DataResponse(['error' => 'Schema not found'], Http::STATUS_NOT_FOUND);
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
				$schemas[] = $this->catalog->schemas()->find($schemaId);
			} catch (\Throwable $e) {
				// Skip unresolvable schema references; the context document
				// covers the schemas that actually exist.
				continue;
			}
		}

		return $schemas;
	}//end loadSchemas()

	/**
	 * The schemas this caller may see: all of them when signed in, else only
	 * the published ones (readable anonymously).
	 *
	 * @param array<Schema> $schemas The schemas.
	 *
	 * @return array<Schema> The visible schemas.
	 */
	private function visibleSchemas(array $schemas): array {
		if ($this->isAnonymous() === false) {
			return $schemas;
		}

		return array_values(array_filter($schemas, fn (Schema $schema): bool => $this->isPublished(schema: $schema)));
	}//end visibleSchemas()

	/**
	 * Whether an anonymous caller may read the schema's objects; a check that fails refuses.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return bool True when the schema is published.
	 */
	private function isPublished(Schema $schema): bool {
		try {
			return $this->permissions->hasPermission(schema: $schema, action: 'read');
		} catch (\Throwable $e) {
			return false;
		}
	}//end isPublished()

	/**
	 * Whether no user is signed in.
	 *
	 * @return bool True for an anonymous caller.
	 */
	private function isAnonymous(): bool {
		return $this->userSession->getUser() === null;
	}//end isAnonymous()

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
		$parts = [self::updatedStamp(updated: $register->getUpdated())];

		foreach ($schemas as $schema) {
			if (($schema instanceof Schema) === false) {
				continue;
			}

			$parts[] = ((string)$schema->getId()) . '-' . self::updatedStamp(updated: $schema->getUpdated());
		}

		return '"' . hash(algo: 'xxh128', data: implode(':', $parts)) . '"';
	}//end buildEtag()

	/**
	 * An update moment as epoch seconds, or '0' for one never updated.
	 *
	 * @param \DateTimeInterface|null $updated The moment
	 *
	 * @return string The stamp
	 */
	private static function updatedStamp(?\DateTimeInterface $updated): string {
		if ($updated !== null) {
			return $updated->format('U');
		}

		return '0';
	}//end updatedStamp()

	/**
	 * Build a JSON-LD context DataResponse with ETag/Cache-Control headers,
	 * honouring a matching `If-None-Match` with a 304. A signed-in caller may
	 * see unpublished schemas, so its response is never cached as public.
	 *
	 * @param array<string, mixed> $document The `{"@context": {...}}` document.
	 * @param string $etag The ETag value.
	 *
	 * @return DataResponse The response (200 or 304), with JSON-LD headers.
	 */
	private function contextResponse(array $document, string $etag): DataResponse {
		$cacheControl = 'public, max-age=3600';
		if ($this->isAnonymous() === false) {
			$cacheControl = 'private, max-age=3600';
		}

		$ifNoneMatch = $this->request->getHeader('If-None-Match');
		if ($ifNoneMatch !== '' && trim($ifNoneMatch) === $etag) {
			$notModified = new DataResponse([], Http::STATUS_NOT_MODIFIED);
			$notModified->addHeader('ETag', $etag);
			$notModified->addHeader('Cache-Control', $cacheControl);
			return $notModified;
		}

		$response = new DataResponse($document);
		$response->addHeader('Content-Type', 'application/ld+json');
		$response->addHeader('ETag', $etag);
		$response->addHeader('Cache-Control', $cacheControl);
		return $response;
	}//end contextResponse()
}//end class
