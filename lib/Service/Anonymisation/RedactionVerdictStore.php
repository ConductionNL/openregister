<?php

/**
 * OpenRegister RedactionVerdictStore
 *
 * Keeps the irreversibility verdict of an anonymised copy on that copy, and
 * refuses to let a copy that was not found clean become public.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Anonymisation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/redaction-release-safeguards/tasks.md#task-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Anonymisation;

use OCA\OpenRegister\Exception\RedactionNotPublishableException;
use OCP\Files\File;
use OCP\FilesMetadata\IFilesMetadataManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The verdict of a written copy, kept in the copy's own file metadata.
 *
 * The verdict travels with the file rather than with the run, so every path
 * that later publishes the file reads the same answer, whoever wrote it.
 * Only the public form is kept: verdict, output mode, routes and a count per
 * route, never a redacted value.
 */
class RedactionVerdictStore {

	/**
	 * The file metadata key the verdict is kept under.
	 */
	public const METADATA_KEY = 'openregister-redaction-verdict';

	/**
	 * Constructor.
	 *
	 * @param IFilesMetadataManager $metadataManager Where the verdict is kept.
	 * @param LoggerInterface       $logger          For a verdict that could not be kept.
	 */
	public function __construct(
		private readonly IFilesMetadataManager $metadataManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The form of a verification result that may leave the engine.
	 *
	 * @param array<string, mixed> $verification The verifier's result.
	 *
	 * @return array{verdict: string, mayBePublished: bool, outputMode: string, routesChecked: list<string>,
	 *     findings: list<array{route: string, count: int}>}
	 *
	 * @spec openspec/changes/redaction-release-safeguards/tasks.md#task-1.2
	 */
	public static function publicForm(array $verification): array {
		$findings = [];
		foreach (($verification['findings'] ?? []) as $finding) {
			if (is_array($finding) === false) {
				continue;
			}

			$findings[] = ['route' => (string)($finding['route'] ?? ''), 'count' => (int)($finding['count'] ?? 0)];
		}

		$verdict = (string)($verification['verdict'] ?? RedactionIrreversibilityVerifier::UNVERIFIABLE);

		return [
			'verdict' => $verdict,
			'mayBePublished' => ($verdict === RedactionIrreversibilityVerifier::CLEAN && ($verification['mayBePublished'] ?? false) === true),
			'outputMode' => (string)($verification['outputMode'] ?? ''),
			'routesChecked' => array_values(array_map('strval', (array)($verification['routesChecked'] ?? []))),
			'findings' => $findings,
		];
	}//end publicForm()

	/**
	 * Keep the verdict on the written copy.
	 *
	 * Best effort: a copy whose verdict could not be kept has no verdict, and
	 * {@see assertMayBePublished()} then refuses it like any unverified copy.
	 *
	 * @param int                  $fileId       The written copy.
	 * @param array<string, mixed> $verification The verifier's result.
	 *
	 * @return bool True when the verdict was kept.
	 *
	 * @spec openspec/changes/redaction-release-safeguards/tasks.md#task-1.2
	 */
	public function record(int $fileId, array $verification): bool {
		$encoded = json_encode(self::publicForm(verification: $verification));
		if ($fileId <= 0 || is_string($encoded) === false) {
			return false;
		}

		try {
			$metadata = $this->metadataManager->getMetadata($fileId, true);
			$metadata->setString(self::METADATA_KEY, $encoded);
			$this->metadataManager->saveMetadata($metadata);
			return true;
		} catch (Throwable $error) {
			$this->logger->warning(
				message: '[RedactionVerdictStore] Could not keep the verdict of an anonymised copy',
				context: ['fileId' => $fileId, 'error' => $error->getMessage()]
			);
			return false;
		}
	}//end record()

	/**
	 * The verdict kept on a copy, or null when it has none.
	 *
	 * @param int $fileId The copy.
	 *
	 * @return array<string, mixed>|null The public form, or null.
	 *
	 * @throws Throwable When the file's metadata cannot be read.
	 *
	 * @spec openspec/changes/redaction-release-safeguards/tasks.md#task-1.4
	 */
	public function read(int $fileId): ?array {
		$metadata = $this->metadataManager->getMetadata($fileId, true);
		if ($metadata->hasKey(self::METADATA_KEY) === false) {
			return null;
		}

		$decoded = json_decode($metadata->getString(self::METADATA_KEY), true);
		if (is_array($decoded) === false) {
			return self::publicForm(verification: ['verdict' => RedactionIrreversibilityVerifier::UNVERIFIABLE]);
		}

		return self::publicForm(verification: $decoded);
	}//end read()

	/**
	 * Refuse to publish an anonymised copy whose verdict is not clean.
	 *
	 * A file that is not an anonymised copy, and an anonymised copy written
	 * before verdicts were kept, are not this guard's business. A copy that
	 * carries a verdict publishes only when that verdict is `clean`; a
	 * metadata store that cannot be read refuses rather than lets it through.
	 *
	 * @param File $file The file about to be published.
	 *
	 * @return void
	 *
	 * @throws RedactionNotPublishableException When the copy is leaking, unverifiable, or its verdict cannot be read.
	 *
	 * @spec openspec/changes/redaction-release-safeguards/tasks.md#task-1.4
	 */
	public function assertMayBePublished(File $file): void {
		if (str_contains((string)$file->getName(), '_anonymized') === false) {
			return;
		}

		try {
			$verdict = $this->read(fileId: (int)$file->getId());
		} catch (Throwable $error) {
			throw new RedactionNotPublishableException(
				message: 'This anonymised copy cannot be published: its redaction verdict could not be read.',
				verdict: RedactionIrreversibilityVerifier::UNVERIFIABLE,
				routes: []
			);
		}

		if ($verdict === null || $verdict['mayBePublished'] === true) {
			return;
		}

		$routes = array_values(array_unique(array_map(static fn (array $f): string => $f['route'], $verdict['findings'])));
		$message = sprintf('This anonymised copy cannot be published: its redaction verdict is %s', $verdict['verdict']);
		if ($routes !== []) {
			$message .= ' (routes: ' . implode(', ', $routes) . ')';
		}

		throw new RedactionNotPublishableException(
			message: $message . '. Anonymise the file again and check the result.',
			verdict: $verdict['verdict'],
			routes: $routes
		);
	}//end assertMayBePublished()
}//end class
