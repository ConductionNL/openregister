# UnsubscribeHeaders

`OCA\OpenRegister\Service\Notification\UnsubscribeHeaders` sets the two RFC 8058 headers on a Nextcloud mail message:

```
List-Unsubscribe: <https://nc.example/apps/integriq/unsubscribe/...>
List-Unsubscribe-Post: List-Unsubscribe=One-Click
```

It is the one copy of this code in the fleet. dossiq and pipelinq call it instead of keeping their own (hydra `opt-out-before-send`, decision 6). Both already depend on OpenRegister, so they inject it by class.

## Contract

```php
public function apply(OCP\Mail\IMessage $message, array $unsubscribe): bool
```

- `$unsubscribe` is the `unsubscribe` material integriq returns in an `OutboundSendDecisionRequestedEvent` decision: `{url, oneClickUrl, smsText, headers}`. The helper reads `oneClickUrl`, and `url` when that is empty.
- It returns `true` when both headers are set.
- It returns `false`, and never throws, when the material has no safe http(s) url, or when the message does not expose `getSymfonyEmail()`. `IMessage` has no header setter, so that method is reached behind `method_exists()`.
- An existing `List-Unsubscribe` header is replaced, not duplicated.

The headers are best effort. The link in the body is not: on `false`, send the mail anyway, with the link line in its body.

## Use

```php
use OCA\OpenRegister\Service\Notification\UnsubscribeHeaders;

public function __construct(private readonly UnsubscribeHeaders $unsubscribeHeaders) {}

$message = $this->mailer->createMessage();
$message->setPlainBody($body . "\n\nStop receiving these messages: " . $material['url']);
$this->unsubscribeHeaders->apply($message, $material);
$this->mailer->send($message);
```

Inside OpenRegister, `EmailSender::sendToAddress()` takes the material as its optional `unsubscribe` argument and calls the helper for you.

An exempt category (`besluit`, `statutory`, `account`, `security`) comes back with `unsubscribe: null`. Pass nothing then: an exempt mail carries neither a link nor the headers.

## Next

Ask integriq first. The decision event and its fail-closed rule are in hydra `openspec/changes/opt-out-before-send/design.md`, sections 2 and 3. OpenRegister's own caller is `OptOutAuthority::ask()`.
