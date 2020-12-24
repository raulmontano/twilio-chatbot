<?php

require "vendor/autoload.php";

use Inbenta\TwilioConnector\TwilioConnector;

//Instance new TwilioConnector
$appPath=__DIR__.'/';
$app = new TwilioConnector($appPath);

//Handle the incoming request
$app->handleRequest();
