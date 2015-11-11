<?php

/**
 * 解析webcal地址
 * User: david
 * Date: 15/11/10
 * Time: 16:33
 */


namespace Icalreader;

use Httpful\Request;

class Webcal
{

	private $link = '';
	private $replace_column = array(
		'SUMMARY'            => 'title',
		'RRULE'              => 'rule',
		'LAST-MODIFIED'      => 'updated_time',
		'LOCATION'           => 'location',
		'DESCRIPTION'        => 'description',
		'URL'                => 'url',
		'UID'                => 'id',
		'RECURRENCE-ID'      => 'recurrence-id',
		'DTSTART'            => 'start',
		'DTEND'              => 'end',
		'DTSTART;VALUE=DATE' => 'start',
		'DTEND;VALUE=DATE'  => 'end'
	);

	public function __construct($url)
	{
		$this->link = $url;
	}

	public function parse()
	{
		if (empty($this->link)) return false;

		$response = Request::get($this->link)->send();

		if ($response->hasErrors()) {
			return false;
		} else {
			$body      = $response->body;
			$event_key = 0;
			$events    = array();
			$ical_arr  = explode('BEGIN:', $body);
			foreach ($ical_arr as $v) {
				if (is_numeric(strpos($v, 'VEVENT'))) {
					$str   = trim(str_replace(['END:VEVENT', 'END:VCALENDAR', 'VEVENT'], '', $v));
					$array = $this->format_data(preg_split('/$\R?^/m', $str));
					foreach ($array as $key => $r) {
						list($key, $val) = explode(':', $r);
						if (array_key_exists($key, $this->replace_column)) {
							$column                      = $this->replace_column[$key];
							$events[$event_key][$column] = $val;
						}
						if ($key == 'DTSTART') $events[$event_key]['allday'] = 0;
						if ($key == 'DTSTART;VALUE=DATE') $events[$event_key]['allday'] = 1;
					}
					++$event_key;
				}
			}
			return $events;
		}
	}

	// 格式化数据
	private function format_data($data)
	{
		$tmp_str   = '';
		$unset_key = array();
		$pre_key   = 0;
		foreach ($data as $key => $val) {
			if ($val[0] == ' ') {
				array_push($unset_key, $key);
				if ($pre_key == 0) $pre_key = $key - 1;
				$tmp_str = $tmp_str == '' ? $data[$key - 1] . trim($val) : $tmp_str . trim($val);
				continue;
			}
			if ($tmp_str != '') {
				$data[$pre_key] = $tmp_str;
				$tmp_str        = '';
				$pre_key        = 0;
			}
		}
		if (!empty($unset_key)) {
			foreach ($unset_key as $val) {
				unset($data[$val]);
			}
			$data = array_values($data);
		}
		return $data;
	}
}