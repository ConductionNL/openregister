<?php
/**
 * An object's document in Nextcloud Office.
 *
 * The form posts the WOPI token into the frame, which is how Collabora
 * expects a token to arrive. No script is needed: the button submits it.
 *
 * @license EUPL-1.2
 *
 * @var array{frameAction: string, token: string, tokenTtl: int, readOnly: bool} $_
 * @var \OCP\IL10N $l
 */
?>
<div id="openregister-office" style="display:flex;flex-direction:column;height:100%;width:100%">
	<form id="openregister-office-form" method="post" target="openregister-office-frame" action="<?php p($_['frameAction']); ?>" style="padding:8px 16px">
		<input type="hidden" name="access_token" value="<?php p($_['token']); ?>">
		<input type="hidden" name="access_token_ttl" value="<?php p((string)$_['tokenTtl']); ?>">
		<button type="submit" class="primary">
			<?php if ($_['readOnly'] === true) { p($l->t('Open document read-only')); } else { p($l->t('Open document')); } ?>
		</button>
	</form>
	<iframe id="openregister-office-frame" name="openregister-office-frame" title="<?php p($l->t('Document')); ?>" allow="clipboard-read *; clipboard-write *" style="flex:1;border:0;width:100%;min-height:80vh"></iframe>
</div>
