<?php

/**
 * The statement a user accepts before the application is used, per version.
 *
 * WHY THE VERSION IS THE WHOLE POINT (design D-1). Recording that somebody
 * accepted "the privacy statement" is worth nothing the day the statement
 * changes: the record then says a person agreed to a text nobody can produce
 * any more. So the acceptance carries the version it was given for, and
 * publishing a new version puts every user back in front of it.
 *
 * 🔑 AN UNREADABLE STATEMENT REFUSES (ADR-005). A stored statement that cannot
 * be decoded reads as no statement at all, which means nothing is asked and
 * nothing is recorded. That is the closed direction here: the alternative is a
 * blank page shown as if it were the text somebody agreed to.
 *
 * WHERE THE ACCEPTANCE LIVES. In the user's own configuration, keyed per app,
 * because it is a fact about one account and it must survive without a table of
 * its own. The audit trail carries the same fact for the security officer, so
 * the evidence does not depend on a preference a user could clear.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Hardening
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-a-published-statement-is-accepted-before-use-per-version-req-ihc-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Hardening;

use DateTime;
use InvalidArgumentException;
use OCP\IAppConfig;
use OCP\IConfig;
use Throwable;

/**
 * Publishes the statement, and records who accepted which version.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Hardening
 */
class StatementService {

	/**
	 * Where the published statement is stored, as JSON.
	 *
	 * @var string
	 */
	public const STATEMENT_KEY = 'hardening_statement';

	/**
	 * The user preference holding the accepted version and the time.
	 *
	 * @var string
	 */
	public const ACCEPTANCE_KEY = 'hardening_statement_accepted';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig           $appConfig Stores the published statement.
	 * @param IConfig              $config    Stores one user's acceptance.
	 * @param HardeningAuditWriter $audit     Records the publication and the acceptance.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IConfig $config,
		private readonly HardeningAuditWriter $audit,
	) {

	}//end __construct()

	/**
	 * The statement in force, or null when nothing is published.
	 *
	 * @return array{version: string, title: string, body: string, publishedAt: string, publishedBy: string}|null The statement.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-a-published-statement-is-accepted-before-use-per-version-req-ihc-001
	 */
	public function published(): ?array {
		try {
			$raw = $this->appConfig->getValueString(HardeningPolicy::APP_ID, self::STATEMENT_KEY, '');
		} catch (Throwable) {
			return null;
		}

		if (trim($raw) === '') {
			return null;
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			return null;
		}

		$version = (string)($decoded['version'] ?? '');
		$body = (string)($decoded['body'] ?? '');
		if ($version === '' || $body === '') {
			return null;
		}

		return [
			'version' => $version,
			'title' => (string)($decoded['title'] ?? ''),
			'body' => $body,
			'publishedAt' => (string)($decoded['publishedAt'] ?? ''),
			'publishedBy' => (string)($decoded['publishedBy'] ?? ''),
		];

	}//end published()

	/**
	 * Publish a statement, or a new version of one.
	 *
	 * @param string $version The version, as the administrator writes it.
	 * @param string $body    The text a user reads.
	 * @param string $title   The heading above it.
	 * @param string $userId  Who published it.
	 *
	 * @return array{version: string, title: string, body: string, publishedAt: string, publishedBy: string} The statement now in force.
	 *
	 * @throws InvalidArgumentException When the version or the text is missing.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-a-published-statement-is-accepted-before-use-per-version-req-ihc-001
	 */
	public function publish(string $version, string $body, string $title = '', string $userId = ''): array {
		$version = trim($version);
		$body = trim($body);

		if ($version === '') {
			throw new InvalidArgumentException('A statement carries a version, so an acceptance can name one.');
		}

		if ($body === '') {
			throw new InvalidArgumentException('A statement carries the text a user is asked to accept.');
		}

		$before = ($this->published()['version'] ?? '');

		$statement = [
			'version' => $version,
			'title' => trim($title),
			'body' => $body,
			'publishedAt' => (new DateTime())->format('c'),
			'publishedBy' => $userId,
		];

		$this->appConfig->setValueString(
			HardeningPolicy::APP_ID,
			self::STATEMENT_KEY,
			(string)json_encode($statement)
		);

		$this->audit->record(fact: 'statement.published', before: $before, after: $version, accepted: true);

		return $statement;

	}//end publish()

	/**
	 * Withdraw the statement, so nothing is asked.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-a-published-statement-is-accepted-before-use-per-version-req-ihc-001
	 */
	public function withdraw(): void {
		$before = ($this->published()['version'] ?? '');
		$this->appConfig->setValueString(HardeningPolicy::APP_ID, self::STATEMENT_KEY, '');
		$this->audit->record(fact: 'statement.withdrawn', before: $before, after: '', accepted: true);

	}//end withdraw()

	/**
	 * What one user has accepted, or null when they have accepted nothing.
	 *
	 * @param string $userId The account.
	 *
	 * @return array{version: string, acceptedAt: string}|null The acceptance.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-a-published-statement-is-accepted-before-use-per-version-req-ihc-001
	 */
	public function acceptanceOf(string $userId): ?array {
		if (trim($userId) === '') {
			return null;
		}

		try {
			$raw = $this->config->getUserValue($userId, HardeningPolicy::APP_ID, self::ACCEPTANCE_KEY, '');
		} catch (Throwable) {
			return null;
		}

		$decoded = json_decode((string)$raw, true);
		if (is_array($decoded) === false) {
			return null;
		}

		$version = (string)($decoded['version'] ?? '');
		if ($version === '') {
			return null;
		}

		return [
			'version' => $version,
			'acceptedAt' => (string)($decoded['acceptedAt'] ?? ''),
		];

	}//end acceptanceOf()

	/**
	 * Whether this user is asked before the application renders.
	 *
	 * An anonymous caller is never asked: there is nobody to record the
	 * acceptance against, and a statement accepted by nobody is not evidence.
	 *
	 * @param string $userId The account, or an empty string for an anonymous caller.
	 *
	 * @return bool True when the statement must be shown and accepted first.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-a-published-statement-is-accepted-before-use-per-version-req-ihc-001
	 */
	public function needsAcceptance(string $userId): bool {
		$statement = $this->published();
		if ($statement === null || trim($userId) === '') {
			return false;
		}

		$acceptance = $this->acceptanceOf(userId: $userId);
		if ($acceptance === null) {
			return true;
		}

		return $acceptance['version'] !== $statement['version'];

	}//end needsAcceptance()

	/**
	 * Record that this user accepted this version.
	 *
	 * The version is checked against the one in force rather than trusted from
	 * the request: a client that posts an old version would otherwise close the
	 * gate on a text the user was never shown.
	 *
	 * @param string $userId  The account accepting.
	 * @param string $version The version they were shown.
	 *
	 * @return array{version: string, acceptedAt: string} The acceptance as recorded.
	 *
	 * @throws InvalidArgumentException When nothing is published, or the version is not the one in force.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-a-published-statement-is-accepted-before-use-per-version-req-ihc-001
	 */
	public function accept(string $userId, string $version): array {
		$statement = $this->published();
		if ($statement === null) {
			throw new InvalidArgumentException('This instance publishes no statement, so there is nothing to accept.');
		}

		if (trim($userId) === '') {
			throw new InvalidArgumentException('An acceptance is recorded against an account.');
		}

		if (trim($version) !== $statement['version']) {
			$this->audit->record(
				fact: 'statement.accepted',
				before: trim($version),
				after: $statement['version'],
				accepted: false,
				refusal: 'The version accepted is not the version in force.',
			);

			throw new InvalidArgumentException(
				'The statement has moved on to version ' . $statement['version'] . '. Read it again before accepting.'
			);
		}

		$acceptance = [
			'version' => $statement['version'],
			'acceptedAt' => (new DateTime())->format('c'),
		];

		$this->config->setUserValue(
			$userId,
			HardeningPolicy::APP_ID,
			self::ACCEPTANCE_KEY,
			(string)json_encode($acceptance)
		);

		$this->audit->record(
			fact: 'statement.accepted',
			before: '',
			after: [
				'user' => $userId,
				'version' => $acceptance['version'],
				'acceptedAt' => $acceptance['acceptedAt'],
			],
			accepted: true,
		);

		return $acceptance;

	}//end accept()
}//end class
