<?php
/* Copy this file to api/config.php and fill it in. api/config.php is private: never commit it or share it. */
return [
    'db' => [
        'driver' => 'mysql',
        'host'   => 'localhost',
        'name'   => 'cpaneluser_babyglee',   // database name from cPanel > MySQL Databases
        'user'   => 'cpaneluser_babyglee',   // database user
        'pass'   => 'PUT-THE-DATABASE-PASSWORD-HERE',
    ],
    // One-time key you type on the first visit to /admin/ to create the admin password. Use a long random text.
    'setup_key' => 'CHANGE-THIS-TO-A-LONG-RANDOM-TEXT',
];
