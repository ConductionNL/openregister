<?php

/**
 * Who holds access to one object, and where each grant came from.
 *
 * WHO MAY ASK, CHECKED BEFORE ANYTHING IS READ. The object is resolved through
 * `ObjectService` first, so a caller who cannot read it gets 404 and learns
 * nothing. Then {@see AccessReviewGate::mayReadGrants()} decides whether the
 * caller may see the people on it, and a refusal answers 403 without a single
 * share, ancestor or rule having been read.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/an-object-lists-its-grants-with-their-origin/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Controller\Support\ObjectSharingEndpoint;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Rbac\AccessReviewGate;
use OCA\OpenRegister\Service\Rbac\ObjectGrantsReport;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * The grants list of one object.
 *
 * @spec openspec/changes/an-object-lists-its-grants-with-their-origin/specs/rbac-scopes/spec.md
 */
class ObjectGrantsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string             $appName       Application identifier.
	 * @param IRequest           $request       Active HTTP request.
	 * @param ObjectOperationsInterface      $objectService Names the register and schema the request resolved.
	 * @param AccessReviewGate   $gate          Resolves the object and decides who may ask.
	 * @param ObjectGrantsReport $report        Assembles the rows.
	 * @param LoggerInterface    $logger        Records why the rows could not be assembled.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ObjectOperationsInterface $objectService,
		private readonly AccessReviewGate $gate,
		private readonly ObjectGrantsReport $report,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * One row per principal and origin: who holds access, which verbs, and why.
	 *
	 * @param string      $register       Register slug or id.
	 * @param string      $schema         Schema slug or id.
	 * @param string      $id             Object uuid.
	 * @param string|null $principalTypes Comma list of principal types to keep; all when empty.
	 *
	 * @return JSONResponse The rows, 404, 403 or 500.
	 *
	 * @SuppressWarnings(PHPMD.ShortVariable) `id` is the route parameter's name.
	 *
	 * @spec openspec/changes/an-object-lists-its-grants-with-their-origin/specs/rbac-scopes/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(string $register, string $schema, string $id, ?string $principalTypes = null): JSONResponse {
		$object = $this->gate->resolve(register: $register, schema: $schema, id: $id);
		if ($object === null) {
			return ObjectSharingEndpoint::notFound();
		}

		if ($this->gate->mayReadGrants(object: $object) === false) {
			return new JSONResponse(
				['message' => 'Reading who has access to this object needs the right to read its grants'],
				Http::STATUS_FORBIDDEN
			);
		}

		$types = array_values(array_filter(array_map('trim', explode(',', (string)$principalTypes))));

		return $this->rows(object: $object, id: $id, types: $types);
	}//end index()

	/**
	 * The rows of the object, or a 500 when a source could not be read.
	 *
	 * @param ObjectEntity  $object The object.
	 * @param string        $id     Object uuid.
	 * @param array<string> $types  The principal types to keep; all when empty.
	 *
	 * @return JSONResponse The rows, or 500.
	 */
	private function rows(ObjectEntity $object, string $id, array $types): JSONResponse {
		try {
			return new JSONResponse(
				$this->report->rowsFor(
					object: $object,
					registerRef: $this->objectService->getRegister(),
					schemaRef: $this->objectService->getSchema(),
					principalTypes: $types
				)
			);
		} catch (\Throwable $e) {
			// An empty list means "nobody but the owner", so a source that
			// could not be read must never turn into one.
			return $this->unlisted(exception: $e, id: $id);
		}//end try
	}//end rows()

	/**
	 * Log why the rows could not be listed, and answer a generic 500.
	 *
	 * @param \Throwable $exception The failure.
	 * @param string     $id        Object uuid.
	 *
	 * @return JSONResponse The 500.
	 */
	private function unlisted(\Throwable $exception, string $id): JSONResponse {
		$this->logger->error(
			message: '[ObjectGrantsController] The grants of {uuid} could not be listed: {error}',
			context: ['uuid' => $id, 'error' => $exception->getMessage(), 'exception' => $exception]
		);
		return new JSONResponse(
			['message' => 'The grants of this object could not be listed'],
			Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}//end unlisted()
}//end class
