<?php
/* This is the simplest web daemon to broadcast NMEA sentences from the given file.
Designed for debugging applications that use gpsd.
The file set in $nmeaFileName and must content correct sentences, one per line.
Required options:
-i log file name
-b bind to proto://address:port
Run:
$ php naiveNMEAdaemon.php -isample1.log -btcp://127.0.0.1:2222
gpsd run to connect this:
$ gpsd -N -n tcp://192.168.10.10:2222
*/
$options = getopt("i::b::");
if(!($nmeaFileName = filter_var($options['i'],FILTER_SANITIZE_URL))) $nmeaFileName = 'sample1.log'; 	// NMEA sentences file name;
if(!($bindAddres=filter_var(@$options['b'],FILTER_VALIDATE_DOMAIN))) $bindAddres = "tcp://127.0.0.1:2222"; 	// Daemon's access address;
if($nmeaFileName=='sample1.log') {
	echo "Usage:\n  php naiveNMEAdaemon.php -isample1.log [-btcp://127.0.0.1:2222]\n";
	echo "now run naiveNMEAdaemon.php -i$nmeaFileName -b$bindAddres\n";
}

//$run = 1800; 		// Overall time of work, in seconds. If 0 - infinity.
$run = 0; 		// 
//$delay = 200000; 	// Min interval between sends sentences, in microseconds. 200000 are semi-realtime for sample1.log
$delay = 100000; 	// Min interval between sends sentences, in microseconds. 200000 are semi-realtime for sample1.log

$strLen = 0;
$r = array(" | "," / "," - "," \ ");
$i = 0;
$startAllTime = time();
$statCollection = array();

$socket = stream_socket_server($bindAddres, $errno, $errstr);
if (!$socket) {
  return "$errstr ($errno)\n";
} 
echo "Wait for first connection\n";
$conn = stream_socket_accept($socket);
echo "Connected! Go to loop\n";
$nStr = 0; 	// number of sending string
while ($conn) { 	// 
	$handle = fopen($nmeaFileName, "r");
	if (FALSE === $handle) {
		exit("Failed to open file $nmeaFileName\n");
	}
	if($nStr) {
		echo "Send $nStr str                         \n";
		statShow();
	}
	echo "\nBegin $nmeaFileName with delay {$delay}ms per string\n";
	echo "\n";
	$nStr = 0; 	// number of sending string
	while (!feof($handle)) {
		if(($run AND ((time()-$startAllTime)>$run))) {
			fclose($handle);
			echo "Timeout, go away                            \n";
			break 2;
		}
		$startTime = microtime(TRUE);
		$nmeaData = fgets($handle, 2048);
		statCollect($nmeaData);
		//echo "$nmeaData\n";
		$res = fwrite($conn, $nmeaData . "\n");
		if($res===FALSE) {
			echo "Error write to socket. Break connection\n";
			fclose($conn);
			echo "Try to reopen\n";
			$conn = stream_socket_accept($socket);
			if(!$conn) {
				echo "Reopen false\n";
				break;
			}
		}
		$endTime = microtime(TRUE);
		$nStr++;
		echo($r[$i]);
		echo " " . ($endTime-$startTime) . " string $nStr         \r";
		$i++;
		if($i>=count($r)) $i = 0;
		usleep($delay);
	};
	fclose($handle);
	// reset connection?
	//if(!$conn)	$conn = stream_socket_accept($socket);
	fclose($conn);
	$conn = stream_socket_accept($socket);
}
fclose($conn);
fclose($socket);

function statCollect($nmeaData) {
/**/
global $statCollection;
$nmeaData = substr(trim(explode(',',$nmeaData)[0]),-3);
if($nmeaData) @$statCollection["$nmeaData"]++;
/*
if(strpos($nmeaData,'ALM')!==FALSE) $statCollection['ALM']++;
elseif(strpos($nmeaData,'AIVDM')!==FALSE) $statCollection['AIVDM']++;
elseif(strpos($nmeaData,'AIVDO')!==FALSE) $statCollection['AIVDO']++;
elseif(strpos($nmeaData,'DBK')!==FALSE) $statCollection['DBK']++;
elseif(strpos($nmeaData,'DBS')!==FALSE) $statCollection['DBS']++;
elseif(strpos($nmeaData,'DBT')!==FALSE) $statCollection['DBT']++;
elseif(strpos($nmeaData,'DPT')!==FALSE) $statCollection['DPT']++;
elseif(strpos($nmeaData,'GGA')!==FALSE) $statCollection['GGA']++;
elseif(strpos($nmeaData,'GLL')!==FALSE) $statCollection['GLL']++;
elseif(strpos($nmeaData,'GNS')!==FALSE) $statCollection['GNS']++;
elseif(strpos($nmeaData,'GSV')!==FALSE) $statCollection['GSV']++;
elseif(strpos($nmeaData,'HDG')!==FALSE) $statCollection['HDG']++;
elseif(strpos($nmeaData,'HDM')!==FALSE) $statCollection['HDM']++;
elseif(strpos($nmeaData,'HDT')!==FALSE) $statCollection['HDT']++;
elseif(strpos($nmeaData,'MTW')!==FALSE) $statCollection['MTW']++;
elseif(strpos($nmeaData,'MWV')!==FALSE) $statCollection['MWV']++;
elseif(strpos($nmeaData,'RMA')!==FALSE) $statCollection['RMA']++;
elseif(strpos($nmeaData,'RMB')!==FALSE) $statCollection['RMB']++;
elseif(strpos($nmeaData,'RMC')!==FALSE) $statCollection['RMC']++;
elseif(strpos($nmeaData,'VHW')!==FALSE) $statCollection['VHW']++;
elseif(strpos($nmeaData,'VWR')!==FALSE) $statCollection['VWR']++;
elseif(strpos($nmeaData,'ZDA')!==FALSE) $statCollection['ZDA']++;
elseif(strpos($nmeaData,'PGRMZ')!==FALSE) $statCollection['PGRMZ']++;
elseif($nmeaData) $statCollection['other']++;
*/
} 	// end function statCollect

function statShow() {
/**/
global $statCollection;
ksort($statCollection);
foreach($statCollection as $code => $count){
	echo "$code: $count\n";
}
$statCollection = array();
} // end statShow
?>
