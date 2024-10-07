<?php
require_once __DIR__ . '/../src/TokenRenew.php';

$config = require __DIR__ . '/../config/config.php';

$cronJob = new TokenRenew();
$cronJob->run();
