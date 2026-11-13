<?php

/**
 * The public page of a form that visitors without an account may fill in.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/capability-rows-verified-a/specs/form-and-journey-registry/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Service\Form\PublicFormDefinition;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Serves `/forms/{formId}`: the form's fields for the page script to render,
 * or nothing and a 404 when the form is not open to visitors without an account.
 *
 * The page submits through the public `forms#submit` route, which applies the
 * same audience check before anything is written.
 *
 * @spec openspec/changes/capability-rows-verified-a/specs/form-and-journey-registry/spec.md
 */
class PublicFormPageController extends Controller {

	/**
	 * The page template.
	 *
	 * @var string
	 */
	public const TEMPLATE = 'publicForm';

	/**
	 * The initial-state key the page script reads the form from.
	 *
	 * @var string
	 */
	public const STATE_KEY = 'publicForm';

	/**
	 * Constructor.
	 *
	 * @param IRequest             $request      The request.
	 * @param PublicFormDefinition $forms        Reads the form as a visitor may see it.
	 * @param IInitialState        $initialState Hands the form to the page script.
	 * @param IL10N                $l10n         Translations for the page title.
	 */
	public function __construct(
		IRequest $request,
		private readonly PublicFormDefinition $forms,
		private readonly IInitialState $initialState,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The public page of a form.
	 *
	 * @param string $formId The form id.
	 *
	 * @return PublicTemplateResponse The page; status 404 when the form is not open to visitors.
	 *
	 * @spec openspec/changes/capability-rows-verified-a/specs/form-and-journey-registry/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function show(string $formId): PublicTemplateResponse {
		$form = $this->forms->forVisitor(formId: $formId);
		$this->initialState->provideInitialState(self::STATE_KEY, $form);

		$response = new PublicTemplateResponse($this->appName, self::TEMPLATE, []);
		$response->setHeaderTitle($form['title'] ?? $this->l10n->t('Form'));
		$response->setFooterVisible(false);
		if ($form === null) {
			$response->setStatus(Http::STATUS_NOT_FOUND);
		}

		return $response;
	}//end show()
}//end class
