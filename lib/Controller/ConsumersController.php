<?php

/**
 * ConsumersController handles REST API endpoints for consumer management.
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
 * @psalm-suppress UnusedClass
 */

namespace OCA\OpenRegister\Controller;

use DateTime;
use DateTimeImmutable;
use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Controller\Support\ConsumersControllerServices;
use OCA\OpenRegister\Db\Consumer;
use OCA\OpenRegister\Db\ConsumerMapperInterface;
use OCA\OpenRegister\Service\Rbac\PermissionCatalogueInterface;
use OCA\OpenRegister\Service\Rbac\TokenGrant;
use OCA\OpenRegister\Service\Rbac\TokenGrantIssue;
use OCA\OpenRegister\Service\Rbac\TokenGrantValidator;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Provides REST API endpoints for managing API consumers.
 *
 * @package OCA\OpenRegister\Controller
 *
 * @spec openspec/specs/openapi-generation/spec.md
 */
class ConsumersController extends Controller {
	/**
	 * The consumer database mapper
	 *
	 * @var ConsumerMapperInterface
	 */
	private readonly ConsumerMapperInterface $consumerMapper;

	/**
	 * The localization service
	 *
	 * @var IL10N
	 */
	private readonly IL10N $l10n;

	/**
	 * Refuses a token grant that cannot be issued.
	 *
	 * @var TokenGrantValidator
	 */
	private readonly TokenGrantValidator $grantValidator;

	/**
	 * The verbs this instance knows.
	 *
	 * @var PermissionCatalogueInterface
	 */
	private readonly PermissionCatalogueInterface $catalogue;

	/**
	 * Constructor.
	 *
	 * @param IRequest                    $request  The request object
	 * @param ConsumersControllerServices $services The controller's collaborators.
	 */
	public function __construct(
		IRequest $request,
		ConsumersControllerServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->consumerMapper = $services->consumerMapper;
		$this->l10n = $services->l10n;
		$this->grantValidator = $services->grantValidator;
		$this->catalogue = $services->catalogue;
	}//end __construct()

	/**
	 * List all consumers.
	 *
	 * @return JSONResponse The list of consumers
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/openapi-generation/spec.md
	 */
	public function index(): JSONResponse {
		$consumers = $this->consumerMapper->findAll();

		return new JSONResponse(
			[
				'results' => $consumers,
				'total' => $this->consumerMapper->getTotalCallCount(),
			]
		);

	}//end index()

	/**
	 * Get a single consumer.
	 *
	 * @param int $id The consumer ID
	 *
	 * @return JSONResponse The consumer data or error
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/openapi-generation/spec.md
	 */
	public function show(int $id): JSONResponse {
		try {
			$consumer = $this->consumerMapper->find($id);
			return new JSONResponse($consumer);
		} catch (DoesNotExistException $exception) {
			return new JSONResponse(['error' => $this->l10n->t('Consumer not found')], 404);
		}

	}//end show()

	/**
	 * Create a new consumer.
	 *
	 * @return JSONResponse The created consumer
	 *
	 * @spec openspec/specs/openapi-generation/spec.md
	 */
	public function create(): JSONResponse {
		$data = $this->request->getParams();

		// Remove framework-injected params.
		unset($data['_route']);

		$refusal = $this->grantRefusal(data: $data);
		if ($refusal !== null) {
			return new JSONResponse(['error' => $refusal], 400);
		}

		$data['created'] = new DateTime();
		$data['updated'] = new DateTime();

		$consumer = $this->consumerMapper->createFromArray($data);

		return new JSONResponse($consumer, 201);
	}//end create()

	/**
	 * Update a consumer.
	 *
	 * @param int $id The consumer ID
	 *
	 * @return JSONResponse The updated consumer or error
	 *
	 * @spec openspec/specs/openapi-generation/spec.md
	 */
	public function update(int $id): JSONResponse {
		try {
			$data = $this->request->getParams();

			unset($data['_route'], $data['id']);

			$refusal = $this->grantRefusal(data: $data);
			if ($refusal !== null) {
				return new JSONResponse(['error' => $refusal], 400);
			}

			$data['updated'] = new DateTime();

			$consumer = $this->consumerMapper->updateFromArray($id, $data);

			return new JSONResponse($consumer);
		} catch (DoesNotExistException $exception) {
			return new JSONResponse(['error' => $this->l10n->t('Consumer not found')], 404);
		}

	}//end update()

	/**
	 * Why the token grant in a consumer's configuration may not be saved, or null when it may.
	 *
	 * Consumers are managed by admins, who hold every verb, so the issuer's
	 * verbs are the catalogue's.
	 *
	 * @param array<string, mixed> $data The submitted consumer.
	 *
	 * @return string|null The reason, or null when there is no grant or it may be saved.
	 */
	private function grantRefusal(array $data): ?string {
		$configuration = ($data['configuration'] ?? null);
		if (is_array($configuration) === false || array_key_exists(TokenGrant::KEY, $configuration) === false) {
			return null;
		}

		$grant = $configuration[TokenGrant::KEY];
		if (is_array($grant) === false) {
			return 'a grant must be an object';
		}

		return $this->grantValidator->refusalFor(
			issue: new TokenGrantIssue(grant: $grant, issuerVerbs: $this->catalogue->verbs(), now: new DateTimeImmutable())
		);
	}//end grantRefusal()

	/**
	 * Delete a consumer.
	 *
	 * @param int $id The consumer ID
	 *
	 * @return JSONResponse Empty response or error
	 *
	 * @spec openspec/specs/openapi-generation/spec.md
	 */
	public function destroy(int $id): JSONResponse {
		try {
			$consumer = $this->consumerMapper->find($id);
			$this->consumerMapper->delete($consumer);

			return new JSONResponse([]);
		} catch (DoesNotExistException $exception) {
			return new JSONResponse(['error' => $this->l10n->t('Consumer not found')], 404);
		}

	}//end destroy()

	/**
	 * Partially update a consumer.
	 *
	 * @param int $id The consumer ID
	 *
	 * @return JSONResponse The updated consumer or error
	 *
	 * @spec openspec/specs/openapi-generation/spec.md
	 */
	public function patch(int $id): JSONResponse {
		return $this->update(id: $id);
	}//end patch()
}//end class
