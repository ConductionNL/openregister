<?php

/**
 * A property whose values come from an integration provider.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schemas
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/property-source-vocabulary/specs/schema-vocabulaire/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schemas;

/**
 * `x-openregister-property-source` binds ONE PROPERTY's values to a provider.
 *
 * 🔴 IT IS NOT `x-openregister-object-source`. That key serves a WHOLE SCHEMA's
 * objects from a provider instead of the magic table. This one serves one
 * property's values. Two keys differing by one word and by their entire blast
 * radius is worth a sentence here, because the failure is not an error: a
 * schema declaring the wrong one of the two is accepted by both, and the
 * symptom is an entire register served from somewhere unexpected.
 *
 * 🔑 THE REASON THIS CLASS EXISTS AT ALL IS THAT AN `x-` KEY IS ACCEPTED
 * WITHOUT IT. `assertKeysAreInTheVocabulary()` skips every `x-` prefixed key,
 * so a property could carry `{"provider": 7}` or `{"mode": "livee"}` and save
 * cleanly, and the consumer would read whatever it could and guess the rest.
 * That is the same shape as the concept-scheme binding, which was accepted for
 * months and could not be forwarded because nothing published it.
 *
 * @spec openspec/changes/property-source-vocabulary/specs/schema-vocabulaire/spec.md
 */
final class PropertySourceDeclaration {

	/**
	 * The annotation.
	 */
	public const ANNOTATION = 'x-openregister-property-source';

	/**
	 * The key this one is most likely to be confused with.
	 */
	public const NOT_THIS_ONE = 'x-openregister-object-source';

	/**
	 * Values are fetched from the provider when the field is used.
	 */
	public const MODE_LIVE = 'live';

	/**
	 * The provider supplies a starting value; a person may change it.
	 */
	public const MODE_DEFAULT = 'default';

	/**
	 * The modes this key accepts.
	 *
	 * @var array<int, string>
	 */
	public const MODES = [self::MODE_LIVE, self::MODE_DEFAULT];

	/**
	 * What a provider id may look like.
	 *
	 * A provider is discovered by a DI tag on the integriq side, so the id is
	 * an identifier and not prose. Checking it here means a typo is named at
	 * schema save rather than becoming an empty option list in a form later.
	 */
	public const PROVIDER_PATTERN = '/^[a-z0-9][a-z0-9._-]{0,63}$/i';

	/**
	 * The provider this property's values come from.
	 *
	 * @var string
	 */
	public readonly string $provider;

	/**
	 * How the provider's value is used.
	 *
	 * @var string
	 */
	public readonly string $mode;

	/**
	 * What the provider is given.
	 *
	 * @var array<string, mixed>
	 */
	public readonly array $config;

	/**
	 * Build a declaration.
	 *
	 * @param string               $provider The provider id.
	 * @param string               $mode     The mode.
	 * @param array<string, mixed> $config   The provider's configuration.
	 */
	private function __construct(string $provider, string $mode, array $config) {
		$this->provider = $provider;
		$this->mode = $mode;
		$this->config = $config;
	}//end __construct()

	/**
	 * Read the declaration off a property, refusing anything malformed.
	 *
	 * @param array<string, mixed> $property The compiled property.
	 * @param string               $path     Where the property sits, for the message.
	 *
	 * @return self|null The declaration, or null when the property carries none.
	 *
	 * @throws PropertySourceException When the declaration cannot be honoured.
	 *
	 * @spec openspec/changes/property-source-vocabulary/specs/schema-vocabulaire/spec.md
	 */
	public static function fromProperty(array $property, string $path = ''): ?self {
		if (array_key_exists(self::ANNOTATION, $property) === false) {
			return null;
		}

		$raw = $property[self::ANNOTATION];

		if (is_array($raw) === false || $raw === []) {
			throw new PropertySourceException(
				sprintf(
					'\'%s\' at \'%s\' must be an object naming a provider, and it is not.',
					self::ANNOTATION,
					$path
				)
			);
		}

		$provider = self::validProvider(raw: $raw, path: $path);
		$mode = self::validMode(raw: $raw, path: $path);

		$config = ($raw['config'] ?? []);
		if (is_array($config) === false) {
			throw new PropertySourceException(
				sprintf(
					'\'%s\' at \'%s\' has a config that is not an object.',
					self::ANNOTATION,
					$path
				)
			);
		}

		// 🔑 BOTH SOURCE KEYS ON ONE PROPERTY IS REFUSED RATHER THAN RANKED.
		// They answer different questions at different scopes, so a property
		// carrying both is a schema whose author meant one of them. Picking one
		// would be right about half the time and silent the rest.
		if (array_key_exists(self::NOT_THIS_ONE, $property) === true) {
			throw new PropertySourceException(
				sprintf(
					'\'%s\' at \'%s\' carries both \'%s\' and \'%s\'. '
					. 'The first binds this one property to a provider; the second serves the whole '
					. 'schema\'s objects from one. Keep the one that was meant.',
					self::ANNOTATION,
					$path,
					self::ANNOTATION,
					self::NOT_THIS_ONE
				)
			);
		}

		return new self(provider: $provider, mode: $mode, config: $config);
	}//end fromProperty()

	/**
	 * The declared provider id, refusing anything that is not one.
	 *
	 * @param array<string, mixed> $raw  The declaration block.
	 * @param string               $path Where the property sits, for the message.
	 *
	 * @return string The provider id, trimmed.
	 *
	 * @throws PropertySourceException When no usable provider is named.
	 *
	 * @spec openspec/changes/property-source-vocabulary/specs/schema-vocabulaire/spec.md
	 */
	private static function validProvider(array $raw, string $path): string {
		$provider = ($raw['provider'] ?? null);
		if (is_string($provider) === false || trim($provider) === '') {
			throw new PropertySourceException(
				sprintf(
					'\'%s\' at \'%s\' must name a provider. Without one there is nothing to ask for the values.',
					self::ANNOTATION,
					$path
				)
			);
		}

		$provider = trim($provider);
		if (preg_match(self::PROVIDER_PATTERN, $provider) !== 1) {
			throw new PropertySourceException(
				sprintf(
					'\'%s\' at \'%s\' names the provider \'%s\', which is not a provider id. '
					. 'A typo here becomes an empty list in a form, with nothing to say why.',
					self::ANNOTATION,
					$path,
					$provider
				)
			);
		}

		return $provider;
	}//end validProvider()

	/**
	 * The declared mode, refusing anything this class does not know.
	 *
	 * The mode is optional and defaults to `live`, which is what a
	 * registry-backed field is for: the value is looked up when it is used.
	 * `default` is the weaker promise and has to be asked for by name.
	 *
	 * @param array<string, mixed> $raw  The declaration block.
	 * @param string               $path Where the property sits, for the message.
	 *
	 * @return string The mode.
	 *
	 * @throws PropertySourceException When the mode is not one of the two.
	 *
	 * @spec openspec/changes/property-source-vocabulary/specs/schema-vocabulaire/spec.md
	 */
	private static function validMode(array $raw, string $path): string {
		$mode = ($raw['mode'] ?? self::MODE_LIVE);
		if (is_string($mode) === true && in_array($mode, self::MODES, true) === true) {
			return $mode;
		}

		$shownMode = gettype($mode);
		if (is_scalar($mode) === true) {
			$shownMode = (string)$mode;
		}

		throw new PropertySourceException(
			sprintf(
				'\'%s\' at \'%s\' has mode \'%s\'. It must be one of: %s. '
				. 'A mode nobody knows would be read as a guess, and the two modes differ in '
				. 'whether a person may change what the provider returned.',
				self::ANNOTATION,
				$path,
				$shownMode,
				implode(', ', self::MODES)
			)
		);
	}//end validMode()

	/**
	 * Refuse a property whose declaration cannot be honoured.
	 *
	 * @param array<string, mixed> $property The compiled property.
	 * @param string               $path     Where the property sits.
	 *
	 * @return void
	 *
	 * @throws PropertySourceException When the declaration cannot be honoured.
	 *
	 * @spec openspec/changes/property-source-vocabulary/specs/schema-vocabulaire/spec.md
	 */
	public static function assert(array $property, string $path = ''): void {
		self::fromProperty(property: $property, path: $path);
	}//end assert()
}//end class
