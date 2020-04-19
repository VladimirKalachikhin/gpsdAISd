# gpsdAIS daemon
**version 0.0**  

The  [gpsd](https://gpsd.io/) does not support access to AIS data via ?POLL; command.  
Reason of this [are](https://lists.nongnu.org/archive/html/gpsd-users/2020-04/msg00098.html):  
>a) no existing code.
>b) AIS does not come in data sets, it is a stream.
>c) AIS is a huge stream.

But ?POLL; is necessary for server-side of a web application. For example, for the reason that browser may be frozen on background in the mobile devices. So the server should fall asleep. POLL the easiest way to implement this.  

gpsdAIS daemon collects AIS data from gpsd stream and saves it to (temporary) file. A webapp can read this file asynchronously against the gpsd stream. The file placed on TMP dir. This directory is often located on a virtual file system, therefore frequent overwriting of the file is not a problem.
## Usage
```
$ php gpsdAISd.php [-oDataFileName]
```
-o name of data file on system TMP dir. Default `aisJSONdata`
## Control
gpsdAIS daemon checks whether the instance is already running, and exit if it.  
Remove data file stops gpsdAIS daemon.
## Output
The output data file are JSON encoded array with MMSI keys and an array of data as value. The data are key-value pair as described in gpsd/www/AIVDM.adoc and [e-Navigation Netherlands](http://www.e-navigation.nl/system-messages) site.