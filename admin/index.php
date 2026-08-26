<?php
/**
 * Root entry point — BtPanel site root is this directory.
 * All requests forward to public/index.php, keeping src/ out of web root.
 */
define('ADMIN_ROOT', __DIR__);
require ADMIN_ROOT . '/public/index.php';
