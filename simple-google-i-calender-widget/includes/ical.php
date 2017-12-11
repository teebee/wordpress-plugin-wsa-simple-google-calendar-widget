<?php

class IcsParsingException extends Exception {}

/**
 * a simple ICS parser.
 *
 * note that this class does not implement all ICS functionality.
 *   bw 20171109 enkele verbeteringen voor start en end in ical.php
 * Version: 0.6.7

 */
class IcsParser {

    const TOKEN_BEGIN_VEVENT = "\nBEGIN:VEVENT";
    const TOKEN_END_VEVENT = "\nEND:VEVENT";

    public function parse($str ,  $penddate,  $pcount) {

        $curstr = $str;
        $haveVevent = true;
        $events = array();
        $now = time();
        $penddate = (isset($penddate) && $penddate > $now) ? $penddate : $now;
        $weekdays = array (
        		'MO' => 'monday',
        		'TU' => 'tuesday',
        		'WE' => 'wednesday',
        		'TH' => 'thursday',
        		'FR' => 'friday',
        		'SA' => 'saturday',
        		'SU' => 'sunday',
        );
        
        

        do {
            $startpos = strpos($curstr, self::TOKEN_BEGIN_VEVENT);
            if ($startpos !== false) {
                // remove BEGIN_VEVENT and END:VEVENT
                // +2: because \r\n at the end
                $eventStrStart = $startpos + strlen(self::TOKEN_BEGIN_VEVENT) + 2;
                $eventStr = substr($curstr, $eventStrStart);

                $endpos = strpos($eventStr, self::TOKEN_END_VEVENT) - 1;

                if ($endpos === false) {
                    throw new IcsParsingException('No valid END:VEVENT found');
                }

                $eventStr = substr($eventStr, 0, $endpos);
                $e = $this->parseVevent($eventStr);
                $events[] = $e;
// Recurring event?
		if (isset($e->rrule) && $e->rrule !== '') {
/* Recurring event, parse RRULE in associative array add appropriate duplicate events
 * only for frequencies YEARLY, MONTHLY,  WEEKLY and DAYLY
 * frequency by multiplied by INTERVAL (default INTERVAL = 1)
 * in a period starting after today() and after the first instance of the event, ending
 * not after the last day of the displayed period, and not after the last instance defined by UNTIL or COUNT*Frequency*INTERVAL
 * BY: only parse BYDAY, BYMONTH, BYMONTHDAY, possibly with multiple instances (eg BYDAY=TU,WE or BYMONTHDAY=1,2,3,4,5)
 * not parsed: BYYEARDAY, BYSETPOS, BYHOUR, BYMINUTE, WKST
 * examples:
 * FREQ=MONTHLY;UNTIL=20201108T225959Z;BYMONTHDAY=8 Every 8th of the month until 20201108
 * FREQ=MONTHLY;UNTIL=20201010T215959Z;BYDAY=2SA Monthly  2nde saturday until 20201010.
 * FREQ=MONTHLY;BYMONTHDAY=5 Monthly the 5th
 * FREQ=WEEKLY;INTERVAL=3;BYDAY=SU,SA Every 3 weeks on sunday and saturday
 * FREQ=WEEKLY;COUNT=10;BYDAY=MO,TU,WE,TH,FR Every week 10 times on weekdays
 * FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU every year last sunday of october
 * FREQ=DAILY;COUNT=5;INTERVAL=7 Every 7 days,5 times

 */
		
			$timezone = new DateTimeZone((isset($e->tzid)&& $e->tzid !== '') ? $e->tzid : get_option('timezone_string'));
			$edtstart = new DateTime('@' . $e->start, $timezone);
			$edtstartmday = $edtstart->format('j');
			$edtstartmon = $edtstart->format('n');
			$egdstart = getdate($e->start);
			//      example 2017-11-16
			// 		$egdstart['weekday'] 'Monday' - 'Sunday' example 'Thursday'
			//		$egdstart['mon']  monthnr in year 1 - 12 example 11  (november) 
			//		$egdstart['mday'] day in the month 1 - 31 example 16
			$edtendd   = new DateTime('@' . $e->end, $timezone);
			$eduration = $edtstart->diff($edtendd);

			
			$rrules = array();
                $rruleStrings = explode(';', $e->rrule);
                foreach ($rruleStrings as $s) {
                    list($k, $v) = explode('=', $s);
                    $rrules[strtolower ($k)] = strtoupper ($v);
                }
                // Get frequency and other values when set
                $frequency = $rrules['freq'];
                $interval = (isset($rrules['interval']) && $rrules['interval'] !== '') ? $rrules['interval'] : 1;
                $freqinterval = new DateInterval('P' . $interval . substr($frequency,0,1));
                $interval3day = new DateInterval('P3D');
                $until = (isset($rrules['until'])) ? $this->parseIcsDateTime($rrules['until']) : $penddate;
                $until = ($until < $penddate) ? $until : ($penddate - 1); 
                $freqendloop = ($until > $penddate) ? $until : $penddate;
                switch ($frequency){
                	case "YEARLY"	:
                		$freqendloop = $freqendloop + (31622400 * $interval); // 366 days in sec
                		continue;
                	case "MONTHLY"	:
                		$freqendloop = $freqendloop + (2678400 * $interval); // 31 days in sec
                		continue;
                		
                	case "WEEKLY"	:
                		$freqendloop = $freqendloop + (604800 * $interval); // 7 days in sec
                		continue;
                		
                	case "DAILY"	:
                		$freqendloop = $freqendloop + (86400 * $interval); // 1 days in sec
                		continue;
                		
                }
                $count = (isset($rrules['count'])) ? $rrules['count'] : 0;
               	$bymonth = explode(',', (isset($rrules['bymonth'])) ? $rrules['bymonth'] : '');
               	$bymonthday = explode(',', (isset($rrules['bymonthday'])) ? $rrules['bymonthday'] : '');
               	$byday = explode(',', (isset($rrules['byday'])) ? $rrules['byday'] : '');
               	$i = 1;
               	$cen = 0;
               	switch ($frequency){
               		case "YEARLY"	:
               		case "MONTHLY"	:
               		case "WEEKLY"	:
               		case "DAILY"	:
                        $fmdayok = true;
               			$freqstart = clone $edtstart;
               			$newstart = clone $edtstart;
               			$newend = clone $edtstart;
               			$tzoffsetedt = $timezone->getOffset ( $edtstart);
               			while ( $freqstart->getTimestamp() <= $freqendloop
               					&& ($count == 0 || $i < $count  )            						)
           				{   // first FREQ loop on dtstart will only output new events
               				// created by a BY... clause
               				$test = '';
               				$test = $test . '<br>' . $e->rrule . ' freq:' . $frequency;
               				$fd = $freqstart->format('d');
 // TODO verwijderen niet gebruikt              				$fm = $freqstart->format('m');
               				$fY = $freqstart->format('Y');
               				$fH = $freqstart->format('H'); 
               				$fi = $freqstart->format('i');
               				$fdays = $freqstart->format('t');
               				$expand = false;
// bymonth               				
               				if (isset($rrules['bymonth'])) {
               				$bym = array();
               				foreach ($bymonth as $by){ 
               					// convert bymonth ordinals to month-numbers
               					if ($by < 0){
               						$by = 13 + $by;
               					}
 //              					$test = $test . ', ' .  $by;
               					$bym[] = $by;
               				}
               				$bym= array_unique($bym); // make unique
               				sort($bym);	// order array so that oldest items first are counted
               				} else {$bym= array('');}
               				foreach ($bym as $by) {
               					$newstart->setTimestamp($freqstart->getTimestamp()) ;
               					if (isset($rrules['bymonth'])){
              						
               						if ($frequency == 'YEARLY' ){ // expand
               							$newstart->setDate($fY , $by, 1);
               							$ndays = intval($newstart->format('t'));
//               							$test = 'Y mnr:' .$by . ' $ndays:' . $ndays . ' interval:' . 'P' . $interval . substr($frequency,0,1). ' ns:' . $newstart->format('Y-m-d G:i');
               							$expand = true;
               							if (intval($fd) <= $ndays) {
               								$newstart->setDate($fY , $by, $fd);
               							} elseif (isset($rrules['bymonthday'])
               									|| isset($rrules['byday'])){
               										// no action day-of the-month is set later
               							}  else {
               								continue;
               							}
 //              							$test = $test . '<br>newstart:' . $newstart->format('Y-m-d G:i t');
               						} else
               						{ // limit
 //              							$test = 'MWD mnr:' .$by  ; //. 'ns:' . $newstart->format('Y-m-d G:i');
               							if ((!$fmdayok) ||
               									(intval($newstart->format('n')) != intval($by)))
               							{continue;}
               						}
 //              						$test = $test . '<br>einde:' . date('Y-m-d G:i', $freqendloop ) . ' pend: ' . date('Y-m-d G:i', $penddate );
               					} else { // passthrough
 //              						$test = $test . 'Geen bymonth';
               					}
// bymonthday
               					if (isset($rrules['bymonthday'])) {
               					$byn = array();
               					$ndays = intval($newstart->format('t'));
 //TODO verwijderen als ok              					$test = 'Bymonthday ndays:' . $ndays;
               					foreach ($bymonthday as $by){
               						// convert bymonthday ordinals to day-of-month-numbers
               						if ($by < 0){
               							$by = 1 + $ndays + intval($by);
               						}
               						if ($by > 0 && $by <= $ndays) {
               						$byn[] = $by;
               						}
               					}
               					$byn= array_unique($byn); // make unique 
               					sort($byn);	// order array so that oldest items first are counted
               				    } else {$byn = array('');}
               				
               					foreach ($byn as $by) {
           						if (isset($rrules['bymonthday'])){
//TODO verwijderen als ok 
//									$test = $test . '<br>Bymonthday MY mday:' .$by . ' ndays:' . $ndays ; //. 'ns:' . $newstart->format('Y-m-d G:i');
           							if (in_array($frequency , array('MONTHLY', 'YEARLY')) ){ // expand
           								$expand = true;
           								$newstart->setDate($newstart->format('Y'), $newstart->format('m'), $by);
           							} else 
           							{ // limit
//TODO verwijderen als ok 
//           								$test = $test . '<br>WD mday:' .$by . ' ndays:' . $ndays ; //. 'ns:' . $newstart->format('Y-m-d G:i');
           								if ((!$fmdayok) ||
           										(intval($newstart->format('j')) !== intval($by)))
           									{continue;}
           							}
           						} else { // passthrough
//TODO verwijderen als ok 
//         							 $test = $test . '<br>Geen bymonthday';
           						}
// byday           						
           						$bydays = array();
           						if (isset($rrules['byday'])){
           						if (in_array($frequency , array('WEEKLY','MONTHLY', 'YEARLY'))
           									&& (! isset($rrules['bymonthday']))
           									&& (! isset($rrules['byyearday']))) { // expand
           						$expand =true;
           						foreach ($byday as $by) {
           							// expand byday codes to bydays datetimes
//TODO verwijderen als ok
           							$test = $test . '<br>by:' . $by . ' freq:' . $frequency;
           							$byd = $weekdays[substr($by,-2)]; 
           							if (!($byd > 'a')) continue; // if $by contains only number (not good ical)
//TODO verwijderen als ok
           							$test = $test . ' byd:' . $byd ;
           							
           							$byi = intval($by);
           							$wdf = clone $newstart;
           							if ($frequency == 'MONTHLY'	|| $frequency == 'YEARLY' ){
           								$wdl = clone $newstart;
           								if ($frequency == 'YEARLY' && (!isset($rrules['bymonth']))){
           									$wdf->setDate($fY , 1, $fd);
           									$wdl->setDate($fY , 12, $fd);
           								}
           								$wdf->modify('first ' . $byd . ' of');
           								$wdl->modify('last ' . $byd . ' of');
           								$wdf->setTime($fH, $fi);
           								$wdl->setTime($fH, $fi);
//TODO verwijderen indien ok          								$test = 'MY $byd' . $byd . ' $wdf:' .$wdf->format('Ymd'). ' $wdl:' . $wdl->format('Ymd');
           								 
           								if ($byi > 0) {
           									$wdf->add(new DateInterval('P' . ($byi - 1) . 'W'));
           									$bydays[] = $wdf->getTimestamp();
           								} elseif ($byi < 0) {
           									$wdl->sub(new DateInterval('P' . (- $byi - 1) . 'W'));
           									$bydays[] = $wdl->getTimestamp();
           									
           								}
           								else {
           									while ($wdf <= $wdl) {
           										$bydays[] = $wdf->getTimestamp();
           										$wdf->add(new DateInterval('P1W'));
           									}
           								}
           							} // Yearly or Monthly
           							else  { // $frequency == 'WEEKLY' byi is not allowed so we dont parse it
           								// TODO test byday weekly
           								$wdnrn = $newstart->format('N'); // Mo 1; Su 7
           								$wdnrb = array_search($byd,array_values($weekdays)) + 1;  // numeric index in weekdays
           								$test = $test . '<br>W wdnrn:' . $wdnrn . ' wdnrb:' . $wdnrb . ' byd:' . $byd; 
           								if ($wdnrb > $wdnrn) {
           									$wdf->add (new DateInterval('P' . ($wdnrb - $wdnrn ) . 'D'));
           								} 
           								if ($wdnrb < $wdnrn) {
           									$wdf->sub (new DateInterval('P' . ($wdnrn - $wdnrb) . 'D'));
           									
           								}
           								$bydays[] = $wdf->getTimestamp();
           								
           							} // Weekly
           							
           						} // foreach
           						} // expand
           						else { // limit frequency period smaller than Week//
           							// intval (byi) is not allowed so we dont parse it
           							$test = $test . '<br>Limit WD f:' . $frequency . '  nsday:' . strtoupper(substr($newstart->format('D'),0,2 )); 
           							foreach ($byday as $by) {
           								$test = $test . ' by:' . $by;
           							}
           							if ($byday == array('') 
           								|| in_array(strtoupper(substr($newstart->format('D'),0,2 )), $byday)
           							){ // only one time in this loop no change of $newstart
           								$bydays =  array('');
           							} else {
           								$bydays =  array('');
           								
           							//	continue;
           							}
           						} // limit
           						} // isset byday
           						else {$bydays = array('');
           						}
           						$bydays= array_unique($bydays); // make unique 
           						sort($bydays);	// order array so that oldest items first are counted
           						foreach ($bydays as $by) {
           							if (intval($by) > 0 ) {
           								$newstart->setTimestamp($by) ;
           							}

           							if ( 
           								($fmdayok  || $expand
           								|| $newstart->format('Ymd') != $edtstart->format('Ymd'))
           							&& ($count == 0 || $i < $count)
           							&& $newstart->getTimestamp() < $until
           							&& $newstart> $edtstart) { // count events after dtstart
           							if ($newstart->getTimestamp() >= $now
           									) { // copy only events after now
           								$cen++;
           								// process daylight saving time
           								$tzadd = $tzoffsetedt - $timezone->getOffset ( $newstart);
           								if ($tzadd != 0) {
           									$tziv = new DateInterval('PT' . abs($tzadd) . 'S');
           									if ($tzadd < 0) {
           										$tziv->invert = 1;
           									}
           									$newstart->add($tziv);
           								}
           								
           								$en =  clone $e;
           								$en->start = $newstart->getTimestamp();
           								$newend->setTimestamp($en->start) ;
           								$newend->add($eduration);
           								$en->end = $newend->getTimestamp();
           								$en->uid = $i . '_' . $e->uid;
           								$en->summary = 'nr:' . $i . ' cen:' . $cen . ' '. $e->summary;
 										if ($test > ' ') {
           									$en->summary = $en->summary . '<br>Test:' . $test;
 										}
           								$events[] = $en;
           							} // copy eevents
           							// next eventcount from $e->start	
           							$i++;
           						} // end count events
           					} // end byday	
           					} // end bymonthday
           					} // end bymonth
           					// next startdate by FREQ for loop < $until and <= $penddate
           					$freqstart->add($freqinterval);
           					if  ($fmdayok &&
           							in_array($frequency , array('MONTHLY', 'YEARLY')) &&
           							$freqstart->format('j') !== $edtstartmday){ 
           						// eg 31 jan + 1 month = 3 mar; -3 days => 28 feb 
           						$freqstart->sub($interval3day);
           						$fmdayok = false;
           					} elseif (!$fmdayok ){
           						$freqstart->add($interval3day);
           						$fmdayok = true;
           						
           					}
           				} 
                 	}
 				} // switch freq
//
                $parsedUntil = strpos($curstr, self::TOKEN_END_VEVENT) + strlen(self::TOKEN_END_VEVENT) + 1;
                $curstr = substr($curstr, $parsedUntil);
            } else {
                $haveVevent = false;
            }
        } while($haveVevent);

       usort($events, array($this, "eventSortComparer"));

        $this->events = $events;
    }

    public function getFutureEvents($penddate ) {
        // events are already sorted
        $newEvents = array();
        $now = time();

        foreach ($this->events as $e) {
            if ((($e->start >= $now) || ($e->end >= $now))
            		&& $e->start <= $penddate) {
                    $newEvents[] = $e;
                }
        }

        return $newEvents;
    }

    public function getAll() {
        return $this->events;
    }

    private function parseIcsDateTime($datetime, $tzid = '') {
        if (strlen($datetime) < 8) {
            return -1;
        }

        if (strlen($datetime) >= 13)  {
            $hms = substr($datetime, 9, 4) . '00';
        } else {
            $hms = '000000';
        }    
        
        // check if it is GMT
        $lastChar = $datetime[strlen($datetime) - 1];
         if ($lastChar == 'Z') {
        	$tzid = 'UTC';
        } else  {
        	$tzid = ($tzid > ' ') ? $tzid : get_option('timezone_string');
        }
        $date = date_create_from_format('Ymd His e', substr($datetime,0,8) . ' ' . $hms. ' ' . $tzid);
        $time = $date->getTimestamp();
        
        
        return $time;
    }

    private function eventSortComparer($a, $b) {
        if ($a->start == $b->start) {
            return 0;
        } else if($a->start > $b->start) {
            return 1;
        } else {
            return -1;
        }
    }

    public function parseVevent($eventStr) {
        $lines = explode("\n", $eventStr);
        $eventObj = new StdClass;

        foreach($lines as $l) { 

            $list = explode(":", $l);
            $token = "";
            $value = "";
            $tzid = '';
            //bw 20171108 added, because sometimes there is timezone or other info after DTSTART, or DTEND 
//     eg. DTSTART;TZID=Europe/Amsterdam, or  DTSTART;VALUE=DATE:20171203
            $tl = explode(";", $list[0]);
            $token = $tl[0];
            if (count($tl) > 1 ){
            	$dtl = explode("=", $tl[1]);
            	if (count($dtl) > 1 ){
            		if ($dtl[0] == 'TZID') {
            			$tzid = $dtl[1];
            		}
            	}
            }
            if (count($list) > 1) {
                // trim() to remove \r
                $value = trim($list[1]);
                $desc = str_replace(array('\;', '\,', '\r\n', '\n', '\r'), array(';', ',', '<br>', '<br>', '<br>'), htmlspecialchars($value));
            }
            switch($token) {
                case "SUMMARY":
                	$eventObj->summary = $desc;
                    break;
                case "DESCRIPTION":
                	$eventObj->description = $desc;
                    break;
                case "LOCATION":
                	$eventObj->location = $desc;
                    break;
                case "DTSTART":
                    $eventObj->start = $this->parseIcsDateTime($value, $tzid);
                    if ($tzid > ' ') {
                    	$eventObj->tzid = $tzid;
                    }
                    break;
                case "DTEND":
                    $eventObj->end = $this->parseIcsDateTime($value, $tzid);
                    break;
                    // bw 20171108 toegevoegd UID RRULE
               case "UID":
                    $eventObj->uid = $value;
                    break;
               case "RRULE":
               		$eventObj->rrule = $value;
               	break;
               	
            }

        }

        return $eventObj;
    }
}

$p = new IcsParser();
