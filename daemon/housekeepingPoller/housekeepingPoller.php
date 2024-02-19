#!/usr/bin/env php

<?php
declare(ticks=1);
/*
  https://alexwebdevelop.com/php-daemons/
  Creating the daemon controller itself
*/

// unique pidfile based on iteration cycle so we can kill easier
$pid=getmypid();

include_once (__DIR__ . '/../../app/config.php');
require_once __DIR__ . '/../../src/Infrastructure/Shared/Functions/daemonFunctions.php';
require __DIR__ . '/../../app/Curl.php';

if ( isset($daemonLog['housekeeping'])) {
  $housekeepingLogSeverity = $daemonLog['housekeeping'];
}
else {
  $housekeepingLogSeverity = $defaultDaemonLog;
}

// Support daemon shutdown
pcntl_async_signals(true);
pcntl_signal(SIGTERM, 'signalHandler'); // Termination (kill was called)
pcntl_signal(SIGHUP, 'signalHandler');  // Terminal log-out
pcntl_signal(SIGINT, 'signalHandler');  // Interrupted (Ctrl-C is pressed) (when in foreground)

/*
This is intended to be called via PHP-cli so we need to support args
iterationCycle is critical, but we also need to support start /stop as well.
*/

$cliOptions= getopt("i:s:t:");
if (isset($cliOptions['i'])) {
  $iterationCycle=intval($cliOptions['i']);
}
elseif (empty($iterationCycle)) {
  echo "FATAL: -i is a manditory parameter for your iteration cycle\n";
  exit();
  $iterationCycle=60;  // redundant I know, but in case it does not die.. somehow happened once?!??
}

if (isset($cliOptions['s'])) {
  $daemonState=$cliOptions['s'];
}

if (isset($cliOptions['t'])) {
  $monitorType=$cliOptions['t'];
  $monitorType=strtolower($monitorType);
}
elseif (empty($monitorType)) {
  $monitorType="housekeeping";
}

if (empty($daemonState)) {
  $daemonState='start';
}

// Enable logging system (filename, and minimum sev to log, iterationCycle)
require __DIR__ . '/../../app/Logger.php';
$logger = new Logger($monitorType."Poller", $housekeepingLogSeverity, $iterationCycle);


// Start the guts of the daemon here
$sleepDate=time();
date_default_timezone_set('UTC');

// Make damn sure we are dealing with a clean int here
$iterationCycle=(int)$iterationCycle;

$logger->info("Daemon called for iteration cycle of $iterationCycle under pid: $pid to $daemonState daemon");

$daemonPidFileName = $monitorType."Poller" . '.' . $iterationCycle . '.pid';

$daemonPidFile = @fopen($daemonPidFileName, 'c');

if (! $daemonPidFile) {
  die("Could not open $daemonPidFileName\n");
}

if (!@flock($daemonPidFile, LOCK_EX | LOCK_NB)) {
  $pid2 = file_get_contents($daemonPidFileName);
  if ( $daemonState == "stop" ) {
    echo "Stopping daemon " . basename(__FILE__) . " pid " . $pid2 . "\n";
    exec ("kill -15 $pid2 &>/dev/null");
    die();
  }
  else {
    die("Daemon already running for " . basename(__FILE__) . " pid: " . $pid2 . "\n");
  }
}
elseif ( $daemonState == "stop" ) {
  ftruncate($daemonPidFile, 0);
  $logger->warning("Daemon stop was called for ". basename(__FILE__) . " but there is no recorded daemon running.  Check for orphans");
  die("Daemon does not have a recorded pid running for " . basename(__FILE__) . "\n");
}
else {
  $pid2 = file_get_contents($daemonPidFileName);
  if (empty($pid2)) {
    $logger->warning("Daemon start was called for ". basename(__FILE__));
  }
  else {
    $logger->error("Daemon start was called but daemon is already running! This pid is " . $pid . " and within lock file the value is " . $pid2 . ' for '  . basename(__FILE__) );
  }
}
echo "Starting daemon " . basename(__FILE__) . " pid " . $pid . "\n";
// Log our running pid value now
ftruncate($daemonPidFile, 0);
fwrite($daemonPidFile, "$pid");

// Send a heartbeat to start
$sendHeartbeat=heartBeat($monitorType,$iterationCycle,$pid);
if ( $sendHeartbeat == "ok") {
  $logger->debug("Initial hartbeat sent for " . $monitorType);
}
else {
  $logger->error("Failed to send initial heartbeat for ". $monitorType);
}

// Daemon loop starts now
while (true) {

  // heartbeat always at the beginning of the loop
  $sendHeartbeat=heartBeat($monitorType,$iterationCycle,$pid);
  if ( $sendHeartbeat == "ok") {
    $logger->debug("Initial hartbeat sent for " . $monitorType);
  }
  else {
    $logger->error("Failed to send initial heartbeat for ". $monitorType);
  }
  // If it can write to the DB, then thats a valid heartbeat ;)
  heartBeat('mysql', 60, 12345);

  // Pull all active events
  $activeEvents=findAllEvents();
  if ( ! is_null($activeEvents)) {
    $activeEventsCount = count($activeEvents);
  }
  else {
    $activeEventsCount=0;
  }
  $logger->debug("Pulled " . $activeEventsCount . " currently active events");

  // Find ageOut Events
  $ageOutEvents=findAgeOutEvents();
  if ( is_null($ageOutEvents)) {
    $ageOutEvents=array();
  }
  $logger->info("Found " . count($ageOutEvents) . " active events to move to history");
  if ( count($ageOutEvents) > 0 ) {
    foreach ($ageOutEvents as $ageOut) {
      $cleanOut = $ageOut['evid'];
      $reason = "Event has aged out of the system.  Removed by housekeeping";
      $logger->info("Age Out of evid " . $cleanOut);
      moveToHistory($cleanOut,$reason);
    }
  }

  // pull all heartbeats
  $currentHeartbeats=getHeartbeats();
  if ( empty($currentHeartbeats)) { $currentHeartbeats=array(); }

  $validIgnores = array("snmptrapd", "mysql", "randomPollerOrPassiveCheck");
  foreach ($currentHeartbeats as $singleHeartbeat) {
    $checkHbPid='check';
    $hbCycle = explode('_', $singleHeartbeat['component']);
    $hbCycle = ltrim(rtrim($hbCycle[1]));
    $hbDevice = $singleHeartbeat['device'];
    $hbLastUpdate = $singleHeartbeat['lastTime'];
    $hbLastUpdateEpoch = strtotime($hbLastUpdate);
    $hbCycleWindow = $hbCycle * 2 ;
    $hbTimeNow = time();
    $hbTimeWindow = $hbCycleWindow + $hbLastUpdateEpoch;
    if ( $hbTimeWindow <= $hbTimeNow ) {  // too long since last hb update in database
      if ( ! in_array($singleHeartbeat['device'], $validIgnores)) {  // only look at legit processes
      sendHostAlarm("Poller "  . $singleHeartbeat['device'] . " with a cycle of " . $hbCycle . " has not sent a heartbeat within check window", 5, $singleHeartbeat['device']. '-' . $hbCycle, $pollerName, 3600, null);
      $logger->error("Poller " . $singleHeartbeat['device'] . " with a cycle of " . $hbCycle . " has not sent a heartbeat within check window");
      $checkHbPid='no check';
      }
    }
    else { // Clear events if there are any from previous failures
      foreach ($activeEvents as $activeEvent) {
        if ( $activeEvent['eventName'] == $singleHeartbeat['device'] . '-' . $hbCycle ) {
          sendHostAlarm("Poller " . $singleHeartbeat['device'] . " with a cycle of " . $hbCycle . " is running", 0, $singleHeartbeat['device']. '-' . $hbCycle, $pollerName, 3600, null);
          $logger->info("Cleared failure for " . $singleHeartbeat['device'] . " not running");
        }
      }
    }
    if ( $checkHbPid == 'check' &&  ! in_array($singleHeartbeat['device'], $validIgnores) ) { // alarm if daemons are not running.  Ignore database.  Cant alarm to db if it is dead anyway
      $hbPid = verifyPid($singleHeartbeat['pid']);
      if ( $hbPid !== 'running') {
        sendHostAlarm("Poller "  . $singleHeartbeat['device'] . " with a cycle of " . $hbCycle . " is a possible zombie process.  Pid does not match database", 3, $singleHeartbeat['device'] . '-' . $hbCycle . '-pid', $pollerName, 1800, null);
        $logger->error("Poller " . $singleHeartbeat['device'] . " with a cycle of " . $hbCycle . " is a possible zombie process.  Pid does not match database");
      }
      else {  // clear alarms if they exist
        foreach ($activeEvents as $activeEvent) {
          if ( $activeEvent['eventName'] == $singleHeartbeat['device'] . '-' . $hbCycle . '-pid' ) {
            sendHostAlarm($singleHeartbeat['device'] . ' daemon is running', 0, $singleHeartbeat['device'] . '-' . $hbCycle . '-pid', $pollerName, 3600, null);
            $logger->info("Cleared failure for " . $singleHeartbeat['device'] . " mismatched pid");
          }
        } // end foreach
      }  // end else
    }  // end if
  } // end foreach
  if ( is_null($currentHeartbeats)) { $currentHeartbeats=''; }
  $logger->info("Completed heartbeat checks against " . count($currentHeartbeats) . " pollers.");

  // Check if snmptrap is running
  $snmpTrapDaemon=verifyPidFileRunning('/run/snmptrapd.pid');
  if ( $snmpTrapDaemon !== 'running' ) {
    sendHostAlarm('snmptrapd daemon is not running ' . $snmpTrapDaemon , 5, 'snmptrapd', $pollerName, 3600, null);
    $logger->error("snmptrapd daemon is not running.  Event sent");
  }
  else {
   // This is as good as we are going to get for snmptrapd validation
   $snmpTrapDaemonPid = intval(file_get_contents('/run/snmptrapd.pid'));
   heartBeat('snmptrapd', 60, $snmpTrapDaemonPid);
    foreach ($activeEvents as $activeEvent) {
      if ( $activeEvent['eventName'] == 'snmptrapd' ) {
        sendHostAlarm('snmptrapd daemon is running', 0, 'snmptrapd', $pollerName, 3600, null);
        $logger->info("Cleared failure for snmptrapd not running");
      }
    }
  }

  // TODO
  // $maintenanceList = findMaintenance();
  $logger->info("Filter maintenance events within the ECE.");
  /*
    Intention is suppression or unsuppression of alarms within a window.
    These should be filtered down to hosts expected to be impacted by
    an application type
  */

  // Clean out old debugger files (since this is WC, note the / on the end
  $logger->info("Remove old debugger files if older than ". $housekeepingDebuggerClean ." days");
  deleteOldFilesWildcard2('../../file/*/debugger/', $housekeepingDebuggerClean);

  // clean out old rrd files that are not updated anymore
  $logger->info("Remove old RRD database files of older than " . $housekeepingRrdClean . " days");
  deleteOldFiles('../../rrd', $housekeepingRrdClean);

  // Clean out old database performance cruft
  $logger->info("Remove old database performance values older than " . $housekeepingDatabaseClean . " days");
  housekeepingDatabaseClean($housekeepingDatabaseClean);

  // Clean out the old images under public/static/blah.jpg that are over a day old
  $logger->info("Remove old jpg images from public/static directory");
  deleteOldFiles('../../public/static', $housekeepingGraphClean);

  $timeNow=time();
  if ( ($sleepDate + $iterationCycle) <= $timeNow ) {
    $timeDelta=( $timeNow - ($sleepDate + $iterationCycle));
    sendHostAlarm("Daemon poller had an iteration overrun.  Confirm daemon is not overloaded in logs delta is " . $timeDelta . " seconds", 2, "housekeeping-iterationComplete-" . $iterationCycle, $pollerName, 3600, null );
    $logger->error("Iteration overrun.  Cycle took $timeDelta seconds beyond the iteration defined");
    $newIterationCycle=1;
  }
  else {
    $timeDelta=($timeNow - $sleepDate);
    foreach($activeEvents as $validEvent) {
      if ( $validEvent['eventName'] == "housekeeping-iterationComplete-" . $iterationCycle) {
        sendHostAlarm("Daemon poller iteration complete.  Daemon is not overloaded in logs delta is " . $timeDelta . " seconds", 0, "housekeeping-iterationComplete-" . $iterationCycle, $pollerName, 3600, null);
        $logger->info("Iteration overrun cleared.  Cycle was within normal parameters");
      }
    }
    $logger->info("Iteration complete.  Cycle took $timeDelta seconds to complete");
    $logger->info("Memory utilization is " . memory_get_usage(true) . " bytes");
    $newIterationCycle=( $iterationCycle - $timeDelta );
  }
  sleep($newIterationCycle);
  $sleepDate=time();
}

// End of daemon loop
?>
