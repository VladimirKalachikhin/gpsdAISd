<?php
/* This is the simplest web daemon to broadcast NMEA sentences from the given file.
Designed for debugging applications that use gpsd.
The file set in $nmeaFileName and must content correct sentences, one per line.
Run:
$ php naiveNMEAdaemon.php
gpsd run:
$ gpsd -N tcp://192.168.10.10:2222
*/
$nmeaFileName = 'sample1.log'; 	// NMEA sentences file name
//$bindAddres = "tcp://127.0.0.1:2222"; 	// Daemon's access address
$bindAddres = "tcp://192.168.10.10:2222"; 	// Daemon's access address

$run = 1800; 		// Overall time of work, in seconds. If 0 - infinity.
$delay = 100000; 	// Min interval between sends sentences, in microseconds. 100000 are semi-realtime for sample1.log

$strLen = 0;
$r = array(" | "," / "," - "," \ ");
$i = 0;
$startAllTime = time();

$socket = stream_socket_server($bindAddres, $errno, $errstr);
if (!$socket) {
  return "$errstr ($errno)\n";
} 
echo "Wait for first connection\n";
$conn = stream_socket_accept($socket);
echo "Connected! Go to loop\n";
while ($conn) { 	// reconnect everyloop by file
	$handle = fopen($nmeaFileName, "r");
	if (FALSE === $handle) {
		exit("Failed to open file $nmeaFileName\n");
	}
	echo "Begin $nmeaFileName with delay {$delay}ms per string, send $nStr str\n";
	$nStr = 0; 	// number of sending string
	while (!feof($handle)) {
		if(($run AND ((time()-$startAllTime)>$run))) {
			fclose($handle);
			echo "Timeout, go away                            \n";
			break 2;
		}
		$startTime = microtime(TRUE);
		$nmeaData = fgets($handle, 2048);
		//echo "$nmeaData\n";
		$res = fwrite($conn, $nmeaData . "\n");
		if($res===FALSE) {
			echo "Error write to socket. Break connection\n";
			fclose($conn);
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
	if(!$conn)	$conn = stream_socket_accept($socket);
}
fclose($conn);
fclose($socket);


?>
