<?php
/* gpsd - AIS daemon

v.1.1.1

This run as a server-side part of the web application.
Daemon collects AIS data from gpsd stream and saves it to file. A webapp can 
read this file asynchronously against the gpsd stream.

gpsdAIS daemon checks whether the instance is already running, and exit if it.
Remove data file stops gpsdAIS daemon.
gpsdAIS daemon checks atime of the data file, if possible. If there are no accesses to this file
daemon exit. If no atime available, daemon exit by timeout.

Set $minLoopTime to the max, but small enough to have time to collect all the data from gpsd, 
otherwise, the data will be displayed with an increasing delay.
So, if two instruments are connected to the gpsd and each sends data once a second,
the $minLoopTime mast be max 500000 microseconds, or less.
*/

$minLoopTime = 500000; 	// microseconds, the time of one survey gpsd cycle is not less than; цикл не должен быть быстрее, иначе он займёт весь процессор.
//$minLoopTime = 1000000; 	// microseconds, 
$runTimeOut = 20; 	// seconds, time activity of daemon after the start. After expiration - exit

$SEEN_GPS = 0x01; $SEEN_AIS = 0x08;
$dataType=$GLOBALS['SEEN_GPS']|$GLOBALS['SEEN_AIS']; 	// 
//$dataType=$GLOBALS['SEEN_AIS']; 	// 

$msg=''; 	// message on exit
$aisData=array(); 	//
$aisData['AIS']=array(); 	// AIS data collection
$aisData['devices']=array(); 	// (net) devices cache

// перечень типов данных каждого (сетевого) источника, которые требуется взять от gpsd
$dataTypes = array(  	// время в секундах после последнего обновления, после которого считается, что данные протухли. Поскольку мы спрашиваем gpsd POLL, легко не увидеть редко передаваемые данные
'altHAE' => 20, 	// Altitude, height above ellipsoid, in meters. Probably WGS84.
'altMSL' => 20, 	// MSL Altitude in meters. 
'lat' => 10,
'lon' => 10,
'track' => 10, 	// курс
'speed' => 5,	// Speed over ground, meters per second.
'errX' => 30,
'errY' => 30,
'errS' => 30,
'magtrack' => 10, 	// магнитный курс
'magvar' => 3600, 	// магнитное склонение
'depth' => 5, 	// глубина
'wanglem' => 3, 	// Wind angle magnetic in degrees.
'wangler' => 3, 	// Wind angle relative in degrees.
'wanglet' => 3, 	// Wind angle true in degrees.
'wspeedr' => 3, 	// Wind speed relative in meters per second.
'wspeedt' => 3 	// Wind speed true in meters per second.
);

$options = getopt("o::h::p::",['nvto::']);
//print_r($options); //
$aisJSONfileName = filter_var(@$options['o'],FILTER_SANITIZE_URL);
if(!$aisJSONfileName) $aisJSONfileName = 'aisJSONdata';
$dirName = pathinfo($aisJSONfileName, PATHINFO_DIRNAME);
$fileName = pathinfo($aisJSONfileName,PATHINFO_BASENAME);
if((!$dirName) OR ($dirName=='.')) {
	$dirName = sys_get_temp_dir()."/gpsdAISd"; 	// права собственно на /tmp в системе могут быть замысловатыми
	@mkdir($dirName, 0777, true); 	// 
	@chmod($dirName,0777); 	// права будут только на каталог gpsdAISd. Если он вложенный, то на предыдущие, созданные по true в mkdir, прав не будет. Тогда надо использовать umask.
	$aisJSONfileName = $dirName."/".$fileName;
}
$daemonRunningFlag = $aisJSONfileName.'Flag';
//echo "aisJSONfileName=$aisJSONfileName; daemonRunningFlag=$daemonRunningFlag;\n";

if(!($host=filter_var(@$options['h'],FILTER_VALIDATE_DOMAIN))) $host='localhost';
if(!($port=filter_var(@$options['p'],FILTER_VALIDATE_INT))) $port=2947;
if(!$noVehicleTimeout = filter_var(@$options['nvto'],FILTER_VALIDATE_INT)) $noVehicleTimeout = 600; 	// seconds, time of continuous absence of the vessel in AIS, when reached - is deleted from the data. "when a ship is moored or at anchor, the position message is only broadcast every 180 seconds;"

echo "Begin. dataType=$dataType;\n";
if(IRun()) { 	// Запущен ли ещё один я?
	$msg="I'm already running"; 
	echo "$msg\n"; 
	goto ENDEND;
}
//echo "За работу!\n";
$startRunTime = time();
clearstatcache(TRUE,$daemonRunningFlag);
file_put_contents($daemonRunningFlag,serialize($startRunTime)); 	// выставим флаг
chmod($daemonRunningFlag,0666); 	// 
$gpsd  = stream_socket_client('tcp://'.$host.':'.$port,$errno,$errstr); // открыть сокет 
//stream_set_blocking($gpsd,FALSE); 	// установим неблокирующий режим чтения. Что-то я здесь не понял...
if(!$gpsd)  {
	chkaisDataFile(); 	// почистим файл данных
	$msg="no GPSD on $host:$port"; 
	echo "$msg\n"; 
	goto ENDEND;
}
echo "Socket opened, handshaking\n";
$controlClasses = array('VERSION','DEVICES','DEVICE','WATCH');
$WATCHsend = FALSE;
do { 	// при каскадном соединении нескольких gpsd заголовков может быть много
	$buf = fgets($gpsd); 
	if($buf === FALSE) { 	// gpsd умер
		echo "\nFailed to read data from gpsd: $errstr\n";
	    socket_close($gpsd);
		break;
	}
	if (!$buf = trim($buf)) {
		continue;
	}
	$buf = json_decode($buf,TRUE);
	switch($buf['class']){
	case 'VERSION': 	// можно получить от slave gpsd посде WATCH
		if(!$WATCHsend) { 	// команды WATCH ещё не посылали
			$params = array(
				"enable"=>TRUE,
				"json"=>TRUE,
				"scaled"=>TRUE, 	// преобразование единиц в gpsd. Возможно, это поможет с углом поворота, который я не декодирую
				"split24"=>TRUE 	// объединять части длинных сообщений
			);
			$res = fwrite($gpsd, '?WATCH='.json_encode($params)."\n"); 	// велим демону включить устройства
			if($res === FALSE) { 	// gpsd умер
				echo "\nFailed to send WATCH to gpsd: $errstr\n";
				socket_close($gpsd);
				break 2; 	// облом, уходим
			}
			$WATCHsend = TRUE;
			echo "Sending TURN ON\n";
		}
		break;
	case 'DEVICES': 	// соберём подключенные устройства со всех gpsd, включая slave
		echo "Received DEVICES\n"; //
		$devicePresent = array();
		foreach($buf["devices"] as $device) {
			if($device['flags']&$dataType) $devicePresent[] = $device['path']; 	// список требуемых среди обнаруженных и понятых устройств.
		}
		break;
	case 'DEVICE': 	// здесь информация о подключенных slave gpsd, т.е., общая часть path в имени устройства. Полезно для опроса конкретного устройства, но нам не надо. 
		echo "Received about slave DEVICE\n"; //
		break;
	case 'WATCH': 	// 
		echo "Received WATCH\n"; //
		//print_r($gpsdWATCH); //
		break;
	}
	
}while(in_array($buf['class'],$controlClasses));

chkaisDataFile(); 	// почистим файл данных

if(!$gpsd) {
	$msg = "no gpsd present\n"; 
	echo "$msg\n"; 
	goto ENDEND;
}
if(!$devicePresent) {
	$msg = "no required devices present\n"; 
	echo "$msg\n"; 
	goto ENDEND;
};
$devicePresent = array_unique($devicePresent);
//print_r($devicePresent); //

echo "Handshaked, recieve data\n";
echo "\n";
$aisVehicles=array(); 	// массив mmsi передаваемых от gpsd целей
$aisVatch = time() - $noVehicleTimeout; 	// почистим от исчезнувших судов после первого цикла и таймаута
do {
	$startTime = microtime(TRUE);
	if((time()-$startRunTime)>$runTimeOut) { 	// давно работаем
		clearstatcache(TRUE,$daemonRunningFlag);
		$daemonRunning = unserialize(@file_get_contents($daemonRunningFlag)); 	// файла обычно нет
		if($daemonRunning) { 	// мы никому не нужны -- флаг не удалили
			@unlink($daemonRunningFlag); 	// 
			$msg='No need to gpsdAISd more - exit';
			echo "\n$msg\n"; 
			break; 	// файл данных остался
		}
		$startRunTime = time(); 	// меня слушают! Освежим себя.
		file_put_contents($daemonRunningFlag,serialize($startRunTime)); 	// выставим флаг
		@chmod($daemonRunningFlag,0666); 	// 
	};
	clearstatcache(TRUE,$aisJSONfileName);
	if(!file_exists($aisJSONfileName)) {
		@unlink($daemonRunningFlag); 	// 
		$msg='Data file deleted by client - exit';
		echo "\n$msg\n"; 
		break;
	};
	//echo "From devices:\n";
	$gpsdData = fgets($gpsd); 	// ждём информации из сокета
	if($gpsdData===FALSE) { 	// сокет умер?
		$msg='socket to gpsd unreachable - exit';
		echo "\n$msg\n"; 
		break; 	// демон будет убит, файл данных остался
	}
	if(!$gpsdData) {
		$msg='no data recieved';
		echo "\n$msg\n"; 
		goto END; 	// ждём данных
		break;
	}
	$gpsdData = json_decode($gpsdData,TRUE);
	//echo "\nJSON gpsdData:\n "; print_r($gpsdData); echo "\n";
	if(!in_array($gpsdData['device'],$devicePresent)) {  	// это не то устройство, которое потребовали
		$msg='No devices found - wait';
		echo "\n$msg\n"; 
		goto END; 	// ждём нужного устройства
	}

	// Ok, мы получили требуемое
	switch($gpsdData['class']) {
	case 'SKY':
		goto END;
		//break;
	case 'TPV':
		//if($gpsdData['depth']) {echo "\n aisData device with depth\n"; print_r($gpsdData);}
		if(substr($gpsdData['device'],0,6) == 'tcp://') { 	// сетевые данные. Их обычно нельзя POLL, потому что редко посылаемые не увидятся
			$now = time();
			foreach($gpsdData as $type => $value){ 	// обновим данные
				$aisData['devices'][$gpsdData['device']]['data'][$type] = $value;
				$aisData['devices'][$gpsdData['device']]['cachedTime'][$type] = $now;
			}
			
			foreach($aisData['devices'][$gpsdData['device']]['cachedTime'] as $type => $cachedTime){ 	// поищем, не протухло ли чего
				if($dataTypes[$type] and (($now - $cachedTime) > $dataTypes[$type])) {
					$aisData['devices'][$gpsdData['device']]['data'][$type] = NULL;
				}
			}
			
			//echo "\n cached aisData device\n"; print_r($aisData['devices'][$gpsdData['device']]['data']);
			//if($gpsdData['depth']) {echo "\n cached aisData device with depth\n"; print_r($aisData['devices'][$gpsdData['device']]['data']);}
		}
		break;
	case 'AIS':
		//echo "JSON AIS Data:\n"; print_r($gpsdData); echo "\n";
		$vehicle = trim((string)$gpsdData['mmsi']);
		$aisVehicles[] = $vehicle;
		$aisData['AIS'][$vehicle]['mmsi'] = $vehicle;
		if($gpsdData['netAIS']) $aisData['AIS'][$vehicle]['netAIS'] = 1; 	// 
		//echo "\n AIS sentence type ".$gpsdData['type']."\n";
		switch($gpsdData['type']) {
		case 27:
		case 18:
		case 19:
		case 1:
		case 2:
		case 3:		// http://www.e-navigation.nl/content/position-report
			$aisData['AIS'][$vehicle]['status'] = (int)filter_var($gpsdData['status'],FILTER_SANITIZE_NUMBER_INT); 	// Navigational status 0 = under way using engine, 1 = at anchor, 2 = not under command, 3 = restricted maneuverability, 4 = constrained by her draught, 5 = moored, 6 = aground, 7 = engaged in fishing, 8 = under way sailing, 9 = reserved for future amendment of navigational status for ships carrying DG, HS, or MP, or IMO hazard or pollutant category C, high speed craft (HSC), 10 = reserved for future amendment of navigational status for ships carrying dangerous goods (DG), harmful substances (HS) or marine pollutants (MP), or IMO hazard or pollutant category A, wing in ground (WIG);11 = power-driven vessel towing astern (regional use), 12 = power-driven vessel pushing ahead or towing alongside (regional use); 13 = reserved for future use, 14 = AIS-SART (active), MOB-AIS, EPIRB-AIS 15 = undefined = default (also used by AIS-SART, MOB-AIS and EPIRB-AIS under test)
			$aisData['AIS'][$vehicle]['status_text'] = filter_var($gpsdData['status_text'],FILTER_SANITIZE_STRING);
			$aisData['AIS'][$vehicle]['accuracy'] = (int)filter_var($gpsdData['accuracy'],FILTER_SANITIZE_NUMBER_INT); 	// Position accuracy The position accuracy (PA) flag should be determined in accordance with Table 50 1 = high (£ 10 m) 0 = low (>10 m) 0 = default
			if($gpsdData['turn']){
				if($gpsdData['scaled']) { 	// данные уже приведены к человеческому виду, но скорость в УЗЛАХ!!!!
					//$aisData['AIS'][$vehicle]['turn'] = $gpsdData['turn']; 	// градусы в минуту со знаком или строка? one of the strings "fastright" or "fastleft" if it is out of the AIS encoding range; otherwise it is quadratically mapped back to the turn sensor number in degrees per minute
				}
				else {
					//$aisData['AIS'][$vehicle]['turn'] = (int)filter_var($gpsdData['turn'],FILTER_SANITIZE_NUMBER_INT); 	// тут чёта сложное...  Rate of turn ROTAIS 0 to +126 = turning right at up to 708° per min or higher 0 to –126 = turning left at up to 708° per min or higher Values between 0 and 708° per min coded by ROTAIS = 4.733 SQRT(ROTsensor) degrees per min where  ROTsensor is the Rate of Turn as input by an external Rate of Turn Indicator (TI). ROTAIS is rounded to the nearest integer value. +127 = turning right at more than 5° per 30 s (No TI available) –127 = turning left at more than 5° per 30 s (No TI available) –128 (80 hex) indicates no turn information available (default). ROT data should not be derived from COG information.
				}
			}
			if($gpsdData['type'] == 27) { 	// оказывается, там координаты в 1/10 минуты и скорость в узлах!!!
				if($gpsdData['lon'] or $gpsdData['lat']){
					if($gpsdData['scaled']) { 	// данные уже приведены к человеческому виду, но скорость в УЗЛАХ!!!!
						$aisData['AIS'][$vehicle]['lon'] = (float)$gpsdData['lon']; 	// 
						$aisData['AIS'][$vehicle]['lat'] = (float)$gpsdData['lat'];
					}
					else {
						if($gpsdData['lon']==181) $aisData['AIS'][$vehicle]['lon'] = NULL;
						else $aisData['AIS'][$vehicle]['lon'] = (float)filter_var($gpsdData['lon'],FILTER_SANITIZE_NUMBER_FLOAT)/(10*60); 	// Longitude in degrees	( 1/10 min (±180°, East = positive (as per 2’s complement), West = negative (as per 2’s complement). 181 = (6791AC0h) = not available = default) )
						if($gpsdData['lat']==91) $aisData['AIS'][$vehicle]['lat'] = NULL;
						else $aisData['AIS'][$vehicle]['lat'] = (float)filter_var($gpsdData['lat'],FILTER_SANITIZE_NUMBER_FLOAT)/(10*60); 	// Latitude in degrees (1/10 min (±90°, North = positive (as per 2’s complement), South = negative (as per 2’s complement). 91° (3412140h) = not available = default))
					}
				}
				if($gpsdData['speed']==63) $aisData['AIS'][$vehicle]['speed'] = NULL;
				else $aisData['AIS'][$vehicle]['speed'] = (float)filter_var($gpsdData['speed'],FILTER_SANITIZE_NUMBER_FLOAT)*1852/3600; 	// SOG Speed over ground in m/sec 	Knots (0-62); 63 = not available = default
				if($gpsdData['course']==511) $aisData['AIS'][$vehicle]['course'] = NULL;
				else $aisData['AIS'][$vehicle]['course'] = (float)filter_var($gpsdData['course'],FILTER_SANITIZE_NUMBER_FLOAT); 	// Путевой угол. COG Course over ground in degrees Degrees (0-359); 511 = not available = default
			}
			else {
				if($gpsdData['lon'] or $gpsdData['lat']){
					if($gpsdData['scaled']) { 	// данные уже приведены к человеческому виду, но скорость в УЗЛАХ!!!!
						$aisData['AIS'][$vehicle]['lon'] = (float)$gpsdData['lon']; 	// 
						$aisData['AIS'][$vehicle]['lat'] = (float)$gpsdData['lat'];
					}
					else {
						if($gpsdData['lon']==181) $aisData['AIS'][$vehicle]['lon'] = NULL;
						else $aisData['AIS'][$vehicle]['lon'] = (float)filter_var($gpsdData['lon'],FILTER_SANITIZE_NUMBER_FLOAT)/(10000*60); 	// Longitude in degrees	( 1/10 000 min (±180°, East = positive (as per 2’s complement), West = negative (as per 2’s complement). 181 = (6791AC0h) = not available = default) )
						if($gpsdData['lat']==91) $aisData['AIS'][$vehicle]['lat'] = NULL;
						else $aisData['AIS'][$vehicle]['lat'] = (float)filter_var($gpsdData['lat'],FILTER_SANITIZE_NUMBER_FLOAT)/(10000*60); 	// Latitude in degrees (1/10 000 min (±90°, North = positive (as per 2’s complement), South = negative (as per 2’s complement). 91° (3412140h) = not available = default))
					}
				}
				if($gpsdData['speed']){
					if($gpsdData['scaled']) { 	// данные уже приведены к человеческому виду, но скорость в УЗЛАХ!!!!
						$aisData['AIS'][$vehicle]['speed'] = ((int)$gpsdData['speed']*1852)/(60*60); 	// SOG Speed over ground in m/sec 	
					}
					else {
						if($gpsdData['speed']>1022) $aisData['AIS'][$vehicle]['speed'] = NULL;
						else $aisData['AIS'][$vehicle]['speed'] = (float)filter_var($gpsdData['speed'],FILTER_SANITIZE_NUMBER_FLOAT)*185.2/3600; 	// SOG Speed over ground in m/sec 	(in 1/10 knot steps (0-102.2 knots) 1 023 = not available, 1 022 = 102.2 knots or higher)
					}
				}
				if($gpsdData['course']==3600) $aisData['AIS'][$vehicle]['course'] = NULL;
				else $aisData['AIS'][$vehicle]['course'] = (float)filter_var($gpsdData['course'],FILTER_SANITIZE_NUMBER_FLOAT)/10; 	// Путевой угол. COG Course over ground in degrees ( 1/10 = (0-3599). 3600 (E10h) = not available = default. 3601-4095 should not be used)
			}
			if($gpsdData['heading']==511) $aisData['AIS'][$vehicle]['heading'] = NULL;
			else $aisData['AIS'][$vehicle]['heading'] = (float)filter_var($gpsdData['heading'],FILTER_SANITIZE_NUMBER_FLOAT); 	// Истинный курс. True heading Degrees (0-359) (511 indicates not available = default)
			if($gpsdData['second']>59) $aisData['AIS'][$vehicle]['timestamp'] = time();
			else $aisData['AIS'][$vehicle]['timestamp'] = time() - (int)filter_var($gpsdData['second'],FILTER_SANITIZE_NUMBER_INT); 	// Unis timestamp. Time stamp UTC second when the report was generated by the electronic position system (EPFS) (0-59, or 60 if time stamp is not available, which should also be the default value, or 61 if positioning system is in manual input mode, or 62 if electronic position fixing system operates in estimated (dead reckoning) mode, or 63 if the positioning system is inoperative)
			$aisData['AIS'][$vehicle]['maneuver'] = (int)filter_var($gpsdData['maneuver'],FILTER_SANITIZE_NUMBER_INT); 	// Special manoeuvre indicator 0 = not available = default 1 = not engaged in special manoeuvre 2 = engaged in special manoeuvre (i.e. regional passing arrangement on Inland Waterway)
			$aisData['AIS'][$vehicle]['raim'] = (int)filter_var($gpsdData['raim'],FILTER_SANITIZE_NUMBER_INT); 	// RAIM-flag Receiver autonomous integrity monitoring (RAIM) flag of electronic position fixing device; 0 = RAIM not in use = default; 1 = RAIM in use. See Table 50
			$aisData['AIS'][$vehicle]['radio'] = (string)$gpsdData['radio']; 	// Communication state
			//break; 	//comment break чтобы netAIS мог посылать информацию типа 5,24 и 6,8 в сообщени типа 1. Но gpsdAISd не имеет дела с netAIS?
		case 5: 	// http://www.e-navigation.nl/content/ship-static-and-voyage-related-data
		case 24: 	// Vendor ID не поддерживается http://www.e-navigation.nl/content/static-data-report
			//echo "JSON gpsdData: \n"; print_r($gpsdData); echo "\n";
			if($gpsdData['imo']) $aisData['AIS'][$vehicle]['imo'] = (string)$gpsdData['imo']; 	// IMO number 0 = not available = default – Not applicable to SAR aircraft 0000000001-0000999999 not used 0001000000-0009999999 = valid IMO number; 0010000000-1073741823 = official flag state number.
			if($gpsdData['ais_version']) $aisData['AIS'][$vehicle]['ais_version'] = (int)filter_var($gpsdData['ais_version'],FILTER_SANITIZE_NUMBER_INT); 	// AIS version indicator 0 = station compliant with Recommendation ITU-R M.1371-1; 1 = station compliant with Recommendation ITU-R M.1371-3 (or later); 2 = station compliant with Recommendation ITU-R M.1371-5 (or later); 3 = station compliant with future editions
			if($gpsdData['callsign']=='@@@@@@@') $aisData['AIS'][$vehicle]['callsign'] = NULL;
			elseif($gpsdData['callsign']) $aisData['AIS'][$vehicle]['callsign'] = (string)$gpsdData['callsign']; 	// Call sign 7 x 6 bit ASCII characters, @@@@@@@ = not available = default. Craft associated with a parent vessel, should use “A” followed by the last 6 digits of the MMSI of the parent vessel. Examples of these craft include towed vessels, rescue boats, tenders, lifeboats and liferafts.
			if($gpsdData['shipname']=='@@@@@@@@@@@@@@@@@@@@') $aisData['AIS'][$vehicle]['shipname'] = NULL;
			elseif($gpsdData['shipname']) $aisData['AIS'][$vehicle]['shipname'] = filter_var($gpsdData['shipname'],FILTER_SANITIZE_STRING); 	// Maximum 20 characters 6 bit ASCII, as defined in Table 47 “@@@@@@@@@@@@@@@@@@@@” = not available = default. The Name should be as shown on the station radio license. For SAR aircraft, it should be set to “SAR AIRCRAFT NNNNNNN” where NNNNNNN equals the aircraft registration number.
			if($gpsdData['shiptype']) $aisData['AIS'][$vehicle]['shiptype'] = (int)filter_var($gpsdData['shiptype'],FILTER_SANITIZE_NUMBER_INT); 	// Type of ship and cargo type 0 = not available or no ship = default 1-99 = as defined in § 3.3.2 100-199 = reserved, for regional use 200-255 = reserved, for future use Not applicable to SAR aircraft
			if($gpsdData['shiptype_text']) $aisData['AIS'][$vehicle]['shiptype_text'] = filter_var($gpsdData['shiptype_text'],FILTER_SANITIZE_STRING); 	// 
			if($gpsdData['to_bow']) $aisData['AIS'][$vehicle]['to_bow'] = (float)filter_var($gpsdData['to_bow'],FILTER_SANITIZE_NUMBER_FLOAT); 	// Reference point for reported position. Also indicates the dimension of ship (m) (see Fig. 42 and § 3.3.3) For SAR aircraft, the use of this field may be decided by the responsible administration. If used it should indicate the maximum dimensions of the craft. As default should A = B = C = D be set to “0”
			if($gpsdData['to_stern']) $aisData['AIS'][$vehicle]['to_stern'] = (float)filter_var($gpsdData['to_stern'],FILTER_SANITIZE_NUMBER_FLOAT); 	// Reference point for reported position.
			if($gpsdData['to_port']) $aisData['AIS'][$vehicle]['to_port'] = (float)filter_var($gpsdData['to_port'],FILTER_SANITIZE_NUMBER_FLOAT); 	// Reference point for reported position.
			if($gpsdData['to_starboard']) $aisData['AIS'][$vehicle]['to_starboard'] = (float)filter_var($gpsdData['to_starboard'],FILTER_SANITIZE_NUMBER_FLOAT); 	// Reference point for reported position.
			$aisData['AIS'][$vehicle]['epfd'] = (int)filter_var($gpsdData['epfd'],FILTER_SANITIZE_NUMBER_INT); 	// Type of electronic position fixing device. 0 = undefined (default) 1 = GPS 2 = GLONASS 3 = combined GPS/GLONASS 4 = Loran-C 5 = Chayka 6 = integrated navigation system 7 = surveyed 8 = Galileo, 9-14 = not used 15 = internal GNSS
			$aisData['AIS'][$vehicle]['epfd_text'] = (string)$gpsdData['epfd_text']; 	// 
			$aisData['AIS'][$vehicle]['eta'] = (string)$gpsdData['eta']; 	// ETA Estimated time of arrival; MMDDHHMM UTC Bits 19-16: month; 1-12; 0 = not available = default  Bits 15-11: day; 1-31; 0 = not available = default Bits 10-6: hour; 0-23; 24 = not available = default Bits 5-0: minute; 0-59; 60 = not available = default For SAR aircraft, the use of this field may be decided by the responsible administration
			if($gpsdData['scaled']) { 	// данные уже приведены к человеческому виду, но скорость в УЗЛАХ!!!!
				if($gpsdData['draught']) $aisData['AIS'][$vehicle]['draught'] = (float)$gpsdData['draught']; 	// в метрах
			}
			else {
				if($gpsdData['draught']) $aisData['AIS'][$vehicle]['draught'] = (float)filter_var($gpsdData['draught'],FILTER_SANITIZE_NUMBER_INT)/10; 	// Maximum present static draught In m ( 1/10 m, 255 = draught 25.5 m or greater, 0 = not available = default; in accordance with IMO Resolution A.851 Not applicable to SAR aircraft, should be set to 0)
			}
			$aisData['AIS'][$vehicle]['destination'] = filter_var($gpsdData['destination'],FILTER_SANITIZE_STRING); 	// Destination Maximum 20 characters using 6-bit ASCII; @@@@@@@@@@@@@@@@@@@@ = not available For SAR aircraft, the use of this field may be decided by the responsible administration
			$aisData['AIS'][$vehicle]['dte'] = (int)filter_var($gpsdData['dte'],FILTER_SANITIZE_NUMBER_INT); 	// DTE Data terminal equipment (DTE) ready (0 = available, 1 = not available = default) (see § 3.3.1)
			//break; 	// comment break чтобы netAIS мог посылать информацию типа 5,24 и 6,8 в сообщени типа 1
		case 6: 	// http://www.e-navigation.nl/asm  http://192.168.10.10/gpsd/AIVDM.adoc
		case 8: 	// 
			//echo "JSON gpsdData:\n"; print_r($gpsdData); echo "\n";
			$aisData['AIS'][$vehicle]['dac'] = (string)$gpsdData['dac']; 	// Designated Area Code
			$aisData['AIS'][$vehicle]['fid'] = (string)$gpsdData['fid']; 	// Functional ID
			if($gpsdData['vin']) $aisData['AIS'][$vehicle]['vin'] = (string)$gpsdData['vin']; 	// European Vessel ID
			if($gpsdData['length']) $aisData['AIS'][$vehicle]['length'] = (float)filter_var($gpsdData['length'],FILTER_SANITIZE_NUMBER_INT)/10; 	// Length of ship in m
			if($gpsdData['beam']) $aisData['AIS'][$vehicle]['beam'] = (float)filter_var($gpsdData['beam'],FILTER_SANITIZE_NUMBER_INT)/10; 	// Beam of ship in m
			if(!$aisData['AIS'][$vehicle]['shiptype']) $aisData['AIS'][$vehicle]['shiptype'] = (string)$gpsdData['shiptype']; 	// Ship/combination type ERI Classification В какой из посылок тип правильный - неизвестно, поэтому будем брать только из одной
			if(!$aisData['AIS'][$vehicle]['shiptype_text'])$aisData['AIS'][$vehicle]['shiptype_text'] = filter_var($gpsdData['shiptype_text'],FILTER_SANITIZE_STRING); 	// 
			$aisData['AIS'][$vehicle]['hazard'] = (int)filter_var($gpsdData['hazard'],FILTER_SANITIZE_NUMBER_INT); 	// Hazardous cargo | 0 | 0 blue cones/lights | 1 | 1 blue cone/light | 2 | 2 blue cones/lights | 3 | 3 blue cones/lights | 4 | 4 B-Flag | 5 | Unknown (default)
			$aisData['AIS'][$vehicle]['hazard_text'] = filter_var($gpsdData['hazard_text'],FILTER_SANITIZE_STRING); 	// 
			if(!$aisData['AIS'][$vehicle]['draught']) $aisData['AIS'][$vehicle]['draught'] = (float)filter_var($gpsdData['draught'],FILTER_SANITIZE_NUMBER_INT)/100; 	// Draught in m ( 1-200 * 0.01m, default 0)
			$aisData['AIS'][$vehicle]['loaded'] = (int)filter_var($gpsdData['loaded'],FILTER_SANITIZE_NUMBER_INT); 	// Loaded/Unloaded | 0 | N/A (default) | 1 | Unloaded | 2 | Loaded
			$aisData['AIS'][$vehicle]['loaded_text'] = filter_var($gpsdData['loaded_text'],FILTER_SANITIZE_STRING); 	// 
			$aisData['AIS'][$vehicle]['speed_q'] = (int)filter_var($gpsdData['speed_q'],FILTER_SANITIZE_NUMBER_INT); 	// Speed inf. quality 0 = low/GNSS (default) 1 = high
			$aisData['AIS'][$vehicle]['course_q'] = (int)filter_var($gpsdData['course_q'],FILTER_SANITIZE_NUMBER_INT); 	// Course inf. quality 0 = low/GNSS (default) 1 = high
			$aisData['AIS'][$vehicle]['heading_q'] = (int)filter_var($gpsdData['heading_q'],FILTER_SANITIZE_NUMBER_INT); 	// Heading inf. quality 0 = low/GNSS (default) 1 = high
			break;
		}
	}
	
	clearstatcache(TRUE,$aisJSONfileName);
	//echo "\naisJSONfileName=$aisJSONfileName\n";
	$aisDataOld = json_decode(file_get_contents($aisJSONfileName),TRUE); 	// там ценная информация
	//exec("cat $aisJSONfileName",$aisDataOld);
	//$aisDataOld = implode("\n",$aisDataOld);
	//$aisDataOld = json_decode($aisDataOld,TRUE); 	// там ценная информация
	//echo "\n aisDataOld: "; print_r($aisDataOld); echo "\n";
	
	if($aisDataOld['AIS']) {
	    //echo "\n aisDataOld "; print_r($aisDataOld);
	    $aisDataPreserve = array_diff_key($aisDataOld['AIS'],$aisData['AIS']); 	// цели в файле, которых нет в текущем наборе целей
		//echo "\n aisDataPreserve "; print_r($aisDataPreserve);
	    //echo "\n aisData "; print_r($aisData);
	    $aisData['AIS'] = $aisData['AIS']+$aisDataPreserve; 	// array_merge перенумеровывает числовые ключи
	    //echo "\n aisData после + "; print_r($aisData);
	}
	
	if((time()-$aisVatch) >= $noVehicleTimeout) { 	// checking visible of ships periodically
		// Сторонних целей нет, всё. Сторонние цели, например, от netAIS, выкидываются. Их возобновление -- проблема сторонних источников.
		echo "\nRefresh the list after ".(time()-$aisVatch)." sec.\n";
		$bf = count($aisData['AIS']);
		$aisData['AIS'] = array_filter($aisData['AIS'],'chkVe',ARRAY_FILTER_USE_KEY); 	// оставим в $aisData['AIS'] только те цели, которые есть в $aisVehicles
		$aisVehicles=array(); 	// снова начнём собирать цели
		$aisVatch = time();
		if($bf<>count($aisData['AIS'])) echo "The number of vessels has changed: it was $bf, became ".count($aisData['AIS'])." \n";
	}
	
	//echo "\n aisData "; print_r($aisData);
	clearstatcache(TRUE,$aisJSONfileName);
	file_put_contents($aisJSONfileName,json_encode($aisData),LOCK_EX);
	@chmod($aisJSONfileName,0666); 	// файл мог создать кто-нибудь ещё
	clearstatcache(TRUE,$aisJSONfileName);
	
	END:
	$endTime = microtime(TRUE);
	$memUsage = memory_get_usage();
	$vehicles = @count($aisData['AIS']);
	$value = strlen(json_encode($aisData));
	$loopTime = round($endTime - $startTime,6);
	echo getmypid()." time=$loopTime mKs; vehicles=$vehicles; value=$value B; memUsage=$memUsage B  \r";
	$sleepTime = $minLoopTime - $loopTime;
	//echo "sleepTime=$sleepTime;\n";
	if($sleepTime > 0) usleep($sleepTime);
} while(1);
ENDEND:
@fwrite($gpsd, '?WATCH={"enable":false};'."\n"); 	// велим демону выключить устройства. А если там другой экземпляр? А там поконнектно.
//echo "\nSending TURN OFF\n";
@fclose($gpsd);

return $msg;



function chkVe($vhID) {
global $aisVehicles,$aisData,$noVehicleTimeout;
return ((time()-$aisData['AIS'][$vhID]['timestamp']>$noVehicleTimeout) or in_array($vhID,$aisVehicles));
//return (in_array($vhID,$aisVehicles));
}

function IRun() {
/**/
$pid = getmypid();
//echo "pid=$pid\n "."ps -A w | grep '".pathinfo(__FILE__,PATHINFO_BASENAME)."'"."\n";
//* ps -A работает слишком долго?
exec("ps -A w | grep '".pathinfo(__FILE__,PATHINFO_BASENAME)."'",$psList);
if(!$psList) exec("ps w | grep '".pathinfo(__FILE__,PATHINFO_BASENAME)."'",$psList); 	// for OpenWRT. For others -- let's hope so all run from one user
//print_r($psList); //
$run = FALSE;
foreach($psList as $str) {
	if(strpos($str,(string)$pid)!==FALSE) continue;
	$str = explode(' ',trim($str)); 	// массив слов
	foreach($str as $w) {
		if((strpos($w,'php')!==FALSE)AND(strpos($w,'.php')===FALSE)) { 	// выполняемая программа, php или php-cli, или /my/path/customphp
			$run=TRUE;
			break 2;
		}
	}
}
return $run;
}

function chkaisDataFile() {
/**/
global $aisJSONfileName,$noVehicleTimeout;

clearstatcache(TRUE,$aisJSONfileName);
if(file_exists($aisJSONfileName)) { 	// однако, файл с целями AIS есть.
	clearstatcache(TRUE,$aisJSONfileName);
	$aisData = json_decode(file_get_contents($aisJSONfileName),TRUE); 	// 
	//print_r($aisData);
	$ch = FALSE;
	foreach($aisData['AIS'] as $vehicle => &$data) { 	// почистим файл от старых целей
		if((time()-$data['timestamp'])>$noVehicleTimeout) {
			//echo time()-$data['timestamp']."\n";
			unset($aisData['AIS'][$vehicle]);
			$ch = TRUE;
		}
	}
	if($ch) {
		//print_r($aisData);
		file_put_contents($aisJSONfileName,json_encode($aisData),LOCK_EX);
		clearstatcache(TRUE,$aisJSONfileName);
	}
}
}
?>
