<?php

/**
 * 解析Ical类型文件
 * User: david
 * Date: 15/11/5
 * Time: 15:24
 */

namespace Icalreader;

use Recurr\Rule;
use Recurr\Transformer\ArrayTransformer;

class Icalreader
{

    /* How many ToDos are in this ical? */
    public $todo_count = 0;

    /* How many events are in this ical? */
    public $event_count = 0;

    /* How many freebusy are in this ical? */
    public $freebusy_count = 0;

    /* The parsed calendar */
    public $cal;

    /* Which keyword has been added to cal at last? */
    private $last_keyword;

    /* The value in years to use for indefinite, recurring events */
    public $default_span = 2;

    private $fields = array(
        'SUMMARY' => 'title',
        'LOCATION' => 'location',
        'LAST-MODIFIED' => 'updated_time',
        'DESCRIPTION' => 'description',
        'DTSTART' => 'start',
        'DTEND' => 'end',
        'RRULE' => 'rules',
        'RECURRENCE-ID' => 'except',
        'UID' => 'id'
    );

    private $events_ids = array();

    /**
     * 读取一个ical类型文件
     * @param $filename
     */
    public function __construct($filename)
    {
        if (!$filename) {
            return false;
        }

        if (is_array($filename)) {
            $lines = $filename;
        } else {
            $lines = file($filename, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        }

        return $this->initLines($lines);
    }

    /**
     * 解析ical
     * @param $lines
     * @return bool
     */
    public function initLines($lines)
    {
        if (stristr($lines[0], 'BEGIN:VCALENDAR') === false) {
            return false;
        } else {
            foreach ($lines as $line) {
                $line = trim($line);
                $add = $this->parseLine($line);
                if ($add === false) {
                    $this->addCalendarComponentWithKeyAndValue($type, false, $line);
                    continue;
                }

                list($keyword, $value) = $add;

                switch ($line) {
                    case 'BEGIN:VTODO':
                        $this->todo_count++;
                        $type = 'VTODO';
                        break;
                    case 'BEGIN:VEVENT':
                        $this->event_count++;
                        $type = 'VEVENT';
                        break;
                    case 'BEGIN:VFREEBUSY':
                        $this->freebusy_count++;
                        $type = 'VFREEBUSY';
                        break;
                    case 'BEGIN:VCALENDAR':
                    case 'BEGIN:DAYLIGHT':
                    case 'BEGIN:VTIMEZONE':
                    case 'BEGIN:STANDARD':
                    case 'BEGIN:VALARM':
                        $type = $value;
                        break;
                    case 'END:VALARM':
                    case 'END:VTODO':
                    case 'END:VEVENT':
                    case 'END:VFREEBUSY':
                    case 'END:VCALENDAR':
                    case 'END:DAYLIGHT':
                    case 'END:VTIMEZONE':
                    case 'END:STANDARD':
                        $type = 'VCALENDAR';
                        break;
                    default:
                        $this->addCalendarComponentWithKeyAndValue($type, $keyword, $value);
                        break;
                }
            }
            $this->process_recurrences();
            return $this->cal;
        }
    }

    /**
     * 解析Event
     * @param $component
     * @param $keyword
     * @param $value
     */
    public function addCalendarComponentWithKeyAndValue($component, $keyword, $value)
    {
        if (strstr($keyword, ';')) {
            // Ignore everything in keyword after a ; (things like Language, etc)
            $keyword = substr($keyword, 0, strpos($keyword, ';'));
        }
        if ($keyword == false) {
            $keyword = $this->last_keyword;
            switch ($component) {
                case 'VEVENT':
                    $value = $this->cal[$component][$this->event_count - 1][$keyword] . $value;
                    break;
                case 'VTODO':
                    $value = $this->cal[$component][$this->todo_count - 1][$keyword] . $value;
                    break;
                case 'VFREEBUSY':
                    $value = $this->cal[$component][$this->freebusy_count - 1][$keyword] . $value;
                    break;
            }
        }

        if (stristr($keyword, 'DTSTART') or stristr($keyword, 'DTEND') or stristr($keyword, 'EXDATE')) {
            $keyword = explode(';', $keyword);
            $keyword = $keyword[0];
        }

        switch ($component) {
            case 'VTODO':
                $this->cal[$component][$this->todo_count - 1][$keyword] = $value;
                break;
            case 'VEVENT':
                $this->cal[$component][$this->event_count - 1][$keyword] = $value;
                if (!isset($this->cal[$component][$this->event_count - 1][$keyword . '_array'])) {
                    $this->cal[$component][$this->event_count - 1][$keyword . '_array'] = array();
                }
                $this->cal[$component][$this->event_count - 1][$keyword . '_array'][] = $value;
                break;
            case 'VFREEBUSY':
                $this->cal[$component][$this->freebusy_count - 1][$keyword] = $value;
                break;
            default:
                $this->cal[$component][$keyword] = $value;
                break;
        }
        $this->last_keyword = $keyword;
    }

    /**
     * 解析每一行,返回键值对
     * @param $text
     * @return array|bool
     */
    public function parseLine($text)
    {
        preg_match('/([A-Z-;=^:]+)[:]([\w\W]*)/', $text, $matches);
        if (count($matches) == 0) {
            return false;
        }
        $matches = array_splice($matches, 1, 2);
        return $matches;
    }

    /**
     * 处理解析出来的events
     * @return bool
     */
    public function process_recurrences()
    {
        $array = $this->cal;
        $events = $array['VEVENT'];
        if (empty($events)) return false;
        $format_events = array();
        //                p($events);
        foreach ($events as $key => $event) {
            if (!isset($format_events[$key]['id'])) {
                $format_events[$key]['id'] = $this->get_unique_id();
                //            }
                foreach ($event as $k => $v) {
                    if (array_key_exists($k, $this->fields)) {
                        $filed_name = $this->fields[$k];
                        $id = $event['UID_array'][0];
                        if ($filed_name == 'end') {
                            $start = strtotime($format_events[$key]['start']);
                            $end = strtotime($v);
                            $format_events[$key]['times'] = array(
                                'start' => $start,
                                'end' => $end
                            );
                            $format_events[$key]['allday'] = date('G', $start) == 0 ? 1 : 0;
                        }
                        if ($filed_name == 'updated_time') {
                            $format_events[$key]['updated_time'] = strtotime($v);
                            continue;
                        }
                        if (isset($event['RRULE'])) {
                            $this->events_ids[$id] = $key;
                        }
                        if ($k == 'UID') {
                            if (isset($event['RECURRENCE-ID'])) {
                                $except = $event['RECURRENCE-ID'];
                                $pevent_key = $this->events_ids[$id];
                                $format_events[$pevent_key]['except'] = isset($format_events[$pevent_key]['except']) ? $format_events[$pevent_key]['except'] . ',' . $except : $except;
                            }
                            $format_events[$key][$filed_name] = !isset($except) ? $id : $id . '_' . $except;
                            $format_events[$key]['recurr_id'] = $id;
                            if (isset($except)) {
                                $format_events[$key]['pid'] = $id;
                            }
                            continue;
                        }
                        $format_events[$key][$filed_name] = $v;
                    }
                }
            }
        }
        p($format_events);
        $this->cal['VEVENT'] = $events;
    }

    /**
     * 返回unix timestamp
     * @param $icalDate
     * @return bool|int
     */
    public function iCalDateToUnixTimestamp($icalDate)
    {
        $icalDate = str_replace('T', '', $icalDate);
        $icalDate = str_replace('Z', '', $icalDate);

        $pattern = '/([0-9]{4})';
        $pattern .= '([0-9]{2})';
        $pattern .= '([0-9]{2})';
        $pattern .= '([0-9]{0,2})';
        $pattern .= '([0-9]{0,2})';
        $pattern .= '([0-9]{0,2})/';
        preg_match($pattern, $icalDate, $date);

        if ($date[1] <= 1970) {
            return false;
        }
        $timestamp = mktime((int)$date[4], (int)$date[5], (int)$date[6], (int)$date[2], (int)$date[3], (int)$date[1]);
        return $timestamp;
    }

    /**
     * 返回events
     * @return mixed
     */
    public function events()
    {
        $array = $this->cal;
        return $array['VEVENT'];
    }

    /**
     * 返回calendar名称
     * @return mixed
     */
    public function calendarName()
    {
        return $this->cal['VCALENDAR']['X-WR-CALNAME'];
    }

    /**
     * 返回Free/Busy Events
     * @return mixed
     */
    public function freeBusyEvents()
    {
        $array = $this->cal;
        return $array['VFREEBUSY'];
    }

    /**
     * generate unique id
     * @return int|string
     */
    private function get_unique_id()
    {
        $rand = $this->str_baseconvert(md5(uniqid(rand(), true)), 16, 32);
        return $this->str_baseconvert(md5(uniqid(rand(), true)), 16, 32) . substr($rand, 6, 15);
    }

    /**
     * base32
     * @param $str
     * @param int $frombase
     * @param int $tobase
     * @return int|string
     */
    private function str_baseconvert($str, $frombase = 10, $tobase = 36)
    {
        $str = trim($str);
        if (intval($frombase) != 10) {
            $len = strlen($str);
            $q = 0;
            for ($i = 0; $i < $len; $i++) {
                $r = base_convert($str[$i], $frombase, 10);
                $q = bcadd(bcmul($q, $frombase), $r);
            }
        } else
            $q = $str;

        if (intval($tobase) != 10) {
            $s = '';
            while (bccomp($q, '0', 0) > 0) {
                $r = intval(bcmod($q, $tobase));
                $s = base_convert($r, 10, $tobase) . $s;
                $q = bcdiv($q, $tobase, 0);
            }
        } else
            $s = $q;

        return $s;
    }
}