<?php

/**
 * SyncWatermark — the server moment a list read started (incremental-sync-for-api-consumers D-4).
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * The server time taken before a list query runs, returned as `syncWatermark`.
 *
 * A consumer's next changed-since read starts from this moment. It is taken
 * before the query, so a write committed while the read ran carries an
 * `updated` at or after it and is picked up by the next run instead of being
 * skipped. The value is truncated to the second (ISO 8601 with offset), which
 * only moves it earlier and so keeps that guarantee.
 *
 * @spec openspec/changes/incremental-sync-for-api-consumers/specs/incremental-sync/spec.md#requirement-list-responses-carry-a-server-side-sync-watermark-req-sync-004
 */
final class SyncWatermark {

	/**
	 * The moment the read started.
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $moment;

	/**
	 * Take the watermark now, or at the moment given.
	 *
	 * @param DateTimeImmutable|null $moment The moment; now when null.
	 *
	 * @spec openspec/changes/incremental-sync-for-api-consumers/specs/incremental-sync/spec.md#requirement-list-responses-carry-a-server-side-sync-watermark-req-sync-004
	 */
	public function __construct(?DateTimeImmutable $moment = null) {
		$this->moment = ($moment ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('UTC'));
	}//end __construct()

	/**
	 * The watermark as ISO 8601 with offset.
	 *
	 * @return string The watermark.
	 *
	 * @spec openspec/changes/incremental-sync-for-api-consumers/specs/incremental-sync/spec.md#requirement-list-responses-carry-a-server-side-sync-watermark-req-sync-004
	 */
	public function value(): string {
		return $this->moment->format(DateTimeInterface::ATOM);
	}//end value()

	/**
	 * Add `syncWatermark` to a successful list envelope.
	 *
	 * Only a 200 whose body is an envelope with `results` gets it: a refusal or
	 * an error is not a read a consumer may resume from.
	 *
	 * @param JSONResponse $response The list response.
	 *
	 * @return JSONResponse The same response, stamped when it is a list envelope.
	 *
	 * @spec openspec/changes/incremental-sync-for-api-consumers/specs/incremental-sync/spec.md#requirement-list-responses-carry-a-server-side-sync-watermark-req-sync-004
	 */
	public function stamp(JSONResponse $response): JSONResponse {
		$data = $response->getData();
		if ($response->getStatus() !== Http::STATUS_OK || is_array($data) === false || array_key_exists('results', $data) === false) {
			return $response;
		}

		$data['syncWatermark'] = $this->value();
		$response->setData($data);

		return $response;
	}//end stamp()
}//end class
