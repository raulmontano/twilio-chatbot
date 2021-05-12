<?php

/**
 * DON'T MODIFY THIS FILE!!! READ "conf/README.md" BEFORE.
 */

// Inbenta Hyperchat configuration
return [
    'chat' => [
        'enabled' => true,
        'version' => '1',
        'appId' => '',
        'secret' => '',
<<<<<<< HEAD
        'roomId' => 1,             // Numeric value, no string (without quotes)
        'lang' => 'en',
=======
        'roomId' => 3,             // Numeric value, no string (without quotes)
        'lang' => 'es',
>>>>>>> dc6ddc8... remove keys
        'source' => 3,             // Numeric value, no string (without quotes)
        'guestName' => '',
        'guestContact' => '',
        'regionServer' => 'us',
        'server' => '<server>',    // Your HyperChat server URL (ask your contact person at Inbenta)
        'server_port' => 443,
        'surveyId' => '1'
    ],
    'triesBeforeEscalation' => 2,
    'negativeRatingsBeforeEscalation' => 0,
    'messenger' => [
        'auht_url' => '',
        'key' => '',
        'secret' => '',
        'webhook_secret' => '' //More details at: https://help.inbenta.com/en/configuring-an-external-tickets-source/
    ]
];
