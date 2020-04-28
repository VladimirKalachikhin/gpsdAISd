<?php
/* gpsd - AIS daemon
This run as a server-side part of the web application.
Daemon collects AIS data from gpsd stream and saves it to file. A webapp can 
read this file asynchronously against the gpsd stream.

gpsdAIS daemon checks whether the instance is already running, and exit if it.
Remove data file stops gpsdAIS daemon.
gpsdAIS daemon checks atime of the data file, if possible. If there are no accesses to this file
daemon exit. If no atime available, daemon exit by timeout.
*/

$minLoopTime = 300000; 	// microseconds, the time of one survey gpsd cycle is not less than; цикл не должен быть быстрее, иначе он займёт весь процессор
$noDeviceTimeout = 60; 	// seconds, time of continuous absence of the desired device, when reached - exit
$noVehicleTimeout = 600; 	// seconds, time of continuous absence of the vessel in AIS, when reached - is deleted from the data. "when a ship is moored or at anchor, the position message is only broadcast every 180 seconds;"
$runTimeOut = 3600; 	// seconds, time activity of daemon after the start. After expiration - exit
$noAccessTimeOut = 3600; 	// seconds, timeout of access to the data file. If expired - exit. If no atime available - not works.

$SEEN_GPS = 0x01; $SEEN_AIS = 0x08;
$msg=''; 	// message on exit
$aisData=array(); 	// AIS data collection

//$dataType=$GLOBALS['SEEN_GPS']|$GLOBALS['SEEN_AIS']; 	// 
$dataType=$GLOBALS['SEEN_AIS']; 	// 

$options = getopt("o::h::p::");
//print_r($options); //
$aisJSONfileName = filter_var(@$options['o'],FILTER_SANITIZE_URL);
if(!$aisJSONfileName) $aisJSONfileName = 'aisJSONdata';
$dirName = pathinfo($aisJSONfileName, PATHINFO_DIRNAME);
if((!$dirName) OR ($dirName=='.')) $aisJSONfileName = sys_get_temp_dir()."/".pathinfo($aisJSONfileName,PATHINFO_BASENAME);
echo "aisJSONfileName=$aisJSONfileName;\n";

if(!($host=filter_var(@$options['h'],FILTER_VALIDATE_DOMAIN))) $host='localhost';
if(!($port=filter_var(@$options['p'],FILTER_VALIDATE_INT))) $port=2947;

echo "Begin. dataType=$dataType;\n";
// Я ли?
$pid = getmypid();
//echo "pid=$pid\n";

exec("ps w | grep '".pathinfo(__FILE__,PATHINFO_BASENAME)."'",$psList);
//print_r($psList); //
$cnt = 0;
foreach($psList as $str) {
	if(strpos($str,(string)$pid)!==FALSE) continue;
	$str = explode(' ',trim($str)); 	// массив слов
	foreach($str as $w) {
		if((strpos($w,'php')!==FALSE)AND(strpos($w,'.php')===FALSE)) { 	// выполняемая программа, php или php-cli, или /my/path/customphp
			$msg="I'm already running"; 
			echo "$msg\n"; 
			goto ENDEND;
		}
	}
}

$aisData = json_decode(file_get_contents($aisJSONfileName),TRUE); 	// но там ценная информация?
// За работу!
$startRunTime = time();
$gpsd  = stream_socket_client('tcp://'.$host.':'.$port); // открыть сокет 
//stream_set_blocking($gpsd,FALSE); 	// установим неблокирующий режим чтения. Что-то я здесь не понял...
if(!$gpsd)  {
	//unlink($aisJSONfileName); 	// считаем, что всё пропало
	$msg="no GPSD on $host:$port"; 
	echo "$msg\n"; 
	goto ENDEND;
}
echo "Socket opened\n";

$gpsdVersion = fgets($gpsd); 	// {"class":"VERSION","release":"3.15","rev":"3.15-2build1","proto_major":3,"proto_minor":11}
echo "Received VERSION \n";
//echo "$gpsdVersion \n";

fwrite($gpsd, '?WATCH={"enable":true,"json":true};'); 	// велим демону включить устройства
echo "Sending TURN ON\n";
// Первым ответом будет:
$gpsdDevices = fgets($gpsd); 	// {"class":"DEVICES","devices":[{"class":"DEVICE","path":"/tmp/ttyS21","activated":"2017-09-20T20:13:02.636Z","native":0,"bps":38400,"parity":"N","stopbits":1,"cycle":1.00}]}
echo "Received DEVICES\n"; //
$gpsdDevices = json_decode($gpsdDevices,TRUE);
//print_r($gpsdDevices); //
$devicePresent = array();
foreach($gpsdDevices["devices"] as $device) {
	if($device['flags']&$dataType) $devicePresent[] = $device['path']; 	// список требуемых среди обнаруженных и понятых устройств.
}
if(!$devicePresent) {
	//unlink($aisJSONfileName); 	// считаем, что всё пропало
	$msg='no required devices present';
	echo "$msg\n"; 
	goto ENDEND;
};
//print_r($gpsdDevices); //
//print_r($devicePresent); //
// Вторым ответом будет
$gpsdWATCH = fgets($gpsd); 	// статус WATCH
echo "Received first WATCH\n"; //
//print_r($gpsdWATCH); //
echo "\n";

unlink($aisJSONfileName); 	// 
file_put_contents($aisJSONfileName,' \n'); 	// создадим файл в знак того, что демон стартовал

$aisVehicles=array(); 
//$aisVatch = time() + $noVehicleTimeout; 	// почистим от исчезнувших судов после первого цикла и таймаута
$aisVatch = time(); 	// почистим от исчезнувших судов сразу по таймауту - вдруг данные очень старые?
do {
	if((time()-$startRunTime)>$runTimeOut) {
		//unlink($aisJSONfileName); 	// 
		$msg='Run timeout expired - exit';
		echo "\n$msg\n"; 
		break;
	};
	clearstatcache(TRUE,$aisJSONfileName);
	if(!file_exists($aisJSONfileName)) {
		$msg='Data file deleted by client - exit';
		echo "\n$msg\n"; 
		break;
	};
	$dataFileAtime = fileatime($aisJSONfileName);
	if(($dataFileAtime>$startRunTime)AND((time()-$dataFileAtime)>$noAccessTimeOut)) {
		$msg='No access to data file timeout - exit';
		echo "\n$msg\n"; 
		break;
	}
	else $startRunTime = time(); 	// меня слушают!
	//echo "From devices:\n";
	$gpsdData = fgets($gpsd); 	// ждём информации из сокета
	if($gpsdData===FALSE) { 	// сокет умер?
		$msg='socket to gpsd unreachable - exit';
		echo "\n$msg\n"; 
		break;
	}
	if(!$gpsdData) {
		$msg='no data recieved';
		echo "\n$msg\n"; 
		goto END;
		//break;
	}
	$gpsdData = json_decode($gpsdData,TRUE);
	//echo "JSON gpsdData:\n "; print_r($gpsdData); echo "\n";
	if(!in_array($gpsdData['device'],$devicePresent)) {  	// это не то устройство, которое потребовали
		if((time() - $noDevicesTime) > $noDeviceTimeout) {
			$msg='No devices found - exit';
			echo "\n$msg\n"; 
			break;
		};
		goto END;
	}
	else $noDevicesTime = time(); 

	switch($gpsdData['class']) {
	case 'SKY':
		goto END;
	case 'TPV':
		goto END;
	case 'AIS':
		$startTime = microtime(TRUE);
		//echo "JSON gpsdData:\n"; print_r($gpsdData); echo "\n";
		$vehicle = trim($gpsdData['mmsi']);
		$aisVehicles[] = $vehicle;
		switch($gpsdData['type']) {
		case 27:
		case 18:
		case 19:
		case 1:
		case 2:
		case 3:		// http://www.e-navigation.nl/content/position-report
			$aisData[$vehicle]['status'] = $gpsdData['status']; 	// Navigational status 0 = under way using engine, 1 = at anchor, 2 = not under command, 3 = restricted maneuverability, 4 = constrained by her draught, 5 = moored, 6 = aground, 7 = engaged in fishing, 8 = under way sailing, 9 = reserved for future amendment of navigational status for ships carrying DG, HS, or MP, or IMO hazard or pollutant category C, high speed craft (HSC), 10 = reserved for future amendment of navigational status for ships carrying dangerous goods (DG), harmful substances (HS) or marine pollutants (MP), or IMO hazard or pollutant category A, wing in ground (WIG);11 = power-driven vessel towing astern (regional use), 12 = power-driven vessel pushing ahead or towing alongside (regional use); 13 = reserved for future use, 14 = AIS-SART (active), MOB-AIS, EPIRB-AIS 15 = undefined = default (also used by AIS-SART, MOB-AIS and EPIRB-AIS under test)
			$aisData[$vehicle]['status_text'] = filter_var($gpsdData['status_text'],FILTER_SANITIZE_STRING);
			$aisData[$vehicle]['turn'] = $gpsdData['turn']; 	// Rate of turn ROTAIS 0 to +126 = turning right at up to 708° per min or higher 0 to –126 = turning left at up to 708° per min or higher Values between 0 and 708° per min coded by ROTAIS = 4.733 SQRT(ROTsensor) degrees per min where  ROTsensor is the Rate of Turn as input by an external Rate of Turn Indicator (TI). ROTAIS is rounded to the nearest integer value. +127 = turning right at more than 5° per 30 s (No TI available) –127 = turning left at more than 5° per 30 s (No TI available) –128 (80 hex) indicates no turn information available (default). ROT data should not be derived from COG information.
			if($gpsdData['speed']>1022) $aisData[$vehicle]['speed'] = NULL;
			else $aisData[$vehicle]['speed'] = $gpsdData['speed']*185.2/3600; 	// SOG Speed over ground in m/sec 	(in 1/10 knot steps (0-102.2 knots) 1 023 = not available, 1 022 = 102.2 knots or higher)
			$aisData[$vehicle]['accuracy'] = $gpsdData['accuracy']; 	// Position accuracy The position accuracy (PA) flag should be determined in accordance with Table 50 1 = high (£ 10 m) 0 = low (>10 m) 0 = default
			if($gpsdData['lon']==181) $aisData[$vehicle]['lon'] = NULL;
			else $aisData[$vehicle]['lon'] = $gpsdData['lon']/(10000*60); 	// Longitude in degrees	( 1/10 000 min (±180°, East = positive (as per 2’s complement), West = negative (as per 2’s complement). 181 = (6791AC0h) = not available = default) )
			if($gpsdData['lat']==91) $aisData[$vehicle]['lat'] = NULL;
			else $aisData[$vehicle]['lat'] = $gpsdData['lat']/(10000*60); 	// Latitude in degrees (1/10 000 min (±90°, North = positive (as per 2’s complement), South = negative (as per 2’s complement). 91° (3412140h) = not available = default))
			if($gpsdData['course']==3600) $aisData[$vehicle]['course'] = NULL;
			else $aisData[$vehicle]['course'] = $gpsdData['course']/10; 	// COG Course over ground in degrees ( 1/10 = (0-3 599). 3 600 (E10h) = not available = default. 3 601-4 095 should not be used)
			if($gpsdData['heading']==511) $aisData[$vehicle]['heading'] = NULL;
			else $aisData[$vehicle]['heading'] = $gpsdData['heading']; 	// True heading Degrees (0-359) (511 indicates not available = default)
			if($gpsdData['second']>59) $aisData[$vehicle]['timestamp'] = time();
			else $aisData[$vehicle]['timestamp'] = time() - $gpsdData['second']; 	// Unis timestamp. Time stamp UTC second when the report was generated by the electronic position system (EPFS) (0-59, or 60 if time stamp is not available, which should also be the default value, or 61 if positioning system is in manual input mode, or 62 if electronic position fixing system operates in estimated (dead reckoning) mode, or 63 if the positioning system is inoperative)
			$aisData[$vehicle]['maneuver'] = $gpsdData['maneuver']; 	// Special manoeuvre indicator 0 = not available = default 1 = not engaged in special manoeuvre 2 = engaged in special manoeuvre (i.e. regional passing arrangement on Inland Waterway)
			$aisData[$vehicle]['raim'] = $gpsdData['raim']; 	// RAIM-flag Receiver autonomous integrity monitoring (RAIM) flag of electronic position fixing device; 0 = RAIM not in use = default; 1 = RAIM in use. See Table 50
			$aisData[$vehicle]['radio'] = $gpsdData['radio']; 	// Communication state
			break;
		case 5: 	// http://www.e-navigation.nl/content/ship-static-and-voyage-related-data
		case 24: 	// Vendor ID не поддерживается http://www.e-navigation.nl/content/static-data-report
			//echo "JSON gpsdData: \n"; print_r($gpsdData); echo "\n";
			if($gpsdData['imo']) $aisData[$vehicle]['imo'] = $gpsdData['imo']; 	// IMO number 0 = not available = default – Not applicable to SAR aircraft 0000000001-0000999999 not used 0001000000-0009999999 = valid IMO number; 0010000000-1073741823 = official flag state number.
			if($gpsdData['ais_version']) $aisData[$vehicle]['ais_version'] = $gpsdData['ais_version']; 	// AIS version indicator 0 = station compliant with Recommendation ITU-R M.1371-1; 1 = station compliant with Recommendation ITU-R M.1371-3 (or later); 2 = station compliant with Recommendation ITU-R M.1371-5 (or later); 3 = station compliant with future editions
			if($gpsdData['callsign']=='@@@@@@@') $aisData[$vehicle]['callsign'] = NULL;
			elseif($gpsdData['callsign']) $aisData[$vehicle]['callsign'] = $gpsdData['callsign']; 	// Call sign 7 x 6 bit ASCII characters, @@@@@@@ = not available = default. Craft associated with a parent vessel, should use “A” followed by the last 6 digits of the MMSI of the parent vessel. Examples of these craft include towed vessels, rescue boats, tenders, lifeboats and liferafts.
			if($gpsdData['shipname']=='@@@@@@@@@@@@@@@@@@@@') $aisData[$vehicle]['shipname'] = NULL;
			elseif($gpsdData['shipname']) $aisData[$vehicle]['shipname'] = filter_var($gpsdData['shipname'],FILTER_SANITIZE_STRING); 	// Maximum 20 characters 6 bit ASCII, as defined in Table 47 “@@@@@@@@@@@@@@@@@@@@” = not available = default. The Name should be as shown on the station radio license. For SAR aircraft, it should be set to “SAR AIRCRAFT NNNNNNN” where NNNNNNN equals the aircraft registration number.
			if($gpsdData['shiptype']) $aisData[$vehicle]['shiptype'] = filter_var($gpsdData['shiptype'],FILTER_SANITIZE_STRING); 	// Type of ship and cargo type 0 = not available or no ship = default 1-99 = as defined in § 3.3.2 100-199 = reserved, for regional use 200-255 = reserved, for future use Not applicable to SAR aircraft
			if($gpsdData['shiptype_text']) $aisData[$vehicle]['shiptype_text'] = filter_var($gpsdData['shiptype_text'],FILTER_SANITIZE_NUMBER_INT); 	// 
			if($gpsdData['to_bow']) $aisData[$vehicle]['to_bow'] = filter_var($gpsdData['to_bow'],FILTER_SANITIZE_NUMBER_FLOAT); 	// Reference point for reported position. Also indicates the dimension of ship (m) (see Fig. 42 and § 3.3.3) For SAR aircraft, the use of this field may be decided by the responsible administration. If used it should indicate the maximum dimensions of the craft. As default should A = B = C = D be set to “0”
			if($gpsdData['to_stern']) $aisData[$vehicle]['to_stern'] = filter_var($gpsdData['to_stern'],FILTER_SANITIZE_NUMBER_FLOAT); 	// Reference point for reported position.
			if($gpsdData['to_port']) $aisData[$vehicle]['to_port'] = filter_var($gpsdData['to_port'],FILTER_SANITIZE_NUMBER_FLOAT); 	// Reference point for reported position.
			if($gpsdData['to_starboard']) $aisData[$vehicle]['to_starboard'] = filter_var($gpsdData['to_starboard'],FILTER_SANITIZE_NUMBER_FLOAT); 	// Reference point for reported position.
			$aisData[$vehicle]['epfd'] = $gpsdData['epfd']; 	// Type of electronic position fixing device. 0 = undefined (default) 1 = GPS 2 = GLONASS 3 = combined GPS/GLONASS 4 = Loran-C 5 = Chayka 6 = integrated navigation system 7 = surveyed 8 = Galileo, 9-14 = not used 15 = internal GNSS
			$aisData[$vehicle]['epfd_text'] = $gpsdData['epfd_text']; 	// 
			$aisData[$vehicle]['eta'] = $gpsdData['eta']; 	// ETA Estimated time of arrival; MMDDHHMM UTC Bits 19-16: month; 1-12; 0 = not available = default  Bits 15-11: day; 1-31; 0 = not available = default Bits 10-6: hour; 0-23; 24 = not available = default Bits 5-0: minute; 0-59; 60 = not available = default For SAR aircraft, the use of this field may be decided by the responsible administration
			if($gpsdData['draught']) $aisData[$vehicle]['draught'] = $gpsdData['draught']/10; 	// Maximum present static draught In m ( 1/10 m, 255 = draught 25.5 m or greater, 0 = not available = default; in accordance with IMO Resolution A.851 Not applicable to SAR aircraft, should be set to 0)
			$aisData[$vehicle]['destination'] = filter_var($gpsdData['destination'],FILTER_SANITIZE_STRING); 	// Destination Maximum 20 characters using 6-bit ASCII; @@@@@@@@@@@@@@@@@@@@ = not available For SAR aircraft, the use of this field may be decided by the responsible administration
			$aisData[$vehicle]['dte'] = $gpsdData['dte']; 	// DTE Data terminal equipment (DTE) ready (0 = available, 1 = not available = default) (see § 3.3.1)
			break;
		case 6: 	// http://www.e-navigation.nl/asm  http://192.168.10.10/gpsd/AIVDM.adoc
		case 8: 	// 
			//echo "JSON gpsdData:\n"; print_r($gpsdData); echo "\n";
			$aisData[$vehicle]['dac'] = $gpsdData['dac']; 	// Designated Area Code
			$aisData[$vehicle]['fid'] = $gpsdData['fid']; 	// Functional ID
			if($gpsdData['vin']) $aisData[$vehicle]['vin'] = $gpsdData['vin']; 	// European Vessel ID
			if($gpsdData['length']) $aisData[$vehicle]['length'] = $gpsdData['length']/10; 	// Length of ship in m
			if($gpsdData['beam']) $aisData[$vehicle]['beam'] = $gpsdData['beam']/10; 	// Beam of ship in m
			if(!$aisData[$vehicle]['shiptype']) $aisData[$vehicle]['shiptype'] = $gpsdData['shiptype']; 	// Ship/combination type ERI Classification В какой из посылок тип правильный - неизвестно, поэтому будем брать только из одной
			if(!$aisData[$vehicle]['shiptype_text'])$aisData[$vehicle]['shiptype_text'] = filter_var($gpsdData['shiptype_text'],FILTER_SANITIZE_STRING); 	// 
			$aisData[$vehicle]['hazard'] = $gpsdData['hazard']; 	// Hazardous cargo | 0 | 0 blue cones/lights | 1 | 1 blue cone/light | 2 | 2 blue cones/lights | 3 | 3 blue cones/lights | 4 | 4 B-Flag | 5 | Unknown (default)
			$aisData[$vehicle]['hazard_text'] = filter_var($gpsdData['hazard_text'],FILTER_SANITIZE_STRING); 	// 
			if(!$aisData[$vehicle]['draught']) $aisData[$vehicle]['draught'] = $gpsdData['draught']/100; 	// Draught in m ( 1-200 * 0.01m, default 0)
			$aisData[$vehicle]['loaded'] = $gpsdData['loaded']; 	// Loaded/Unloaded | 0 | N/A (default) | 1 | Unloaded | 2 | Loaded
			$aisData[$vehicle]['loaded_text'] = filter_var($gpsdData['loaded_text'],FILTER_SANITIZE_STRING); 	// 
			$aisData[$vehicle]['speed_q'] = $gpsdData['speed_q']; 	// Speed inf. quality 0 = low/GNSS (default) 1 = high
			$aisData[$vehicle]['course_q'] = $gpsdData['course_q']; 	// Course inf. quality 0 = low/GNSS (default) 1 = high
			$aisData[$vehicle]['heading_q'] = $gpsdData['heading_q']; 	// Heading inf. quality 0 = low/GNSS (default) 1 = high
			break;
		}
		$endTime = microtime(TRUE);
	}
	//echo "JSON aisData:\n"; print_r($aisData); echo "\n";
	//echo "\nlon={$aisData[$vehicle]['lon']};lat={$aisData[$vehicle]['lat']};\n";
	//if($gpsdData['speed']) echo "\nSpeed in knots=".($gpsdData['speed']/10)."; in m/sec={$aisData[$vehicle]['speed']};\n";
	//if($aisData[$vehicle]['speed']) echo "\nSpeed in knots=".($gpsdData['speed']/10)."; in m/sec={$aisData[$vehicle]['speed']};\n";
	//if($gpsdData['course']>3590) echo "\nCourse in deg/10=".($gpsdData['course'])."; in deg={$aisData[$vehicle]['course']};\n";
	//if($gpsdData['to_bow']) echo "\nmmsi=$vehicle; to_bow=".$aisData[$vehicle]['to_bow']."; to_stern=".$aisData[$vehicle]['to_stern']."; to_port=".$aisData[$vehicle]['to_port']."; to_starboard=".$aisData[$vehicle]['to_starboard'].";\n";
	//if($gpsdData['mmsi']=='215441000') echo "\nlon={$aisData[$vehicle]['lon']};lat={$aisData[$vehicle]['lat']}\n to_bow=".$aisData[$vehicle]['to_bow']."; to_stern=".$aisData[$vehicle]['to_stern']."; to_port=".$aisData[$vehicle]['to_port']."; to_starboard=".$aisData[$vehicle]['to_starboard'].";\n";
	/*
	if($gpsdData['mmsi']=='235084466') {
		echo "JSON gpsdData:\n"; print_r($aisData[$vehicle]);
		echo "Before sanitize: to_bow=".$gpsdData['to_bow']."; to_stern=".$gpsdData['to_stern']."; to_port=".$gpsdData['to_port']."; to_starboard=".$gpsdData['to_starboard'].";\n";
		echo "\n";
	};
	*/
	END:
	file_put_contents($aisJSONfileName,json_encode($aisData));
	
	if((time()-$aisVatch)>=$noVehicleTimeout) { 	// checking visible of ships periodically
		echo "\nRefresh the list after ".(time()-$aisVatch)." sec.\n";
		$bf = count($aisData);
		$aisData = array_filter($aisData,'chkVe',ARRAY_FILTER_USE_KEY);
		$aisVehicles=array();
		$aisVatch = time();
		if($bf<>count($aisData)) echo "The number of vessels has changed: it was $bf, became ".count($aisData)." \n";
	}
	
	$memUsage = memory_get_usage();
	$vehicles = @count($aisData);
	$value = strlen(json_encode($aisData));
	$loopTime = round($endTime - $startTime,6);
	echo getmypid()." time=$loopTime mKs; vehicles=$vehicles; value=$value B; memUsage=$memUsage B  \r";
	$sleepTime = $minLoopTime - $loopTime;
	//echo "sleepTime=$sleepTime;\n";
	if($sleepTime > 0) usleep($sleepTime);
} while(1);
ENDEND:
@fwrite($gpsd, '?WATCH={"enable":false};'); 	// велим демону выключить устройства
echo "\nSending TURN OFF\n";
//unlink($aisJSONfileName); 	// там ценная информация?
//echo "Data file removed\n";
return $msg;

function chkVe($vhID) {
global $aisVehicles;
return in_array($vhID,$aisVehicles);
}
?>
