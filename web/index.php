<?php
/**
 * Copyright (C) 2020 Xibo Signage Ltd
 *
 * Xibo - Digital Signage - http://www.xibo.org.uk
 *
 * This file is part of Xibo.
 *
 * Xibo is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 *
 * Xibo is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Xibo.  If not, see <http://www.gnu.org/licenses/>.
 */

use Monolog\Logger;
use Monolog\Processor\UidProcessor;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Container\ContainerInterface;
use Slim\Http\Factory\DecoratedResponseFactory;
use Slim\Http\Response as Response;
use Slim\Http\ServerRequest as Request;
use Slim\Views\TwigMiddleware;
use Xibo\Exception\UpgradePendingException;
use Xibo\Factory\ContainerFactory;

DEFINE('XIBO', true);
define('PROJECT_ROOT', realpath(__DIR__ . '/..'));

error_reporting(1);
ini_set('display_errors', 1);

require PROJECT_ROOT . '/vendor/autoload.php';

// Should we show the installer?
if (!file_exists('settings.php')) {
    // Check to see if the install app is available
    if (file_exists(PROJECT_ROOT . '/web/install/index.php')) {
        header('Location: install/');
        exit();
    } else {
        // We can't do anything here - no install app and no settings file.
        die('Not configured');
    }
}

// Check that the cache folder if writeable - if it isn't we're in big trouble
if (!is_writable(PROJECT_ROOT . '/cache')) {
    die('Installation Error: Cannot write files into the Cache Folder');
}

// Create the container for dependency injection.
try {
    $container = ContainerFactory::create();
} catch (Exception $e) {
    die($e->getMessage());
}


$container->set('logger', function (ContainerInterface $container) {
    $logger = new Logger('WEB');

    $uidProcessor = new UidProcessor();
    // db
    $dbhandler  =  new \Xibo\Helper\DatabaseLogHandler();

    $logger->pushProcessor($uidProcessor);
    $logger->pushHandler($dbhandler);

    return $logger;
});

// Create a Slim application
$app = \DI\Bridge\Slim\Bridge::create($container);
$app->setBasePath(\Xibo\Middleware\State::determineBasePath());

// Config
$app->config = $container->get('configService');
$app->router = $app->getRouteCollector()->getRouteParser();

//
// Middleware (onion, outside inwards and then out again - i.e. the last one is first and last);
//
$twigMiddleware = TwigMiddleware::createFromContainer($app);
$app->add(new RKA\Middleware\IpAddress(true, []));
$app->add(new \Xibo\Middleware\Actions($app));
$app->add(new \Xibo\Middleware\Theme($app));
$app->add(new \Xibo\Middleware\WebAuthentication($app));
$app->add(new \Xibo\Middleware\Storage($app));
$app->add(new \Xibo\Middleware\State($app));
$app->add(new \Xibo\Middleware\Log($app));
$app->add($twigMiddleware);
$app->add(new \Xibo\Middleware\Xmr($app));

$app->addRoutingMiddleware();


/* TODO Authentication middleware
if ($app->configService->authentication != null && $app->configService->authentication instanceof \Slim\Middleware)
    $app->add($app->configService->authentication);
else
    $app->add(new \Xibo\Middleware\WebAuthentication());
*/
// Standard Xibo middleware
// TODO, investigate if we still want to use csrf
//$app->add(new \Xibo\Middleware\CsrfGuard());


// Handle additional Middleware
\Xibo\Middleware\State::setMiddleWare($app);
//
// End Middleware
//

// Define Custom Error Handler
$customErrorHandler = function (Request $request, Throwable $exception, bool $displayErrorDetails, bool $logErrors, bool $logErrorDetails) use ($app) {
    $nyholmFactory = new Psr17Factory();
    $decoratedResponseFactory = new DecoratedResponseFactory($nyholmFactory, $nyholmFactory);
    /** @var Response $response */
    $response = $decoratedResponseFactory->createResponse($exception->getCode());

    if ($exception->getCode() == 404) {
        $app->getContainer()->get('logger')->debug(sprintf('Page Not Found. %s', $request->getUri()->getPath()));
        return $response->withRedirect('/notFound');
    } else {
        $app->getContainer()->get('session')->set('exceptionMessage', $exception->getMessage());
        $app->getContainer()->get('session')->set('exceptionCode', $exception->getCode());
        $exceptionClass = 'error-' . strtolower(str_replace('\\', '-', get_class($exception)));

        if ($exception instanceof UpgradePendingException) {
            $exceptionClass = 'upgrade-in-progress-page';
        }

        $app->getContainer()->get('session')->set('exceptionClass', $exceptionClass);
        return $response->withRedirect('/error');
    }
};

// Add Error Middleware
$errorMiddleware = $app->addErrorMiddleware(true, true, true);
//$errorMiddleware->setDefaultErrorHandler($customErrorHandler);

// All application routes
require PROJECT_ROOT . '/lib/routes-web.php';
require PROJECT_ROOT . '/lib/routes.php';

// Run App
try {
    $app->run();
}
catch (Exception $e) {
    echo 'Fatal Error - sorry this shouldn\'t happen. ';
    echo $e->getMessage();
}
