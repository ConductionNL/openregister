<?php

/**
 * The purpose the current request declared, and the one that was accepted.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Audit;

use OCA\OpenRegister\Db\ProcessingPurpose;
use OCA\OpenRegister\Db\Verwerkingsactiviteit;
use OCP\IRequest;

/**
 * Carries the declared purpose for the length of one request.
 *
 * Two values, deliberately separate. `declared()` is what the caller CLAIMED —
 * an unvalidated string off the wire, which is never written anywhere. The
 * accepted purpose is the entity the guard resolved and approved, and only that
 * one is stamped onto an audit row. Collapsing the two would let a caller write
 * any string it liked into the trail by naming it.
 *
 * The caller declares a purpose in one of two ways, and they mean the same
 * thing: the `X-Processing-Purpose` header, which a koppeling sets once for
 * every call it makes, or the `_purpose` parameter, underscore-prefixed exactly
 * like the other control parameters so a schema is still free to declare a
 * property called `purpose`. The header wins when both are present, because it
 * is the one a proxy or an adapter sets deliberately.
 *
 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
 */
class PurposeContext {
	/**
	 * The header a koppeling sets to name its purpose.
	 *
	 * @var string
	 */
	public const HEADER = 'X-Processing-Purpose';

	/**
	 * The request parameter that names the purpose.
	 *
	 * @var string
	 */
	public const PARAM = '_purpose';

	/**
	 * A purpose declared in code rather than on the wire, for a background job
	 * or a flow that has no request to carry a header.
	 *
	 * @var string|null
	 */
	private ?string $declared = null;

	/**
	 * The purpose the guard resolved and accepted.
	 *
	 * @var ProcessingPurpose|null
	 */
	private ?ProcessingPurpose $accepted = null;

	/**
	 * The processing activity the guard resolved the purpose to, live.
	 *
	 * Held beside the purpose rather than read off the purpose's stored
	 * `activityUuid`, which is a cache that can name an activity that has since
	 * been archived. An audit row is attributed to the activity that existed
	 * when the read happened.
	 *
	 * @var Verwerkingsactiviteit|null
	 */
	private ?Verwerkingsactiviteit $activity = null;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The current request.
	 */
	public function __construct(
		private readonly IRequest $request,
	) {
	}//end __construct()

	/**
	 * The purpose code the caller claimed, unvalidated.
	 *
	 * @return string|null The declared code, or null when none was named.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
	 */
	public function declared(): ?string {
		if ($this->declared !== null && $this->declared !== '') {
			return $this->declared;
		}

		$header = trim((string)$this->request->getHeader(self::HEADER));
		if ($header !== '') {
			return $header;
		}

		$param = $this->request->getParam(key: self::PARAM);
		if (is_string($param) === true && trim($param) !== '') {
			return trim($param);
		}

		return null;
	}//end declared()

	/**
	 * Declare a purpose from code, for a caller with no request headers.
	 *
	 * @param string|null $code The purpose code, or null to clear.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
	 */
	public function setDeclared(?string $code): void {
		$this->declared = $code;
		if ($code === null || $code === '') {
			$this->accepted = null;
			$this->activity = null;
		}
	}//end setDeclared()

	/**
	 * Record the purpose the guard accepted.
	 *
	 * @param ProcessingPurpose|null      $purpose  The accepted purpose, or null to clear.
	 * @param Verwerkingsactiviteit|null  $activity The activity it resolved to, live.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
	 */
	public function accept(?ProcessingPurpose $purpose, ?Verwerkingsactiviteit $activity = null): void {
		$this->accepted = $purpose;
		$this->activity = $activity;
	}//end accept()

	/**
	 * The processing activity the accepted purpose resolved to.
	 *
	 * @return Verwerkingsactiviteit|null The activity, or null when nothing was accepted.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
	 */
	public function acceptedActivity(): ?Verwerkingsactiviteit {
		return $this->activity;
	}//end acceptedActivity()

	/**
	 * The purpose an audit row written now should be attributed to.
	 *
	 * @return ProcessingPurpose|null The accepted purpose, or null when none was.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
	 */
	public function accepted(): ?ProcessingPurpose {
		return $this->accepted;
	}//end accepted()

	/**
	 * Forget both values.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
	 */
	public function clear(): void {
		$this->declared = null;
		$this->accepted = null;
		$this->activity = null;
	}//end clear()
}//end class
