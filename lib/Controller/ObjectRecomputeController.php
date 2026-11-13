<?php

/**
 * Re-derives the calculated fields of a selection of a schema's objects.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/recompute-and-reindex-on-demand/specs/computed-fields/spec.md#requirement-computed-fields-are-re-derived-on-demand-over-a-selection-req-rrd-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\BackgroundJob\CalculationRecomputeJob;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Calculation\CalculationRematerialiser;
use OCA\OpenRegister\Service\Calculation\RecomputeAccess;
use OCA\OpenRegister\Service\Calculation\RecomputeSelection;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

/**
 * `POST /api/objects/{register}/{schema}/recompute`: synchronous up to SYNC_LIMIT objects, a queued run above.
 *
 * @spec openspec/changes/recompute-and-reindex-on-demand/specs/computed-fields/spec.md#requirement-computed-fields-are-re-derived-on-demand-over-a-selection-req-rrd-001
 */
class ObjectRecomputeController extends Controller {
	/**
	 * The most objects recomputed within the request.
	 */
	public const SYNC_LIMIT = 500;

	/**
	 * Constructor.
	 *
	 * @param IRequest                  $request        The request.
	 * @param RegisterMapperInterface            $registers      Finds the register, as the caller may see it.
	 * @param SchemaMapperInterface              $schemas        Finds the schema, as the caller may see it.
	 * @param CalculationRematerialiser $rematerialiser Re-derives the objects.
	 * @param RecomputeAccess           $access         The caller's update check per object.
	 * @param IJobList                  $jobs           Queues a large recompute.
	 * @param IUserSession              $userSession    The caller.
	 */
	public function __construct(
		IRequest $request,
		private readonly RegisterMapperInterface $registers,
		private readonly SchemaMapperInterface $schemas,
		private readonly CalculationRematerialiser $rematerialiser,
		private readonly RecomputeAccess $access,
		private readonly IJobList $jobs,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Re-derive every calculated field of the selected objects.
	 *
	 * Body: exactly one of `ids` (a list) or `filters` (the object list
	 * filters; `{}` selects the whole schema), and `dryRun`. Every object is
	 * checked against the caller's update rule before anything is written: one
	 * the caller may not update is failed with reason `forbidden`.
	 *
	 * @param string $register The register id or slug.
	 * @param string $schema   The schema id or slug.
	 *
	 * @return JSONResponse 200 with the counts, 202 with the queued run, 400, 401 or 404.
	 *
	 * @psalm-return JSONResponse<200|202|400|401|404, array, array<never, never>>
	 *
	 * @spec openspec/changes/recompute-and-reindex-on-demand/specs/computed-fields/spec.md#requirement-computed-fields-are-re-derived-on-demand-over-a-selection-req-rrd-001
	 */
	#[NoAdminRequired]
	public function recompute(string $register, string $schema): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Sign in to recompute objects'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$selection = $this->selection();
		if ($selection === null) {
			return new JSONResponse(
				data: ['error' => 'Send exactly one selection: ids (a list) or filters (an object)'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$table = new RegisterSchema(register: $this->registers->find(id: $register), schema: $this->schemas->find(id: $schema));
		} catch (Throwable $e) {
			return new JSONResponse(data: ['error' => 'Register or schema not found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		$dryRun = filter_var($this->request->getParam('dryRun', false), FILTER_VALIDATE_BOOLEAN);
		$count  = count($this->rematerialiser->select(table: $table, selection: $selection));
		if ($count > self::SYNC_LIMIT) {
			$this->jobs->add(
				CalculationRecomputeJob::class,
				[
					'register' => $table->register->getId(),
					'schema' => $table->schema->getId(),
					'ids' => $selection->ids,
					'filters' => $selection->filters,
					'dryRun' => $dryRun,
					'actor' => $user->getUID(),
				]
			);

			return new JSONResponse(
				data: ['run' => ['status' => 'queued', 'job' => CalculationRecomputeJob::class, 'objects' => $count]],
				statusCode: Http::STATUS_ACCEPTED
			);
		}

		$result = $this->rematerialiser->run(
			table: $table,
			selection: $selection,
			dryRun: $dryRun,
			mayUpdate: $this->access->forUser(schema: $table->schema, userId: $user->getUID())
		);

		return new JSONResponse(data: $result->jsonSerialize());
	}//end recompute()

	/**
	 * The selection the body names, or null when it names none or both.
	 *
	 * @return RecomputeSelection|null
	 */
	private function selection(): ?RecomputeSelection {
		$ids     = $this->request->getParam('ids');
		$filters = $this->request->getParam('filters');
		if (($ids === null) === ($filters === null)) {
			return null;
		}

		if ($ids !== null) {
			if (is_array($ids) === false || array_is_list($ids) === false) {
				return null;
			}

			return RecomputeSelection::byIds(ids: $ids);
		}

		if (is_array($filters) === false) {
			return null;
		}

		return RecomputeSelection::byFilters(filters: $filters);
	}//end selection()
}//end class
