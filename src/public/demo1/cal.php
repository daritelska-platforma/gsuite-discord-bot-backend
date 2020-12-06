<?php

require_once('Cal.class.php');


$cal = new Cal();
$s = $cal->getCalendarService();

// demo to create a simple randomized event
$cr = $cal->createTestEvent();
var_dump($cr);