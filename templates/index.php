<?php
/** @var array $_ */
// For an app's script, addScript also queues the signed-in user's
// l10n/<language>.js ahead of it, which src/l10n.js reads.
\OCP\Util::addScript('crate', 'crate-main');
?>
<div id="app"></div>
