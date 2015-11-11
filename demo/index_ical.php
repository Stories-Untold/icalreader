<?php

include '../vendor/autoload.php';

use Icalreader\Icalreader;

function p($data)
{
	echo '<pre />';
	print_r($data);
	exit;
}

//$ical = new Icalreader("google_ical.ics");

//$ical = new Icalreader("google_except_test.ics");

//$ical = new Icalreader("google_except_test2.ics");

$ical = new Icalreader("webcal.ics");

$events = $ical->events();

p($events);