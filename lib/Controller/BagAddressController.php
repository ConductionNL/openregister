<?php

/**
 * OpenRegister BAG address controller
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Controller
 * @package   OCA\OpenRegister\Controller
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Service\Bag\BagAddressLookup;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The public address lookup by postcode and house number, for forms that
 * fill in street and town for a resident.
 *
 * Read-only and public: the BAG is open data. Throttled at 60 lookups a
 * minute per client address.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @spec openspec/changes/bag-address-lookup-by-postcode/specs/bag-address-lookup/spec.md#requirement-an-address-is-found-by-postcode-and-house-number-req-bag-002
 */
class BagAddressController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest         $request The request.
	 * @param BagAddressLookup $lookup  The address lookup.
	 * @param LoggerInterface  $logger  Logs a failed read.
	 *
	 * @spec openspec/changes/bag-address-lookup-by-postcode/specs/bag-address-lookup/spec.md#requirement-an-address-is-found-by-postcode-and-house-number-req-bag-002
	 */
	public function __construct(
		IRequest $request,
		private readonly BagAddressLookup $lookup,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The addresses at a postcode and house number.
	 *
	 * Query parameters: postcode, huisnummer, and optionally huisletter and
	 * toevoeging. Answers 200 with the addresses, 404 when there is none, 400
	 * for a postcode or number that is not one, 503 when the register cannot
	 * be read.
	 *
	 * @return JSONResponse The addresses.
	 *
	 * @spec openspec/changes/bag-address-lookup-by-postcode/specs/bag-address-lookup/spec.md#requirement-an-address-is-found-by-postcode-and-house-number-req-bag-002
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function index(): JSONResponse {
		try {
			$answer = $this->lookup->find(
				postcode: (string) $this->request->getParam('postcode', ''),
				number: (string) $this->request->getParam('huisnummer', ''),
				letter: self::optional(value: $this->request->getParam('huisletter')),
				addition: self::optional(value: $this->request->getParam('toevoeging'))
			);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage(), 'error' => 'invalid_address'], statusCode: Http::STATUS_BAD_REQUEST);
		} catch (Throwable $e) {
			$this->logger->error('[BagAddressController] The BAG register could not be read', ['exception' => $e]);
			return new JSONResponse(
				data: ['message' => 'The address lookup is not available.', 'error' => 'lookup_unavailable'],
				statusCode: Http::STATUS_SERVICE_UNAVAILABLE
			);
		}

		if ($answer['total'] === 0) {
			return new JSONResponse(data: $answer, statusCode: Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(data: $answer);
	}//end index()

	/**
	 * An optional query parameter as a string, or null when absent.
	 *
	 * @param mixed $value The parameter.
	 *
	 * @return string|null The value.
	 */
	private static function optional(mixed $value): ?string {
		if (is_string($value) === false) {
			return null;
		}

		return $value;
	}//end optional()
}//end class
