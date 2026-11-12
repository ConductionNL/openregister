<?php

/**
 * OpenRegister VersionClaim
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;


use DateTimeImmutable;
use DateTimeInterface;
use OCP\IRequest;

/**
 * The version of an object a request says it read.
 *
 * @spec openspec/changes/a-conflicting-save-shows-the-other-value/specs/objects-crud/spec.md#requirement-every-write-path-asserts-the-expected-version-req-cso-003
 */
final class VersionClaim {
	/**
	 * Hold the request.
	 *
	 * @param IRequest $request The request carrying the claim.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-conflicting-save-shows-the-other-value/specs/objects-crud/spec.md#requirement-every-write-path-asserts-the-expected-version-req-cso-003
	 */
	public function __construct(
		private readonly IRequest $request,
	) {
	}//end __construct()

	/**
	 * The version this request claims, or null when it claims none.
	 *
	 * Read from the RAW request: the payload filters strip `_`-prefixed keys, so
	 * by the time a handler sees the body this is gone.
	 *
	 * @return string|null The claimed version.
	 *
	 * @spec openspec/changes/a-conflicting-save-shows-the-other-value/specs/objects-crud/spec.md#requirement-every-write-path-asserts-the-expected-version-req-cso-003
	 */
	public function version(): ?string {
		$expected = (string)$this->request->getParam('_expectedUpdated');
		if (trim($expected) !== '') {
			return $expected;
		}

		return $this->asExpectedVersion(value: $this->request->getHeader('If-Match'));
	}//end version()

	/**
	 * An `If-Match` value, when it is an instant rather than any old header.
	 *
	 * The object's concurrency token IS its `updated` timestamp, so an `If-Match`
	 * that does not parse as one cannot be a version of it and is ignored rather
	 * than refused: a caller sending a content etag is not making a concurrency
	 * assertion. A bare word like `application/json` can still parse on some
	 * builds, so the value has to carry a date as well as parse.
	 *
	 * @param string|null $value The header value.
	 *
	 * @return string|null The instant, or null.
	 */
	private function asExpectedVersion(?string $value): ?string {
		$value = trim(trim((string)$value), '"');
		if (preg_match('/\\d{4}-\\d{2}-\\d{2}/', $value) !== 1) {
			return null;
		}

		try {
			return (new DateTimeImmutable($value))->format(DateTimeInterface::ATOM);
		} catch (\Throwable $e) {
			return null;
		}
	}//end asExpectedVersion()
}//end class
