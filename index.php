<?php
declare(strict_types=1);

// Front controller: every clean URL ends up here (see .htaccess).
define('CMS_ROOT', __DIR__);
require CMS_ROOT . '/core/engine/bootstrap.php';

Cms\App::run();
