<?php
/**
 * Created by Mahedi Azad.
 * User: micro
 * Date: 8/23/14
 * Time: 8:50 AM
 */

require 'vendor/autoload.php';


$app = new \Slim\Slim();

var_dump(1);

$app->get('/hello/:name', function ($name) {
    echo "Hello, $name";
});

$app->run();