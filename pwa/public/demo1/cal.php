<?php

require_once('Cal.class.php');


$cal = new Cal('dev');
$s = $cal->getCalendarService();

// demo to create a simple randomized event
$cr = $cal->createTestEvent();
var_dump($cr);