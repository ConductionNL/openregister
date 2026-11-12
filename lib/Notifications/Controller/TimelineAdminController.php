<?php

/**
 * TimelineAdminController
 *
 * The three administered declarations the timeline leans on: entry kinds,
 * reference patterns and canned text blocks. They share a controller because
 * they share an audience and a posture — an administrator declaring what this
 * instance's timeline can do — and splitting them into three would triple the
 * route surface for one panel.
 *
 * THE READ SIDE IS NOT ADMIN-ONLY, and that is deliberate. A handler writing
 * an entry has to know which kinds exist and which canned texts they may
 * insert, or the form has nothing to offer. Declaring, rewriting and
 * withdrawing are administrative; listing is not.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Controller
 * @package   OCA\OpenRegister\Controller
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 *
 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Controller;

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Notifications\Controller\Support\TimelineAdminControllerServices;
use OCA\OpenRegister\Notifications\Service\Timeline\ReferenceService;
use OCA\OpenRegister\Notifications\Service\Timeline\TextBlockService;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelineKindService;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelineValidationException;
use OCA\OpenRegister\Settings\OpenRegisterAdmin;
use OCA\OpenRegister\Notifications\Controller\Support\QueryText;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Entry kinds, reference patterns and canned text blocks.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
 */
class TimelineAdminController extends Controller {

	/**
	 * The user session.
	 *
	 * @var IUserSession
	 */
	private readonly IUserSession $userSession;

	/**
	 * The group manager.
	 *
	 * @var IGroupManager
	 */
	private readonly IGroupManager $groupManager;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * The entry kinds.
	 *
	 * @var TimelineKindService
	 */
	private readonly TimelineKindService $kinds;

	/**
	 * The reference patterns.
	 *
	 * @var ReferenceService
	 */
	private readonly ReferenceService $references;

	/**
	 * The canned text blocks.
	 *
	 * @var TextBlockService
	 */
	private readonly TextBlockService $blocks;

	/**
	 * Constructor.
	 *
	 * @param IRequest                        $request  Request.
	 * @param TimelineAdminControllerServices $services The kinds, references, text blocks and session.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		TimelineAdminControllerServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

		$this->kinds = $services->kinds;
		$this->references = $services->references;
		$this->blocks = $services->blocks;
		$this->userSession = $services->session->userSession;
		$this->groupManager = $services->session->groupManager;
		$this->logger = $services->session->logger;
	}//end __construct()

	/**
	 * The entry kinds, optionally the ones in scope for a register and schema.
	 *
	 * @return JSONResponse The declarations.
	 *
	 * @NoAdminRequired
	 *
	 * @no-admin-idor-exempt Takes no caller-supplied object id and reads no object.
	 * It lists instance-wide CONFIGURATION: the names and declared properties of
	 * entry kinds. There is nothing here to scope to a caller, and a handler
	 * whose form cannot offer the kinds cannot write a contactmoment at all.
	 * Declaring, rewriting and withdrawing a kind are admin-only and guarded by
	 * requireAdmin() below.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	#[NoAdminRequired]
	public function kinds(): JSONResponse {
		$params = $this->request->getParams();

		return new JSONResponse(
			[
				'results' => array_map(
					static fn ($kind) => $kind->jsonSerialize(),
					$this->kinds->listKinds(
						register: (new QueryText(params: $params))->value(key: 'register'),
						schema: (new QueryText(params: $params))->value(key: 'schema')
					)
				),
			]
		);
	}//end kinds()

	/**
	 * Declare an entry kind, or rewrite the declaration carrying the name.
	 *
	 * 🔴 CSRF STAYS ON. The panel posts through axios with Nextcloud's request
	 * token, so nothing here needs a no-CSRF tag; the admin-setting attribute
	 * declares the posture the body enforces.
	 *
	 * @return JSONResponse The stored declaration.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	#[AuthorizedAdminSetting(settings: OpenRegisterAdmin::class)]
	public function declareKind(): JSONResponse {
		$refusal = $this->requireAdmin();
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			$kind = $this->kinds->declareKind(data: $this->request->getParams());
		} catch (TimelineValidationException $e) {
			return new JSONResponse(['message' => $e->getMessage(), 'errors' => $e->getErrors()], Http::STATUS_BAD_REQUEST);
		} catch (Throwable $e) {
			return $this->unexpected(exception: $e, context: 'declareKind');
		}

		return new JSONResponse($kind->jsonSerialize());
	}//end declareKind()

	/**
	 * Withdraw an entry kind. Entries already written as it keep their kind.
	 *
	 * @param string $slug The kind name.
	 *
	 * @return JSONResponse Whether anything was withdrawn.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	#[AuthorizedAdminSetting(settings: OpenRegisterAdmin::class)]
	public function withdrawKind(string $slug): JSONResponse {
		$refusal = $this->requireAdmin();
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			$removed = $this->kinds->withdraw(slug: $slug);
		} catch (Throwable $e) {
			return $this->unexpected(exception: $e, context: 'withdrawKind');
		}

		if ($removed === false) {
			return new JSONResponse(['message' => 'No such entry kind'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(['withdrawn' => true]);
	}//end withdrawKind()

	/**
	 * The administered reference patterns.
	 *
	 * @return JSONResponse The declarations.
	 *
	 * @NoAdminRequired
	 *
	 * @no-admin-idor-exempt Takes no caller-supplied object id and reads no object.
	 * It lists instance-wide CONFIGURATION: the declared short-code patterns and
	 * where they resolve. The pattern is what renders a code as a link on a page
	 * the caller is already reading, so a reader who cannot see the list cannot
	 * render one. Declaring and withdrawing a pattern are admin-only and guarded
	 * by requireAdmin() below.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	#[NoAdminRequired]
	public function patterns(): JSONResponse {
		return new JSONResponse(
			[
				'results' => array_map(
					static fn ($pattern) => $pattern->jsonSerialize(),
					$this->references->listPatterns(enabledOnly: false)
				),
			]
		);
	}//end patterns()

	/**
	 * Declare a reference pattern, or rewrite the one carrying the name.
	 *
	 * @return JSONResponse The stored declaration.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	#[AuthorizedAdminSetting(settings: OpenRegisterAdmin::class)]
	public function declarePattern(): JSONResponse {
		$refusal = $this->requireAdmin();
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			$pattern = $this->references->declarePattern(data: $this->request->getParams());
		} catch (TimelineValidationException $e) {
			return new JSONResponse(['message' => $e->getMessage(), 'errors' => $e->getErrors()], Http::STATUS_BAD_REQUEST);
		} catch (Throwable $e) {
			return $this->unexpected(exception: $e, context: 'declarePattern');
		}

		return new JSONResponse($pattern->jsonSerialize());
	}//end declarePattern()

	/**
	 * Withdraw a reference pattern. References already recorded stay.
	 *
	 * @param string $slug The pattern name.
	 *
	 * @return JSONResponse Whether anything was withdrawn.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	#[AuthorizedAdminSetting(settings: OpenRegisterAdmin::class)]
	public function withdrawPattern(string $slug): JSONResponse {
		$refusal = $this->requireAdmin();
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			$removed = $this->references->withdrawPattern(slug: $slug);
		} catch (Throwable $e) {
			return $this->unexpected(exception: $e, context: 'withdrawPattern');
		}

		if ($removed === false) {
			return new JSONResponse(['message' => 'No such reference pattern'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(['withdrawn' => true]);
	}//end withdrawPattern()

	/**
	 * The canned text blocks the caller may insert here.
	 *
	 * Scoped to the caller's own groups, so a list is what this handler may
	 * actually use rather than everything the instance holds.
	 *
	 * @return JSONResponse The blocks.
	 *
	 * @NoAdminRequired
	 *
	 * @no-admin-idor-exempt Takes no caller-supplied object id and reads no object.
	 * It IS scoped to the caller, one hop out rather than in this body:
	 * TextBlockService::listBlocks() resolves the caller's own groups through
	 * IGroupManager and TextBlockMapper::findInScope() narrows the query to the
	 * unscoped blocks plus the ones administered for those groups, so a block
	 * scoped to a group the caller is not in is never returned.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	#[NoAdminRequired]
	public function textBlocks(): JSONResponse {
		$params = $this->request->getParams();

		return new JSONResponse(
			[
				'results' => array_map(
					static fn ($block) => $block->jsonSerialize(),
					$this->blocks->listBlocks(
						register: (new QueryText(params: $params))->value(key: 'register'),
						schema: (new QueryText(params: $params))->value(key: 'schema')
					)
				),
			]
		);
	}//end textBlocks()

	/**
	 * Administer a canned text block, or rewrite the one carrying the name.
	 *
	 * @return JSONResponse The stored block.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	#[AuthorizedAdminSetting(settings: OpenRegisterAdmin::class)]
	public function declareTextBlock(): JSONResponse {
		$refusal = $this->requireAdmin();
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			$block = $this->blocks->declareBlock(data: $this->request->getParams());
		} catch (TimelineValidationException $e) {
			return new JSONResponse(['message' => $e->getMessage(), 'errors' => $e->getErrors()], Http::STATUS_BAD_REQUEST);
		} catch (Throwable $e) {
			return $this->unexpected(exception: $e, context: 'declareTextBlock');
		}

		return new JSONResponse($block->jsonSerialize());
	}//end declareTextBlock()

	/**
	 * Withdraw a canned text block.
	 *
	 * @param string $slug The block name.
	 *
	 * @return JSONResponse Whether anything was withdrawn.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	#[AuthorizedAdminSetting(settings: OpenRegisterAdmin::class)]
	public function withdrawTextBlock(string $slug): JSONResponse {
		$refusal = $this->requireAdmin();
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			$removed = $this->blocks->withdrawBlock(slug: $slug);
		} catch (Throwable $e) {
			return $this->unexpected(exception: $e, context: 'withdrawTextBlock');
		}

		if ($removed === false) {
			return new JSONResponse(['message' => 'No such text block'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(['withdrawn' => true]);
	}//end withdrawTextBlock()

	/**
	 * Refuse a caller who is not an administrator.
	 *
	 * The attribute declares the posture; this enforces it, because the
	 * attribute alone is a declaration and not a check.
	 *
	 * @return JSONResponse|null The refusal, or null when the caller may proceed.
	 */
	private function requireAdmin(): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->groupManager->isAdmin($user->getUID()) === false) {
			return new JSONResponse(
				['message' => 'Only an administrator declares what the timeline can carry'],
				Http::STATUS_FORBIDDEN
			);
		}

		return null;
	}//end requireAdmin()


	/**
	 * The one answer for something nobody expected.
	 *
	 * @param Throwable $exception The failure.
	 * @param string    $context   Which method it happened in.
	 *
	 * @return JSONResponse A 500 that says nothing about the instance.
	 */
	private function unexpected(Throwable $exception, string $context): JSONResponse {
		$this->logger->error(
			'[TimelineAdminController] '.$context.' failed',
			['exception' => $exception]
		);

		return new JSONResponse(
			['message' => 'The declaration could not be written'],
			Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}//end unexpected()
}//end class
