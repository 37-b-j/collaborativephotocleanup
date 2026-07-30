<?php
\OCP\Util::addScript("collaborativephotocleanup", "collaborativephotocleanup-main");
?>
<style>
body { min-height: 100%; height: auto; position: initial; }
#content { overflow: hidden !important; z-index: 2001 !important; }
@media (max-width: 768px) { #app { padding: 8px !important; max-width: 100vw !important; overflow-x: hidden !important; } }
@media (display-mode: standalone) { body { padding-top: env(safe-area-inset-top); } }
</style>
<div id="app"></div>