<?php

use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

$app = AppFactory::create();

$app->addBodyParsingMiddleware();

require __DIR__ . '/../src/middleware.php';
require __DIR__ . '/../src/config.php';
require __DIR__ . '/../src/routes.php';

setupRoutes($app);

$app->run();