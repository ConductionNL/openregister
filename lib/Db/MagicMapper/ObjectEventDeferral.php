<?php

/**
 * Whether a write's object event is handed to a background job or dispatched before the response.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Db
 * @package   OCA\OpenRegister\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 *
 * @spec openspec/changes/object-write-sub-500ms/specs/object-write-performance/spec.md#requirement-a-write-response-means-persisted-and-a-caller-can-ask-to-wait-for-its-side-effects
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use OCP\IAppConfig;
use OCP\IRequest;

/**
 * The deferral decision: the instance opts in, and one request can opt back out.
 *
 * With `defer_object_events=1` a 2xx means "persisted"; the listeners run in a
 * background job afterwards. A caller that needs the old guarantee, "persisted
 * and every listener has run", sends `X-OpenRegister-Effects: sync` and that
 * request's events are dispatched before the response, as without deferral.
 *
 * @spec openspec/changes/object-write-sub-500ms/specs/object-write-performance/spec.md#requirement-a-write-response-means-persisted-and-a-caller-can-ask-to-wait-for-its-side-effects
 */
final class ObjectEventDeferral {

	/**
	 * The app setting that opts an instance in.
	 */
	public const SETTING = 'defer_object_events';

	/**
	 * The request header a caller sends to wait for the side effects.
	 */
	public const HEADER = 'X-OpenRegister-Effects';

	/**
	 * The header value that asks to wait.
	 */
	public const SYNC = 'sync';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig    $appConfig The app settings.
	 * @param IRequest|null $request   The current request; null outside one (a job, occ).
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ?IRequest $request,
	) {
	}//end __construct()

	/**
	 * Whether this write's event goes to a background job.
	 *
	 * @return bool True only when the instance opted in and the request did not ask to wait.
	 *
	 * @spec openspec/changes/object-write-sub-500ms/specs/object-write-performance/spec.md#requirement-a-write-response-means-persisted-and-a-caller-can-ask-to-wait-for-its-side-effects
	 */
	public function defers(): bool {
		if ($this->appConfig->getValueString('openregister', self::SETTING, '') !== '1') {
			return false;
		}

		return $this->callerWaits() === false;
	}//end defers()

	/**
	 * Whether the request asked for its side effects before the response.
	 *
	 * @return bool True when the header says `sync`, in any letter case.
	 *
	 * @spec openspec/changes/object-write-sub-500ms/specs/object-write-performance/spec.md#requirement-a-write-response-means-persisted-and-a-caller-can-ask-to-wait-for-its-side-effects
	 */
	public function callerWaits(): bool {
		if ($this->request === null) {
			return false;
		}

		return strtolower(trim($this->request->getHeader(self::HEADER))) === self::SYNC;
	}//end callerWaits()
}//end class
