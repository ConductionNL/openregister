<?php
// SPDX-License-Identifier: EUPL-1.2
//
// A template that OWNS THE DOCUMENT and therefore IS in scope — and answers
// the gate properly, with a real bypass anchor as the first focusable
// element. The pair with `accidental/templates/standalone.php` is the whole
// point: same shape, same scope, one has the affordance and one does not.
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<title>Fixture standalone page</title>
</head>
<body>
	<a href="#main-content" class="skip-link">Skip to main content</a>
	<nav class="fixture-nav">
		<a href="/apps/fixture/things">Things</a>
	</nav>
	<main id="main-content">
		<?php print_unescaped($_['body'] ?? ''); ?>
	</main>
</body>
</html>
