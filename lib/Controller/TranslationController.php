<?php

/**
 * OpenRegister Translation Controller
 *
 * REST endpoints for the translations sidecar:
 *
 *   GET  /api/translations/search             — full-text + filters
 *   GET  /api/translations/object/{uuid}      — list slots + completeness
 *   POST /api/translations/object/{uuid}/{property}/{language}/status
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

use InvalidArgumentException;
use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Controller\Support\BulkTranslation;
use OCA\OpenRegister\Controller\Support\TranslationControllerServices;
use OCA\OpenRegister\Controller\Support\TranslationFilter;
use OCA\OpenRegister\Controller\Support\TranslationSearchOptions;
use OCA\OpenRegister\Controller\Support\TranslationSlot;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Db\MagicMapper\MagicObjectFinderInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Db\Translation;
use OCA\OpenRegister\Db\TranslationFilter as RowFilter;
use OCA\OpenRegister\Db\TranslationMapperInterface;
use OCA\OpenRegister\Db\TranslationSlot as RowSlot;
use OCA\OpenRegister\Service\BulkTranslationServiceInterface;
use OCA\OpenRegister\Service\TranslationSearch;
use OCA\OpenRegister\Service\TranslationStatusServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Controller for translation operations.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class TranslationController extends Controller {
	/**
	 * The translation status service.
	 *
	 * @var TranslationStatusServiceInterface
	 */
	private readonly TranslationStatusServiceInterface $statusService;

	/**
	 * The translation mapper.
	 *
	 * @var TranslationMapperInterface
	 */
	private readonly TranslationMapperInterface $translationMapper;

	/**
	 * The schema mapper.
	 *
	 * @var SchemaMapperInterface
	 */
	private readonly SchemaMapperInterface $schemaMapper;

	/**
	 * The bulk translation service.
	 *
	 * @var BulkTranslationServiceInterface
	 */
	private readonly BulkTranslationServiceInterface $bulkService;

	/**
	 * The object mapper.
	 *
	 * @var MagicObjectFinderInterface
	 */
	private readonly MagicObjectFinderInterface $objectMapper;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The current request.
	 * @param TranslationControllerServices $services The translation controller services.
	 */
	public function __construct(
		IRequest $request,
		TranslationControllerServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->statusService = $services->statusService;
		$this->translationMapper = $services->mappers->translationMapper;
		$this->schemaMapper = $services->mappers->schemaMapper;
		$this->bulkService = $services->bulkService;
		$this->objectMapper = $services->mappers->objectMapper;
	}//end __construct()

	/**
	 * Search the translations sidecar.
	 *
	 * Supports the i18n-source-of-truth filters in addition to the existing
	 * status/language/object filters:
	 * - `?sourceLanguage=<bcp47>` — narrow to rows whose canonical source
	 *   language equals the given tag.
	 * - `?isOutOfDate=true` — narrow to rows in `outdated` status.
	 * - `?compareToSource=true` — return both `value` and `sourceValue`
	 *   side-by-side per matched row so editorial tooling can render the
	 *   source / target pair without a follow-up round trip.
	 *
	 * @param string|null $query Optional full-text query.
	 * @param string|null $language Optional language filter.
	 * @param string|null $status Optional status filter.
	 * @param string|null $objectUuid Optional object UUID filter.
	 * @param int|null $limit Maximum number of results.
	 * @param string|null $sourceLanguage Optional source-language filter.
	 * @param string|null $isOutOfDate When set to "true"/"1", restrict to outdated status.
	 * @param string|null $compareToSource When set to "true"/"1", attach `sourceValue` per row.
	 *
	 * @return JSONResponse JSON response with results and count.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/i18n-source-of-truth/tasks.md#phase-3
	 */
	public function search(
		string|null $query = null,
		string|null $language = null,
		string|null $status = null,
		string|null $objectUuid = null,
		int|null $limit = 100,
		string|null $sourceLanguage = null,
		string|null $isOutOfDate = null,
		string|null $compareToSource = null,
	): JSONResponse {
		$filter = new TranslationFilter(language: $language, status: $status, objectUuid: $objectUuid, sourceLanguage: $sourceLanguage);
		$listing = new ListQuery(limit: max(1, min(1000, (int)($limit ?? 100))), search: $query);
		$options = new TranslationSearchOptions(listing: $listing, isOutOfDate: $isOutOfDate, compareToSource: $compareToSource);

		return $this->searchRows(filter: $filter, options: $options);
	}//end search()

	/**
	 * The rows a search finds, with each row's source value when the options ask for it.
	 *
	 * @param TranslationFilter        $filter  The row filters.
	 * @param TranslationSearchOptions $options The listing and the switches.
	 *
	 * @return JSONResponse JSON response with results and count.
	 */
	private function searchRows(TranslationFilter $filter, TranslationSearchOptions $options): JSONResponse {
		if ($options->withSource() === true) {
			$rows = $this->rowsWithSource(filter: $filter, options: $options);
		} else {
			$rows = $this->plainRows(filter: $filter, options: $options);
		}

		return new JSONResponse(['results' => $rows, 'count' => count($rows)]);
	}//end searchRows()

	/**
	 * The rows a search finds.
	 *
	 * @param TranslationFilter        $filter  The row filters.
	 * @param TranslationSearchOptions $options The listing and the switches.
	 *
	 * @return array The rows.
	 */
	private function plainRows(TranslationFilter $filter, TranslationSearchOptions $options): array {
		return $this->statusService->search(
			new TranslationSearch(
				filter: new RowFilter(
					language: $filter->language,
					status: $filter->status,
					objectUuid: $filter->objectUuid,
					sourceLanguage: $filter->sourceLanguage
				),
				query: $options->listing->search,
				limit: $options->listing->limit,
				isOutOfDate: $options->outdatedOnly()
			)
		);
	}//end plainRows()

	/**
	 * The rows a search finds, each with its source value beside it.
	 *
	 * @param TranslationFilter        $filter  The row filters.
	 * @param TranslationSearchOptions $options The listing and the switches.
	 *
	 * @return array The rows.
	 */
	private function rowsWithSource(TranslationFilter $filter, TranslationSearchOptions $options): array {
		return $this->statusService->searchWithSourceValues(
			new TranslationSearch(
				filter: new RowFilter(
					language: $filter->language,
					status: $filter->status,
					objectUuid: $filter->objectUuid,
					sourceLanguage: $filter->sourceLanguage
				),
				query: $options->listing->search,
				limit: $options->listing->limit,
				isOutOfDate: $options->outdatedOnly()
			)
		);
	}//end rowsWithSource()


	/**
	 * List every translation slot for one object + completeness summary.
	 *
	 * Query parameter `schema` (id/uuid/slug) is required to compute
	 * completeness against the schema's translatable-property total.
	 *
	 * @param string $uuid The object UUID.
	 * @param string|null $schema Optional schema ref (id/uuid/slug).
	 *
	 * @return JSONResponse JSON response with translations and completeness.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/register-i18n/spec.md
	 */
	public function showByObject(string $uuid, ?string $schema = null): JSONResponse {
		$rows = $this->translationMapper->findByObject($uuid);
		$serialised = array_map(fn (Translation $t) => $t->jsonSerialize(), $rows);

		return new JSONResponse(
			[
				'translations' => $serialised,
				'completeness' => $this->completenessFor(uuid: $uuid, schema: $schema),
			]
		);
	}//end showByObject()

	/**
	 * Completeness of one object against its schema, or none without a schema.
	 *
	 * @param string      $uuid   The object UUID.
	 * @param string|null $schema Optional schema ref (id/uuid/slug).
	 *
	 * @return array Completeness summary, or an empty array.
	 */
	private function completenessFor(string $uuid, ?string $schema): array {
		if ($schema === null || $schema === '') {
			return [];
		}

		$resolvedSchema = $this->resolveSchema(ref: $schema);
		if ($resolvedSchema === null) {
			return [];
		}

		return $this->statusService->completenessForObject($uuid, $resolvedSchema);
	}//end completenessFor()

	/**
	 * Promote / change the workflow status of one translation slot.
	 *
	 * Body: `{status: "human_reviewed"}`.
	 *
	 * @param string $uuid The object UUID.
	 * @param string $property The property name.
	 * @param string $language The language code.
	 * @param string|null $status The new workflow status.
	 *
	 * @return JSONResponse JSON response with the updated row.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/register-i18n/spec.md
	 */
	public function setStatus(string $uuid, string $property, string $language, ?string $status = null): JSONResponse {
		return $this->setSlotStatus(slot: new TranslationSlot(uuid: $uuid, property: $property, language: $language), status: $status);
	}//end setStatus()

	/**
	 * Change the workflow status of one translation slot.
	 *
	 * @param TranslationSlot $slot   The slot the route names.
	 * @param string|null     $status The new workflow status.
	 *
	 * @return JSONResponse JSON response with the updated row.
	 */
	private function setSlotStatus(TranslationSlot $slot, ?string $status): JSONResponse {
		if ($status === null || $status === '') {
			return new JSONResponse(
				['error' => 'status is required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$row = $this->statusService->setStatus(new RowSlot(objectUuid: $slot->uuid, property: $slot->property, language: $slot->language), $status);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($row->jsonSerialize());
	}//end setSlotStatus()

	/**
	 * Bulk-translate one object's translatable properties.
	 *
	 * Translates from `from` to `to` using the configured TranslationProvider.
	 *
	 * Body: `{from: "nl", to: "en", properties?: ["title", "body"]}`.
	 * Returns `{translated: {prop: value}, skipped: {prop: reason}}`.
	 * Caller must persist the returned `translated` map onto the
	 * object to make the JSONB authoritative; the sidecar is updated
	 * in-place by the service so search sees the new translations
	 * before persistence.
	 *
	 * @param string $uuid The object UUID.
	 * @param string|null $from Source language code.
	 * @param string|null $to Target language code.
	 * @param string[]|null $properties Optional whitelist of property names.
	 *
	 * @return JSONResponse JSON response with translation result.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/register-i18n/spec.md
	 */
	public function bulkTranslate(
		string $uuid,
		?string $from = null,
		?string $to = null,
		?array $properties = null,
	): JSONResponse {
		return $this->translateRequested(uuid: $uuid, translation: new BulkTranslation(from: $from, to: $to, properties: $properties));
	}//end bulkTranslate()

	/**
	 * Refuse a translation without both languages, or translate the object.
	 *
	 * @param string          $uuid        The object UUID.
	 * @param BulkTranslation $translation The languages and properties asked for.
	 *
	 * @return JSONResponse The translation result, 400 or 404.
	 */
	private function translateRequested(string $uuid, BulkTranslation $translation): JSONResponse {
		if ($translation->languagesMissing() === true) {
			return new JSONResponse(['error' => 'from and to are required'], Http::STATUS_BAD_REQUEST);
		}

		return $this->translateLoaded(uuid: $uuid, translation: $translation);
	}//end translateRequested()

	/**
	 * Load the object and translate it, or answer 404.
	 *
	 * @param string          $uuid        The object UUID.
	 * @param BulkTranslation $translation The languages, both present, and the properties.
	 *
	 * @return JSONResponse The translation result, or 404.
	 */
	private function translateLoaded(string $uuid, BulkTranslation $translation): JSONResponse {
		$object = $this->loadObject(uuid: $uuid);
		if ($object === null) {
			return new JSONResponse(['error' => 'object not found', 'uuid' => $uuid], Http::STATUS_NOT_FOUND);
		}

		$result = $this->bulkService->translateObject(
			object: $object,
			fromLong: $translation->from,
			toLong: $translation->to,
			properties: $translation->properties
		);

		return new JSONResponse(
			[
				'uuid' => $uuid,
				'from' => $translation->from,
				'to' => $translation->to,
				'translated' => $result['translated'],
				'skipped' => $result['skipped'],
			]
		);
	}//end translateLoaded()

	/**
	 * Load an object by UUID; returns null when not found.
	 *
	 * @param string $uuid The object UUID.
	 *
	 * @return ObjectEntity|null The object entity or null.
	 */
	private function loadObject(string $uuid): ?ObjectEntity {
		try {
			return $this->objectMapper->find($uuid);
		} catch (DoesNotExistException $e) {
			return null;
		} catch (\Throwable $e) {
			return null;
		}
	}//end loadObject()

	/**
	 * Resolve a schema by id/uuid/slug; returns null when not found.
	 *
	 * @param string $ref The schema reference (id/uuid/slug).
	 *
	 * @return Schema|null The schema entity or null.
	 */
	private function resolveSchema(string $ref): ?Schema {
		try {
			return $this->schemaMapper->find($ref, _rbac: false, _multitenancy: false);
		} catch (DoesNotExistException $e) {
			return null;
		} catch (\Throwable $e) {
			return null;
		}
	}//end resolveSchema()
}//end class
