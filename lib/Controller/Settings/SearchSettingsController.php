<?php

/**
 * SearchSettingsController: the text search configuration `_search` stems with.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Settings
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/search-dutch-language-quality/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Settings;

use InvalidArgumentException;
use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Service\Search\TextSearchConfiguration;
use OCA\OpenRegister\Settings\OpenRegisterAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Reads and saves `search.textSearchConfig`.
 *
 * Administrator only: no `NoAdminRequired`, and `AuthorizedAdminSetting`
 * names the OpenRegister admin section it belongs to. `stemming` is the
 * configuration on PostgreSQL and `false` on MySQL and MariaDB; `available`
 * lists what `pg_ts_config` reports, the only values a save accepts.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/search-dutch-language-quality/specs/zoeken-filteren/spec.md
 */
class SearchSettingsController extends Controller {
	/**
	 * Build the controller.
	 *
	 * @param IRequest                $request    The request.
	 * @param TextSearchConfiguration $textSearch The setting and the database it applies to.
	 */
	public function __construct(
		IRequest $request,
		private readonly TextSearchConfiguration $textSearch,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The saved configuration, whether it applies here, and what may be chosen.
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/search-dutch-language-quality/specs/zoeken-filteren/spec.md
	 */
	#[AuthorizedAdminSetting(settings: OpenRegisterAdmin::class)]
	public function show(): JSONResponse {
		return new JSONResponse(data: $this->settings());
	}//end show()

	/**
	 * Save the configuration; a name `pg_ts_config` does not list is refused with 400.
	 *
	 * @param string $textSearchConfig The configuration name.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/search-dutch-language-quality/specs/zoeken-filteren/spec.md
	 */
	#[AuthorizedAdminSetting(settings: OpenRegisterAdmin::class)]
	public function update(string $textSearchConfig=''): JSONResponse {
		try {
			$this->textSearch->save(name: $textSearchConfig);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(data: $this->settings());
	}//end update()

	/**
	 * The settings block.
	 *
	 * @return array{textSearchConfig: string, stemming: string|false, available: list<string>}
	 */
	private function settings(): array {
		return [
			'textSearchConfig' => $this->textSearch->configured(),
			'stemming'         => $this->textSearch->stemming(),
			'available'        => $this->textSearch->available(),
		];
	}//end settings()
}//end class
