<?php
declare(strict_types=1);

/**
 * Root entry point — redirects cleanly to public/index.php
 * Works when hosted directly in htdocs/Interview-Panel or Hostinger public_html.
 */
header('Location: public/index.php');
exit;
