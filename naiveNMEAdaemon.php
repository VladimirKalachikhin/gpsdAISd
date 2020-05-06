<?php
/* This is the simplest web daemon to broadcast NMEA sentences from the given file.
Designed for debugging applications that use gpsd.
The file set in $nmeaFileName and must content correct sentences, one per line.
Run:
$ php naiveNMEAdaemon.php
gpsd run:
$ gpsd -N -b -n tcp://192.168.10.10:2222
*/
$nmeaFileName = 'sample1.log'; 	// NMEA sentences file name
//$bindAddres = "tcp://127.0.0.1:2222"; 	// Daemon's access address
$bindAddres = "tcp://192.168.10.10:2222"; 	// Daemon's access address

$run = 1800; 		// Overall time of work, in seconds. If 0 - infinity.
$delay = 500000; 	// Min interval between sends sentences, in microseconds

$strLen = 0;
$r = array(" | "," / "," - "," \ ");
$i = 0;
$startAllTime = time();

$socket = stream_socket_server($bindAddres, $errno, $errstr);
if (!$socket) {
  return "$errstr ($errno)\n";
} 
while(!($run AND ((time()-$startAllTime)>$run))) {
	while ($conn = stream_socket_accept($socket)) {
		$handle = fopen($nmeaFileName, "r");
		if (FALSE === $handle) {
			exit("Failed to open stream ");
		}
		while (!feof($handle)) {
			$startTime = microtime(TRUE);
			
			$nmeaData = fread($handle, 8192);
			//echo "$nmeaData\n";
			$res = fwrite($conn, $nmeaData . "\n");
			if($res===FALSE) {
				fclose($handle);
				break 2;
			}
			$endTime = microtime(TRUE);
			echo($r[$i]);
			echo " " . ($endTime-$startTime) . "                    \r";
			$i++;
			if($i>=count($r)) $i = 0;
			usleep($delay);
		};
		fclose($handle);
	}
	fclose($conn);
};
fclose($socket);


?>
