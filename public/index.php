<?php

declare(strict_types=1);

use App\Cache\FileCache;
use App\DataSource\DsnClient;
use App\DataSource\HorizonsClient;
use App\VoyagerDataService;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

require dirname(__DIR__) . '/vendor/autoload.php';

$appConfig = require dirname(__DIR__) . '/config/app.php';
$probes = require dirname(__DIR__) . '/config/probes.php';

$app = AppFactory::create();
$app->addRoutingMiddleware();

$twig = Twig::create(dirname(__DIR__) . '/templates', [
    'cache' => false,
]);
$app->add(TwigMiddleware::create($app, $twig));

$dataService = new VoyagerDataService(
    new HorizonsClient($appConfig['horizonsApiUrl'], $appConfig['httpTimeoutSeconds']),
    new DsnClient($appConfig['dsnFeedUrl'], $appConfig['httpTimeoutSeconds']),
    new FileCache($appConfig['cacheDir'], $appConfig['cacheTtlSeconds']),
    $probes,
);

$errorMiddleware = $app->addErrorMiddleware(displayErrorDetails: false, logErrors: true, logErrorDetails: true);

$renderDataError = static function ($request, $response, Twig $twig) {
    $response = $response->withStatus(503);

    return $twig->render($response, 'error.twig', [
        'refreshCadenceLabel' => 'refreshes every 15 minutes',
    ]);
};

$app->get('/', function ($request, $response) use ($dataService, $twig, $appConfig, $renderDataError) {
    try {
        $v1 = $dataService->getSummary('voyager-1');
        $v2 = $dataService->getSummary('voyager-2');
        $orrery = $dataService->getOrreryLayout();
        $distanceModal = $dataService->getDistanceModalLayout();
    } catch (\Throwable) {
        return $renderDataError($request, $response, $twig);
    }

    return $twig->render($response, 'home.twig', [
        'v1' => $v1,
        'v2' => $v2,
        'orrery' => $orrery,
        'distanceModal' => $distanceModal,
        'stale' => $v1['stale'] || $v2['stale'],
        'updatedLabel' => $v1['updatedLabel'],
        'refreshCadenceLabel' => $appConfig['refreshCadenceLabel'],
        'active' => 'home',
    ]);
});

$app->get('/voyager-1', function ($request, $response) use ($dataService, $twig, $renderDataError) {
    try {
        $probe = $dataService->getProbe('voyager-1');
    } catch (\Throwable) {
        return $renderDataError($request, $response, $twig);
    }

    return $twig->render($response, 'detail.twig', ['probe' => $probe, 'active' => 'voyager-1']);
});

$app->get('/voyager-2', function ($request, $response) use ($dataService, $twig, $renderDataError) {
    try {
        $probe = $dataService->getProbe('voyager-2');
    } catch (\Throwable) {
        return $renderDataError($request, $response, $twig);
    }

    return $twig->render($response, 'detail.twig', ['probe' => $probe, 'active' => 'voyager-2']);
});

foreach (['milestones' => 'Milestones', 'about' => 'About', 'sources' => 'Sources'] as $slug => $title) {
    $app->get("/{$slug}", function ($request, $response) use ($twig, $slug, $title) {
        return $twig->render($response, 'stub.twig', ['title' => $title, 'active' => $slug]);
    });
}

$app->run();
