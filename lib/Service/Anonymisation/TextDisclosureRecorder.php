<?php

/**
 * Records, per document, every external call that carries its text.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Anonymisation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/anonymisation-discloses-itself-pipeline/specs/anonymisation-disclosure/spec.md#requirement-every-external-call-that-carries-a-documents-text-is-recorded-per-document-req-adi-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Anonymisation;

use DateTime;
use OCA\OpenRegister\Exception\TextDisclosureNotRecordedException;
use OCA\OpenRegister\Files\Db\TextDisclosure;
use OCA\OpenRegister\Files\Db\TextDisclosureMapper;
use Throwable;

/**
 * Writes the disclosure record before the call is made; a record that cannot
 * be written stops the call.
 *
 * @spec openspec/changes/anonymisation-discloses-itself-pipeline/specs/anonymisation-disclosure/spec.md#requirement-every-external-call-that-carries-a-documents-text-is-recorded-per-document-req-adi-005
 */
class TextDisclosureRecorder {
	/**
	 * Constructor.
	 *
	 * @param TextDisclosureMapper $mapper The disclosure store.
	 */
	public function __construct(
		private readonly TextDisclosureMapper $mapper,
	) {
	}//end __construct()

	/**
	 * Record that a document's text is about to be sent to a service.
	 *
	 * @param TextDisclosureSource $source  The document whose text is sent.
	 * @param string               $service The service, such as Presidio.
	 * @param string               $url     The URL the text is sent to; only its host is kept.
	 * @param int                  $bytes   The number of bytes sent.
	 *
	 * @return TextDisclosure The stored record.
	 *
	 * @throws TextDisclosureNotRecordedException When the record cannot be written; the caller must not send.
	 *
	 * @spec openspec/changes/anonymisation-discloses-itself-pipeline/specs/anonymisation-disclosure/spec.md#requirement-every-external-call-that-carries-a-documents-text-is-recorded-per-document-req-adi-005
	 */
	public function record(TextDisclosureSource $source, string $service, string $url, int $bytes): TextDisclosure {
		$disclosure = new TextDisclosure();
		$disclosure->setFileId($source->fileId());
		$disclosure->setSourceType($source->sourceType);
		$disclosure->setSourceId($source->sourceId);
		$disclosure->setService($service);
		$disclosure->setHost($this->hostOf(url: $url));
		$disclosure->setAt(new DateTime());
		$disclosure->setBytes($bytes);

		try {
			return $this->mapper->insert(entity: $disclosure);
		} catch (Throwable $e) {
			throw new TextDisclosureNotRecordedException(service: $service, previous: $e);
		}
	}//end record()

	/**
	 * The host of a URL, with its port when one is given; the whole URL when it has no host.
	 *
	 * @param string $url The URL.
	 *
	 * @return string
	 */
	private function hostOf(string $url): string {
		$host = parse_url($url, PHP_URL_HOST);
		if (is_string($host) === false || $host === '') {
			return $url;
		}

		$port = parse_url($url, PHP_URL_PORT);
		if (is_int($port) === true) {
			return $host . ':' . $port;
		}

		return $host;
	}//end hostOf()
}//end class
