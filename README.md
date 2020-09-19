# gpsdAIS daemon [![License: CC BY-SA 4.0](https://img.shields.io/badge/License-CC%20BY--SA%204.0-lightgrey.svg)](https://creativecommons.org/licenses/by-sa/4.0/)
**version 0.1**  

The  [gpsd](https://gpsd.io/) does not support access to AIS data via ?POLL; command.  
Reason of this [are](https://lists.nongnu.org/archive/html/gpsd-users/2020-04/msg00098.html):  
>a) no existing code.  
>b) AIS does not come in data sets, it is a stream.  
>c) AIS is a huge stream.  

But ?POLL; is necessary for server-side of a web application. For example, for the reason that browser may be frozen on background in the mobile devices. So the server should fall asleep. POLL the easiest way to implement this.  

gpsdAIS daemon collects AIS data from gpsd stream and saves it to (temporary) file. A webapp can read this file asynchronously against the gpsd stream. The file placed on TMP dir. This directory is often located on a virtual file system, therefore frequent overwriting of the file is not a problem.  

[GaladrielMap](https://github.com/VladimirKalachikhin/Galadriel-map/tree/master)  use this daemon for display AIS information.
## Usage
```
$ php gpsdAISd.php [-oDataFileName] [-hHOST] [-pPORT]
```
-o name of data file on system TMP dir. Default `aisJSONdata`  
-h host of gpsd. Defauli `localhost`  
-p port of gpsd. Default `2947`  
## Control
gpsdAIS daemon checks whether the instance is already running, and exit if it.  
Remove data file stops gpsdAIS daemon.  
gpsdAIS daemon checks atime of the data file, if possible. If there are no accesses to this file daemon exit. If no atime available, daemon exit by timeout.  
Timeouts define in the begin of the script file, for easily adjusted it.
## Output
The output data file are JSON encoded array with MMSI keys and an array of data as value. The data are key-value pair as described in gpsd/www/AIVDM.adoc and [e-Navigation Netherlands](http://www.e-navigation.nl/system-messages) site, except:  
* The units of measurement are given in the human species
* The timestamp  is Unix timestamp