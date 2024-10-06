<?php
// public/cronjob.php

require_once __DIR__ . '/../src/CronJob.php';

$config = require __DIR__ . '/../config/config.php';

// Instantiate the CronJob class
$cronJob = new CronJob($config);

// Run the cron job logic
$cronJob->run();

