<?php

require_once __DIR__ . '/../vendor/autoload.php';

// Manda las headers que permiten al cliente conectarse.
Ts\HttpServer::sendHeaders();

$app = new FrameworkX\App();

//$app->get('/min/public/index.php/', new Ts\ControllerGet());

$urlBase = "/min/public/index.php";

$app->post($urlBase . '/', new Ts\DataController());

$app->run();
