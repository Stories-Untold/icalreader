<?php

include '../vendor/autoload.php';

use Httpful\Request;
use Icalreader\Webcal;

function p($data)
{
	echo '<pre />';
	print_r($data);
	exit;
}

// webcal://windsoraaazone.net/webcal.ashx?IDs=1042

$webcal = new Webcal('http://windsoraaazone.net/webcal.ashx?IDs=1042');

$events = $webcal->parse();

foreach ($events as $key => $val) {
	$events[$key]['times'][0]['start'] = strtotime($val['start']);
	$events[$key]['times'][0]['end']   = strtotime($val['end']);
	unset($events[$key]['start']);
	unset($events[$key]['end']);
}
p($events);