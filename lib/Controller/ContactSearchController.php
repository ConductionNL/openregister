<?php

/**
 * The contacts leaf's name search endpoint.
 *
 * `GET /api/integrations/contacts/search?q=` answers the contacts in the
 * current user's readable address books whose name, e-mail address or
 * organisation contains the query.
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
 * @spec openspec/changes/contacts-leaf-cases-panel/specs/integration-contacts/spec.md#requirement-the-contacts-leaf-offers-a-name-search
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\Integration\ContactSearch;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Name search for the contacts leaf.
 *
 * @spec openspec/changes/contacts-leaf-cases-panel/specs/integration-contacts/spec.md#requirement-the-contacts-leaf-offers-a-name-search
 */
class ContactSearchController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string        $appName The app name.
	 * @param IRequest      $request The request.
	 * @param ContactSearch $search      The search.
	 * @param IUserSession  $userSession The signed-in user.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ContactSearch $search,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Contacts matching `q`.
	 *
	 * Every signed-in user may search: the contacts manager only reads the
	 * address books that user can already read.
	 *
	 * @return JSONResponse The rows and their count, or 400 for a query that is too short.
	 *
	 * @spec openspec/changes/contacts-leaf-cases-panel/specs/integration-contacts/spec.md#requirement-the-contacts-leaf-offers-a-name-search
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function search(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Authentication required', 'results' => [], 'total' => 0], Http::STATUS_UNAUTHORIZED);
		}

		$query = trim((string)$this->request->getParam('q', ''));
		if (mb_strlen($query) < ContactSearch::MIN_QUERY_LENGTH) {
			return new JSONResponse(
				[
					'error' => sprintf('Type at least %d characters to search.', ContactSearch::MIN_QUERY_LENGTH),
					'results' => [],
					'total' => 0,
				],
				Http::STATUS_BAD_REQUEST
			);
		}

		$rows = $this->search->search(query: $query);

		return new JSONResponse(['results' => $rows, 'total' => count($rows)]);
	}//end search()
}//end class
