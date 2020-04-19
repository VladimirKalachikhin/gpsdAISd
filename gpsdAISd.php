<?php
/*
366 байт на судно
*/

$minLoopTime = 300000; 	// в микросекундах, цикл не должен быть быстрее, иначе он займёт весь процессор
$noDeviceTimeout = 60; 	// в секундах, время непрерывного отсутствия нужного устройства, по достижении - выход
$noVehacleTimeout = 180; 	// в секундах, время непрерывного отсутствия судна в AIS, по достижении - удаляется из данных

$SEEN_GPS = 0x01; $SEEN_AIS = 0x08;

$host='localhost';$port=2947;$dataType=NULL;
//$dataType=$GLOBALS['SEEN_GPS']|$GLOBALS['SEEN_AIS']; 	// 
$dataType=$GLOBALS['SEEN_AIS']; 	// 

$options = getopt("o::");
print_r($options); //
$aisJSONfileName = filter_var($options['o'],FILTER_SANITIZE_URL);
if(!$aisJSONfileName) $aisJSONfileName = 'aisJSONdata';
$aisJSONfileName = sys_get_temp_dir()."/$aisJSONfileName";

echo "Begin. dataType=$dataType;<br>\n";
// Я ли?
$pid = getmypid();
exec("ps -d o pid,command | grep '{$_SERVER['PHP_SELF']}'",$psList);
//print_r($psList); //
if(count($psList)>3) return "I'm already running"; 	// последние две строки - это собственно ps grep

unlink($aisJSONfileName); 	// файл данных мог остаться

// За работу!
$gpsd  = @stream_socket_client('tcp://'.$host.':'.$port); // открыть сокет 
if(!$gpsd) return 'no GPSD';
echo "Socket opened\n";

$gpsdVersion = fgets($gpsd); 	// {"class":"VERSION","release":"3.15","rev":"3.15-2build1","proto_major":3,"proto_minor":11}
echo "Received VERSION $gpsdVersion \n";

fwrite($gpsd, '?WATCH={"enable":true,"json":true};'); 	// велим демону включить устройства
echo "Sending TURN ON\n";
// Первым ответом будет:
$gpsdDevices = fgets($gpsd); 	// {"class":"DEVICES","devices":[{"class":"DEVICE","path":"/tmp/ttyS21","activated":"2017-09-20T20:13:02.636Z","native":0,"bps":38400,"parity":"N","stopbits":1,"cycle":1.00}]}
//echo "Received DEVICES\n"; //
//print_r($gpsdDevices); //
$gpsdDevices = json_decode($gpsdDevices,TRUE);
$devicePresent = array();
foreach($gpsdDevices["devices"] as $device) {
	if($device['flags']&$dataType) $devicePresent[] = $device['path']; 	// список требуемых среди обнаруженных и понятых устройств.
}
if(!$devicePresent) return 'no required devices present';
//print_r($gpsdDevices); //
//print_r($devicePresent); //
// Вторым ответом будет
$gpsdWATCH = fgets($gpsd); 	// статус WATCH
echo "Received first WATCH\n"; //
print_r($gpsdWATCH); //
echo "\n";

$aisData=array(); 
file_put_contents($aisJSONfileName,json_encode($aisData));
$aisVehacles=array(); $aisVatch = time() + $noVehacleTimeout;
do {
	clearstatcache(TRUE,$aisJSONfileName);
	if(!file_exists($aisJSONfileName)) break;
	//echo "From devices:\n";
	$gpsdData = fgets($gpsd); 	// ждём информации из сокета
	if(!$gpsdData) break;
	$gpsdData = json_decode($gpsdData,TRUE);
	//echo "JSON gpsdData:\n "; print_r($gpsdData); echo "\n";
	if(!in_array($gpsdData['device'],$devicePresent)) {  	// это не то устройство, которое потребовали
		if((time() - $noDevicesTime) > $noDeviceTimeout) break;
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
		$vehacle = $gpsdData['mmsi'];
		$aisVehacles[] = $vehacle;
		switch($gpsdData['type']) {
		case 27:
		case 18:
		case 19:
		case 1:
		case 2:
		case 3:		// http://www.e-navigation.nl/content/position-report
			$aisData[$vehacle]['status'] = $gpsdData['status']; 	// Navigational status 0 = under way using engine, 1 = at anchor, 2 = not under command, 3 = restricted maneuverability, 4 = constrained by her draught, 5 = moored, 6 = aground, 7 = engaged in fishing, 8 = under way sailing, 9 = reserved for future amendment of navigational status for ships carrying DG, HS, or MP, or IMO hazard or pollutant category C, high speed craft (HSC), 10 = reserved for future amendment of navigational status for ships carrying dangerous goods (DG), harmful substances (HS) or marine pollutants (MP), or IMO hazard or pollutant category A, wing in ground (WIG);11 = power-driven vessel towing astern (regional use), 12 = power-driven vessel pushing ahead or towing alongside (regional use); 13 = reserved for future use, 14 = AIS-SART (active), MOB-AIS, EPIRB-AIS 15 = undefined = default (also used by AIS-SART, MOB-AIS and EPIRB-AIS under test)
			$aisData[$vehacle]['status_text'] = $gpsdData['status_text'];
			$aisData[$vehacle]['turn'] = $gpsdData['turn']; 	// Rate of turn ROTAIS 0 to +126 = turning right at up to 708° per min or higher 0 to –126 = turning left at up to 708° per min or higher Values between 0 and 708° per min coded by ROTAIS = 4.733 SQRT(ROTsensor) degrees per min where  ROTsensor is the Rate of Turn as input by an external Rate of Turn Indicator (TI). ROTAIS is rounded to the nearest integer value. +127 = turning right at more than 5° per 30 s (No TI available) –127 = turning left at more than 5° per 30 s (No TI available) –128 (80 hex) indicates no turn information available (default). ROT data should not be derived from COG information.
			$aisData[$vehacle]['speed'] = $gpsdData['speed']/(185.2*3600); 	// SOG Speed over ground in m/sec 	(in 1/10 knot steps (0-102.2 knots) 1 023 = not available, 1 022 = 102.2 knots or higher)
			$aisData[$vehacle]['accuracy'] = $gpsdData['accuracy']; 	// Position accuracy The position accuracy (PA) flag should be determined in accordance with Table 50 1 = high (£ 10 m) 0 = low (>10 m) 0 = default
			$aisData[$vehacle]['lon'] = ($gpsdData['lon']/10000)*60; 	// Longitude in degrees	( 1/10 000 min (±180°, East = positive (as per 2’s complement), West = negative (as per 2’s complement). 181 = (6791AC0h) = not available = default) )
			$aisData[$vehacle]['lat'] = ($gpsdData['lat']/10000)*60; 	// Latitude in degrees (1/10 000 min (±90°, North = positive (as per 2’s complement), South = negative (as per 2’s complement). 91° (3412140h) = not available = default))
			$aisData[$vehacle]['course'] = $gpsdData['course']/10; 	// COG Course over ground in degrees ( 1/10 = (0-3 599). 3 600 (E10h) = not available = default. 3 601-4 095 should not be used)
			$aisData[$vehacle]['heading'] = $gpsdData['heading']; 	// True heading Degrees (0-359) (511 indicates not available = default)
			if($gpsdData['second']>59) $aisData[$vehacle]['second'] = NULL;
			else $aisData[$vehacle]['second'] = $gpsdData['second']; 	// Time stamp UTC second when the report was generated by the electronic position system (EPFS) (0-59, or 60 if time stamp is not available, which should also be the default value, or 61 if positioning system is in manual input mode, or 62 if electronic position fixing system operates in estimated (dead reckoning) mode, or 63 if the positioning system is inoperative)
			$aisData[$vehacle]['maneuver'] = $gpsdData['maneuver']; 	// Special manoeuvre indicator 0 = not available = default 1 = not engaged in special manoeuvre 2 = engaged in special manoeuvre (i.e. regional passing arrangement on Inland Waterway)
			$aisData[$vehacle]['raim'] = $gpsdData['raim']; 	// RAIM-flag Receiver autonomous integrity monitoring (RAIM) flag of electronic position fixing device; 0 = RAIM not in use = default; 1 = RAIM in use. See Table 50
			$aisData[$vehacle]['radio'] = $gpsdData['radio']; 	// Communication state
			break;
		case 5: 	// http://www.e-navigation.nl/content/ship-static-and-voyage-related-data
		case 24: 	// Vendor ID не поддерживается http://www.e-navigation.nl/content/static-data-report
			//echo "JSON gpsdData: \n"; print_r($gpsdData); echo "\n";
			$aisData[$vehacle]['imo'] = $gpsdData['imo']; 	// IMO number 0 = not available = default – Not applicable to SAR aircraft 0000000001-0000999999 not used 0001000000-0009999999 = valid IMO number; 0010000000-1073741823 = official flag state number.
			$aisData[$vehacle]['ais_version'] = $gpsdData['ais_version']; 	// AIS version indicator 0 = station compliant with Recommendation ITU-R M.1371-1; 1 = station compliant with Recommendation ITU-R M.1371-3 (or later); 2 = station compliant with Recommendation ITU-R M.1371-5 (or later); 3 = station compliant with future editions
			$aisData[$vehacle]['callsign'] = $gpsdData['callsign']; 	// Call sign 7 x 6 bit ASCII characters, @@@@@@@ = not available = default. Craft associated with a parent vessel, should use “A” followed by the last 6 digits of the MMSI of the parent vessel. Examples of these craft include towed vessels, rescue boats, tenders, lifeboats and liferafts.
			$aisData[$vehacle]['shipname'] = $gpsdData['shipname']; 	// Maximum 20 characters 6 bit ASCII, as defined in Table 47 “@@@@@@@@@@@@@@@@@@@@” = not available = default. The Name should be as shown on the station radio license. For SAR aircraft, it should be set to “SAR AIRCRAFT NNNNNNN” where NNNNNNN equals the aircraft registration number.
			$aisData[$vehacle]['shiptype'] = $gpsdData['shiptype']; 	// Type of ship and cargo type 0 = not available or no ship = default 1-99 = as defined in § 3.3.2 100-199 = reserved, for regional use 200-255 = reserved, for future use Not applicable to SAR aircraft
			$aisData[$vehacle]['shiptype_text'] = $gpsdData['shiptype_text']; 	// 
			$aisData[$vehacle]['to_bow'] = $gpsdData['to_bow']; 	// Reference point for reported position. Also indicates the dimension of ship (m) (see Fig. 42 and § 3.3.3) For SAR aircraft, the use of this field may be decided by the responsible administration. If used it should indicate the maximum dimensions of the craft. As default should A = B = C = D be set to “0”
			$aisData[$vehacle]['to_stern'] = $gpsdData['to_stern']; 	// Reference point for reported position.
			$aisData[$vehacle]['to_port'] = $gpsdData['to_port']; 	// Reference point for reported position.
			$aisData[$vehacle]['to_starboard'] = $gpsdData['to_starboard']; 	// Reference point for reported position.
			$aisData[$vehacle]['epfd'] = $gpsdData['epfd']; 	// Type of electronic position fixing device. 0 = undefined (default) 1 = GPS 2 = GLONASS 3 = combined GPS/GLONASS 4 = Loran-C 5 = Chayka 6 = integrated navigation system 7 = surveyed 8 = Galileo, 9-14 = not used 15 = internal GNSS
			$aisData[$vehacle]['epfd_text'] = $gpsdData['epfd_text']; 	// 
			$aisData[$vehacle]['eta'] = $gpsdData['eta']; 	// ETA Estimated time of arrival; MMDDHHMM UTC Bits 19-16: month; 1-12; 0 = not available = default  Bits 15-11: day; 1-31; 0 = not available = default Bits 10-6: hour; 0-23; 24 = not available = default Bits 5-0: minute; 0-59; 60 = not available = default For SAR aircraft, the use of this field may be decided by the responsible administration
			$aisData[$vehacle]['draught'] = $gpsdData['draught']/10; 	// Maximum present static draught In m ( 1/10 m, 255 = draught 25.5 m or greater, 0 = not available = default; in accordance with IMO Resolution A.851 Not applicable to SAR aircraft, should be set to 0)
			$aisData[$vehacle]['destination'] = $gpsdData['destination']; 	// Destination Maximum 20 characters using 6-bit ASCII; @@@@@@@@@@@@@@@@@@@@ = not available For SAR aircraft, the use of this field may be decided by the responsible administration
			$aisData[$vehacle]['dte'] = $gpsdData['dte']; 	// DTE Data terminal equipment (DTE) ready (0 = available, 1 = not available = default) (see § 3.3.1)
			break;
		case 6: 	// http://www.e-navigation.nl/asm  http://192.168.10.10/gpsd/AIVDM.adoc
		case 8: 	// 
			//echo "JSON gpsdData:\n"; print_r($gpsdData); echo "\n";
			$aisData[$vehacle]['dac'] = $gpsdData['dac']; 	// Designated Area Code
			$aisData[$vehacle]['fid'] = $gpsdData['fid']; 	// Functional ID
			$aisData[$vehacle]['vin'] = $gpsdData['vin']; 	// European Vessel ID
			$aisData[$vehacle]['length'] = $gpsdData['length']/10; 	// Length of ship in m
			$aisData[$vehacle]['beam'] = $gpsdData['beam']/10; 	// Beam of ship in m
			if(!$aisData[$vehacle]['shiptype']) $aisData[$vehacle]['shiptype'] = $gpsdData['shiptype']; 	// Ship/combination type ERI Classification
			if(!$aisData[$vehacle]['shiptype_text'])$aisData[$vehacle]['shiptype_text'] = $gpsdData['shiptype_text']; 	// 
			$aisData[$vehacle]['hazard'] = $gpsdData['hazard']; 	// Hazardous cargo | 0 | 0 blue cones/lights | 1 | 1 blue cone/light | 2 | 2 blue cones/lights | 3 | 3 blue cones/lights | 4 | 4 B-Flag | 5 | Unknown (default)
			$aisData[$vehacle]['hazard_text'] = $gpsdData['hazard_text']; 	// 
			if(!$aisData[$vehacle]['draught']) $aisData[$vehacle]['draught'] = $gpsdData['draught']/100; 	// Draught in m ( 1-200 * 0.01m, default 0)
			$aisData[$vehacle]['loaded'] = $gpsdData['loaded']; 	// Loaded/Unloaded | 0 | N/A (default) | 1 | Unloaded | 2 | Loaded
			$aisData[$vehacle]['loaded_text'] = $gpsdData['loaded_text']; 	// 
			$aisData[$vehacle]['speed_q'] = $gpsdData['speed_q']; 	// Speed inf. quality 0 = low/GNSS (default) 1 = high
			$aisData[$vehacle]['course_q'] = $gpsdData['course_q']; 	// Course inf. quality 0 = low/GNSS (default) 1 = high
			$aisData[$vehacle]['heading_q'] = $gpsdData['heading_q']; 	// Heading inf. quality 0 = low/GNSS (default) 1 = high
			break;
		}
		$endTime = microtime(TRUE);
	}
	//echo "JSON aisData:\n"; print_r($aisData); echo "\n";
	END:
	file_put_contents($aisJSONfileName,json_encode($aisData));
	
	if((time()-$aisVatch)>=$noVehacleTimeout) { 	// checking visible of ships periodically
		echo "\nRefresh the list after ".(time()-$aisVatch)." sec.\n";
		$bf = count($aisData);
		$aisData = array_filter($aisData,'chkVe',ARRAY_FILTER_USE_KEY);
		$aisVehacles=array();
		$aisVatch = time();
		if($bf<>count($aisData)) echo "The number of vessels has changed: it was $bf, became ".count($aisData)." \n";
	}
	
	$memUsage = memory_get_usage();
	$vehacles = count($aisData);
	$value = strlen(json_encode($aisData));
	$loopTime = $endTime - $startTime;
	echo getmypid()." time=$loopTime mKs; vehacles=$vehacles; value=$value B; memUsage=$memUsage B \r";
	$sleepTime = $minLoopTime - $loopTime;
	//echo "sleepTime=$sleepTime;\n";
	if($sleepTime > 0) usleep($sleepTime);
} while(1);

fwrite($gpsd, '?WATCH={"enable":false};'); 	// велим демону выключить устройства
echo "\nSending TURN OFF\n";
unlink($aisJSONfileName);
echo "Data file removed\n";

function chkVe($vhID) {
global $aisVehacles;
return in_array($vhID,$aisVehacles);
}
?>
