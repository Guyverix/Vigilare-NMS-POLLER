<?php
/*
  REMOTE API VARIBLES
*/
$apiUrl ='https://FQDN of remote API server';         // for responding with things like images
$apiHost=$apiUrl;                                     // Poller default and sometimes independent of $apiUrl
$apiPort='8002';                                      // Used to craft URLs correctly when non-standart ports in play
$apiKey ='UUID';                                      // Create with uuidgen :) lives in settings.php as array.  This is for daemons or custom script auth

/*
  LOCAL DAEMON VARIABLES
*/
$pollerName='LOCAL FQDN';                             // Define here, so remote collectors have one spot only to change (unsupported in V1)


/*
  DAEMON VARIABLES
  Daemon threading and scale tweaks can be done here.
  Hopefully if this ever needs to be adjusted another
  host will be brought online instead.  By the time it is necessary to
  adjust children, likely we will be looking at disk IO or perhaps even
  RAM issues as well.  A small remote host may be a better solution than
  tweaking children locally.

  Any changes here require daemon to be restarted or they will not be
  picked up.  This file is only read once at daemon start.

  pollerName should not be IP address, but a FQDN so graphs on perf
  are able to be found.
  Log Levels default to $daemonLogSeverity if not set/overridden

  Log levels:
  0 log everything possible
  1 log debug and up
  2 log info and up
  3 log warning and up
  4 log error and up
  5 log criticals
*/

$defaultDaemonLog = 0;
$daemonLog['randomPollerType'] = 0;
$daemonLog['housekeeping'] = 2;
$daemonLog['snmp'] = 2;
$daemonLog['nrpe'] = 0;
$daemonLog['alive'] = 2;
$daemonLog['shell'] = 1;

/*
  Daemon and job controls for pollers
  housekeeping does not use this, only
  legit pollers do.
*/

// How many children should we spawn at once (max)
$defaultMaxChildren = 20;
$maxChildren['snmp'] = 40;
$maxChildren['nrpe'] = 30;
$maxChildren['shell'] = 20;
$maxChildren['alive'] = 20;
$maxChildren['randomPollerType'] = 10;

// maxWork per child should always be 1. (or bad things will happen (for now?))
$defaultMaxWork = 1;
$maxWork['snmp'] = 1;
$maxWork['nrpe'] = 1;
$maxWork['shell'] = 1;
$maxWork['alive'] = 1;
$maxWork['randomPollerType'] = 1;

$nrpePath="/usr/lib/nagios/plugins/check_nrpe";      // Define this here even though it is specific to NRPE daemon ( will be gone in V2?)


/*
  Housekeeping specific values outside of common daemon values
  This is going to be more important in V2 when housekeeping
  will be a little smarter and know about itself more

  Right now, housekeeping should NVER be run on a remote poller.
  there simply is no reason to.  (yet)
*/

$housekeepingRrdClean=30;                          // how long to keep rrd files in days
$housekeepingGraphClean=1;                         // how long to keep rendered graphs in public/static/???.jpg in days
$housekeepingDatabaseClean=30;                     // how long to keep performance metrics in days
$housekeepingDebuggerClean=14;                     // how long to keep debugger files

?>
