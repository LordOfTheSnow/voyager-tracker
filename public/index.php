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
$milestones = require dirname(__DIR__) . '/config/milestones.php';
$about = require dirname(__DIR__) . '/config/about.php';

$app = AppFactory::create();
$app->addRoutingMiddleware();

$twig = Twig::create(dirname(__DIR__) . '/templates', [
    'cache' => false,
]);
$twig->getEnvironment()->addGlobal('appVersion', $appConfig['version']);
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
    if (!isset($request->getQueryParams()['refresh']) && !$dataService->isHomeDataFresh()) {
        return $twig->render($response, 'loading.twig', [
            'refreshUrl' => '/?refresh=1',
            'refreshCadenceLabel' => $appConfig['refreshCadenceLabel'],
        ]);
    }

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

$app->get('/voyager-1', function ($request, $response) use ($dataService, $twig, $appConfig, $renderDataError) {
    if (!isset($request->getQueryParams()['refresh']) && !$dataService->isProbeDataFresh('voyager-1')) {
        return $twig->render($response, 'loading.twig', [
            'refreshUrl' => '/voyager-1?refresh=1',
            'refreshCadenceLabel' => $appConfig['refreshCadenceLabel'],
        ]);
    }

    try {
        $probe = $dataService->getProbe('voyager-1');
    } catch (\Throwable) {
        return $renderDataError($request, $response, $twig);
    }

    return $twig->render($response, 'detail.twig', ['probe' => $probe, 'active' => 'voyager-1']);
});

$app->get('/voyager-2', function ($request, $response) use ($dataService, $twig, $appConfig, $renderDataError) {
    if (!isset($request->getQueryParams()['refresh']) && !$dataService->isProbeDataFresh('voyager-2')) {
        return $twig->render($response, 'loading.twig', [
            'refreshUrl' => '/voyager-2?refresh=1',
            'refreshCadenceLabel' => $appConfig['refreshCadenceLabel'],
        ]);
    }

    try {
        $probe = $dataService->getProbe('voyager-2');
    } catch (\Throwable) {
        return $renderDataError($request, $response, $twig);
    }

    return $twig->render($response, 'detail.twig', ['probe' => $probe, 'active' => 'voyager-2']);
});

$app->get('/about', function ($request, $response) use ($twig, $about) {
    return $twig->render($response, 'about.twig', ['active' => 'about', 'about' => $about]);
});

$app->get('/milestones', function ($request, $response) use ($twig, $milestones) {
    $sorted = $milestones;
    usort($sorted, static fn (array $a, array $b): int => $b['date'] <=> $a['date']);

    return $twig->render($response, 'milestones.twig', [
        'milestones' => $sorted,
        'milestoneCount' => count($sorted),
        'oldestYear' => date('Y', strtotime(end($sorted)['date'])),
        'active' => 'milestones',
    ]);
});

$app->get('/sources', function ($request, $response) use ($twig) {
    return $twig->render($response, 'sources.twig', ['active' => 'sources']);
});

$app->run();
