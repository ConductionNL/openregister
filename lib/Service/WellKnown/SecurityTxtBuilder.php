<?php

/**
 * Builds the administered `security.txt` (RFC 9116).
 *
 * 🔴 NOTHING ADMINISTERED MEANS NO FILE. Not a template, not a placeholder.
 * A `security.txt` naming `security@example.com` reads as a working disclosure
 * channel and swallows the report, which is worse than the 404 that at least
 * tells a researcher to find another way in.
 *
 * 🔑 `Expires` IS MANDATORY IN RFC 9116, AND A STALE ONE IS WORSE THAN NONE.
 * The field exists so a researcher can tell whether the contact is still
 * maintained. An instance that set it once and let it lapse is publishing a
 * file that says, correctly, "do not trust this". So it is computed forward
 * from now on every read rather than stored, and the docblock says why: a
 * stored date is a date somebody has to remember to move, and nobody does.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\WellKnown
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\WellKnown;

use DateTimeImmutable;
use DateTimeZone;
use OCP\IAppConfig;
use Throwable;

/**
 * Renders the administered responsible-disclosure contact.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\WellKnown
 *
 * @SuppressWarnings(PHPMD.StaticAccess)
 * Reason: `DateTimeImmutable::createFromFormat` and the `DateTimeImmutable`
 *         constructor are PHP's own date API, which has no instance form.
 */
class SecurityTxtBuilder {

	/**
	 * The app the settings live under.
	 *
	 * @var string
	 */
	private const APP_ID = 'openregister';

	/**
	 * Configuration key holding the contact, a mailto: or https: URI.
	 *
	 * @var string
	 */
	public const CONTACT_KEY = 'security_contact';

	/**
	 * Configuration key holding the disclosure policy URL.
	 *
	 * @var string
	 */
	public const POLICY_KEY = 'security_policy_url';

	/**
	 * Configuration key holding the preferred languages, comma separated.
	 *
	 * @var string
	 */
	public const LANGUAGES_KEY = 'security_languages';

	/**
	 * How far ahead the `Expires` field is set, in days.
	 *
	 * RFC 9116 asks for less than a year. Ninety days is short enough that an
	 * abandoned instance stops claiming to have a live channel reasonably soon,
	 * and long enough that a researcher reading it today can act on it.
	 *
	 * @var int
	 */
	public const EXPIRES_DAYS = 90;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Reads the administered contact.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {

	}//end __construct()

	/**
	 * The file, or null when no contact is administered.
	 *
	 * @param DateTimeImmutable|null $now The moment to compute `Expires` from.
	 *
	 * @return string|null The file body, or null.
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md#requirement-the-instance-answers-the-well-known-paths-and-honours-an-administered-proxy-req-avs-004
	 */
	public function build(?DateTimeImmutable $now = null): ?string {
		$contact = $this->normaliseContact(value: $this->read(key: self::CONTACT_KEY));
		if ($contact === null) {
			return null;
		}

		$moment = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')));
		$expires = $moment->modify('+' . self::EXPIRES_DAYS . ' days');

		$lines = [
			'Contact: ' . $contact,
			'Expires: ' . $expires->format('Y-m-d\TH:i:s\Z'),
		];

		$policy = $this->read(key: self::POLICY_KEY);
		if ($policy !== null && $this->isHttpUrl(value: $policy) === true) {
			$lines[] = 'Policy: ' . $policy;
		}

		$languages = $this->languages();
		if ($languages !== '') {
			$lines[] = 'Preferred-Languages: ' . $languages;
		}

		return (implode("\n", $lines) . "\n");

	}//end build()

	/**
	 * Whether a disclosure contact is administered.
	 *
	 * @return bool True when this instance publishes a contact.
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md
	 */
	public function isConfigured(): bool {
		return ($this->normaliseContact(value: $this->read(key: self::CONTACT_KEY)) !== null);

	}//end isConfigured()

	/**
	 * The contact as a usable URI, or null.
	 *
	 * A bare address is accepted and turned into a `mailto:`, because an
	 * administrator typing an email address into a field labelled "security
	 * contact" has said what they mean, and refusing them over a scheme is how
	 * the field ends up empty.
	 *
	 * @param string|null $value The administered value.
	 *
	 * @return string|null The contact URI, or null.
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md
	 */
	private function normaliseContact(?string $value): ?string {
		if ($value === null) {
			return null;
		}

		if (stripos($value, 'mailto:') === 0 || stripos($value, 'tel:') === 0) {
			return $value;
		}

		if ($this->isHttpUrl(value: $value) === true) {
			return $value;
		}

		if (filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
			return ('mailto:' . $value);
		}

		return null;

	}//end normaliseContact()

	/**
	 * Whether a value is an http or https URL.
	 *
	 * @param string $value The candidate.
	 *
	 * @return bool True when it is.
	 */
	private function isHttpUrl(string $value): bool {
		if (preg_match('#^https?://#i', $value) !== 1) {
			return false;
		}

		return (filter_var($value, FILTER_VALIDATE_URL) !== false);

	}//end isHttpUrl()

	/**
	 * The preferred languages, as a comma-separated list of tags.
	 *
	 * @return string The list, or the empty string.
	 */
	private function languages(): string {
		$raw = $this->read(key: self::LANGUAGES_KEY);
		if ($raw === null) {
			return '';
		}

		$tags = [];
		foreach (explode(',', $raw) as $tag) {
			$trimmed = trim($tag);
			if (preg_match('/^[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/i', $trimmed) === 1) {
				$tags[] = $trimmed;
			}
		}

		return implode(', ', $tags);

	}//end languages()

	/**
	 * Read one administered value, or null when it is unset.
	 *
	 * @param string $key The configuration key.
	 *
	 * @return string|null The value, or null.
	 */
	private function read(string $key): ?string {
		try {
			$value = trim((string)$this->appConfig->getValueString(self::APP_ID, $key, ''));
		} catch (Throwable) {
			return null;
		}

		if ($value === '') {
			return null;
		}

		return $value;

	}//end read()
}//end class
