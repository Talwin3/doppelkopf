<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

// Der Docker-Container exportiert APP_ENV=dev und DATABASE_URL (dev-DB) als echte
// Env-Variablen. Diese hätten Vorrang vor phpunit.dist.xml und .env.test, sodass
// Tests fälschlich im dev-Env gegen die dev-Datenbank liefen. Tests laufen immer im
// test-Env gegen doppelkopf_test → hier erzwingen, bevor die Dotenv-Dateien geladen
// werden. DATABASE_URL wird entfernt, damit der Wert aus .env.test greift.
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
putenv('APP_ENV=test');
unset($_SERVER['DATABASE_URL'], $_ENV['DATABASE_URL']);
putenv('DATABASE_URL');

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
