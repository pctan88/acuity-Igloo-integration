<?php

require_once __DIR__ . '/../src/AppointmentProcessor.php';

$config = require __DIR__ . '/../config/config.php';

// Instantiate the CronJob class
$cronJob = new AppointmentProcessor($config);

// Run the cron job logic
$cronJob->run();
