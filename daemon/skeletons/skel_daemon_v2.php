<?php
//declare(strict_types=1);

/*
  Not a smart solution, however snmp failures err to stdout even though we are dealing with
  them internally.  Perhaps in the future SNMP will be better dealt with.
  The class has several buggy gotchas that are a real PITA to deal with.
*/

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);
declare(ticks=1);
/*
  https://alexwebdevelop.com/php-daemons/
  Creating the daemon controller itself
*/

// unique pidfile based on iteration cycle so we can kill easier
$daemonPid=getmypid();
$logSeverity=0;

require_once __DIR__ . "/../../app/config.php";

//$apiHost="http://localhost";
//$apiPort=8002;
$nrpePath="/usr/lib/nagios/plugins/check_nrpe";
$pollerName='larvel01.iwillfearnoevil.com';


/*
  daemonFunctions contains the following:
  signalHandler, convert, sendAlarm, sendHostAlarm, dumpDatabaseObject, heartBeat, pullActiveEvents, pullMonitors, storeResults, convertPeriodToUnderbar, convertSlashToUnderbar
*/
// Enable Eventing support for daemon
require __DIR__ . '/../../app/Curl.php';

include_once __DIR__ . '/../../src/Infrastructure/Shared/Functions/daemonFunctions.php';


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
if (isset($cliOptions['s'])) {
  $daemonState=$cliOptions['s'];
}
if (empty($iterationCycle)) {
  echo "FATAL: -i is a manditory parameter for your iteration cycle\n";
  exit();
  $iterationCycle=3;
}
if (isset($cliOptions['t'])) {
  $monitorType=$cliOptions['t'];
  $monitorType=strtolower($monitorType);
}
if (empty($monitorType)) {
  $monitorType="snmp";
}
if (empty($daemonState)) {
  $daemonState='start';
}

$cycle=$iterationCycle;
// Enable logging system (filename, and minimum sev to log, iterationCycle)
require __DIR__ . '/../../app/Logger.php';
$logger = new Logger($monitorType."Poller", $logSeverity, $iterationCycle);


/*
  Set values to create job processor
*/

// General shared functions for use.
require_once(__DIR__ . '/../../src/Infrastructure/Shared/ForkMonitor/Class/fork_daemon.php');

/* setup forking daemon */
$server = new fork_daemon();
$server->max_children_set(20);
$server->max_work_per_child_set(1);
$server->register_child_run("process_child_run");
$server->register_parent_child_exit("process_child_exit");
$server->register_logging("logger", fork_daemon::LOG_LEVEL_ALL);
$server->register_parent_results("process_results");
$server->store_result_set(true);


// Start the guts of the daemon here
$sleepDate=time();
date_default_timezone_set('UTC');
$logger->info("Daemon called for iteration cycle of $iterationCycle under pid: $daemonPid to $daemonState daemon");

/*
  daemonFunctions contains the following:
  signalHandler, convert, sendAlarm, sendHostAlarm, dumpDatabaseObject, heartBeat, pullActiveEvents, pullMonitors, storeResults, convertPeriodToUnderbar, convertSlashToUnderbar
*/
//require __DIR__ . '/../../src/Infrastructure/Shared/Functions/daemonFunctions.php';

// This will allow different daemons with different
// iteration cycles to run side by side
$daemonPidFileName = $monitorType."Poller" . '.' . $iterationCycle . '.pid';
$daemonPidFile = @fopen($daemonPidFileName, 'c');
if (! $daemonPidFile) {
  //  sendAlarm("Unable to open pidfile $daemonPidFileName", 3);
  die("Could not open $daemonPidFileName\n");
}

if (!@flock($daemonPidFile, LOCK_EX | LOCK_NB)) {
  $daemonPid2 = file_get_contents($daemonPidFileName);
  if ( $daemonState == "stop" ) {
    echo "Stopping daemon " . $monitorType."Poller" . " pid " . $daemonPid2 . "\n";
    exec ("kill -15 $daemonPid2 &>/dev/null");
    ftruncate($daemonPidFile, 0);
    die();
  }
  else {
    die("Daemon already running for " . $monitorType."Poller" . " pid: " . $daemonPid2 . "\n");
  }
}
elseif ( $daemonState == "stop" ) {
  ftruncate($daemonPidFile, 0);
  $logger->warning("Daemon stop was called for ". $monitorType."Poller" . " but there is no recorded daemon running.  Check for orphans");
  die("Daemon does not have a recorded pid running for " . $monitorType."Poller" . "\n");
}
else {
  $daemonPid2 = file_get_contents($daemonPidFileName);
  if (empty($daemonPid2)) {
    $logger->warning("Daemon start was called for ". $monitorType."Poller");
  }
  else {
    if(file_exists( "/proc/$daemonPid2")) {
      $logger->error("Daemon start was called but daemon is already running! This pid is " . $daemonPid . " and within lock file the value is " . $daemonPid2 . ' for '  . $monitorType."Poller" );
    }
    else {
      $logger->debug("Stale pidfile found.  Updating with new pid of ". $daemonPid);
    }
  }
}

// Start the daemon and log our running pid value now
echo "Starting daemon " . $monitorType."Poller" . " pid " . $daemonPid . "\n";
ftruncate($daemonPidFile, 0);
fwrite($daemonPidFile, "$daemonPid");

/*
  This is our daemon loop
*/
while (true) {
  $startSize=convert(memory_get_usage(true));

  // Update heartbeat each iteration
  heartBeat($monitorType."Poller", $iterationCycle, $daemonPid);
  $logger->debug("Heartbeat sent");

  /*
    Pull ALL active events so we do not hammer the database with "ok" messages
  */
  $activeEvents = pullActiveEvents();
  $activeEvents = json_decode($activeEvents,true);


  if ( ! is_null($activeEvents['data'])) {
    $countActiveEvents=count($activeEvents['data']);
  }
  else {
    $countActiveEvents=0;
  }
  $logger->debug("Active events found is " . $countActiveEvents);

  // echo "COUNT ACTIVE " . $countActiveEvents . "\n";  // DEBUG
  // print_r($activeEvents); // DEBUG
  // exit();  // DEBUG

  $pullMonitors2 = pullMonitors($monitorType, $iterationCycle);
  $pullMonitors=json_decode($pullMonitors2,true);
  if ( ! is_null($pullMonitors['data'])) {
    $pullMonitorsCount=count($pullMonitors['data']);
  }
  else {
    $pullMonitorsCount=0;
  }
  $logger->info("Poller table query for $monitorType returned $pullMonitorsCount distinct monitors");
  //  print_r($pullMonitors['data']);  //DEBUG
  //  exit();  // DEUBG
  // echo "COUNT MONITORS " . $pullMonitorsCount . "\n";  // DEBUG


  // Our list of monitors and all host details that we have in hostProperties
  $monitorListHostDetails = getMonitorHostDetails( $pullMonitors['data'] );
  //  print_r($monitorListHostDetails);  //DEBUG
  //  exit();  // DEBUG

  $expandedList=array();
  foreach ($monitorListHostDetails as $monitorExpanded) {
    // print_r($monitorExpanded); // DEBUG
    foreach($monitorExpanded['hostProperties'] as $singleHostProperties) {
      $expandedList[] = array( 'id' => $monitorExpanded['id'], 'checkName' => $monitorExpanded['checkName'], 'checkAction' => $monitorExpanded['checkAction'], 'type' => $monitorExpanded['type'], 'storage' => $monitorExpanded['storage'], 'hostProperties' => $singleHostProperties);
    }
  }
  $countExpandedList=count($expandedList);
  $logger->debug("Count list of checks is " . $countExpandedList);
  //print_r($expandedList);  // DEBUG
  //exit();
  /*
    This is where the magic happens.
  */
  //print_r($server); // DEBUG
  job_blocking();
  //  job_nonblocking();


  // After we are all done getting data, get our results
  $jobResults = $server->get_all_results();
  // print_r($jobResults); // DEBUG

  // We have data, now DO something with it
  foreach ($jobResults as $monitorResults) {
    //echo "JOB RESULTS " . $monitorResults['checkName'] . " ". $monitorResults['hostname'] . " " . json_encode($monitorResults['output'],1) . "\n"; // DEBUG
    //print_r($monitorResults); // DEBUG
    if ( array_key_exists('output', $monitorResults) && array_key_exists('exitCode', $monitorResults) ) { // No output or exit code implies check was not run
      // Clear active events if we have a success match
      clearEvents($activeEvents, $monitorResults);
      // Send new active events found
      sendEvents($monitorResults);
      // Store our data in whereever defined
      storageForward($monitorResults);
    }
    else {
      $logger->warning("Service check for " . $monitorResults['hostname'] . " with check name " . $monitorResults['checkName'] . " have no output or exit code defined.  Bypassing attempt to record metrics or work with events");
    }
  }  // end foreach
  // print_r($jobResults);  // DEBUG
  //  exit(); // DEBUG

  /*
    This is the bottom half of the while loop.  Everything here
    is of a housekeeping nature for the daemon itself.
    Saving metrics and dealing with events is all above this point.
  */
  $endSize = convert(memory_get_usage(true));
  $logger->info("Daemon memory Stats: Beginning of active loop is " . $startSize . " end of loop size is " . $endSize);
  /*
    we have completed our iteration for this cycle, now update info
    about the daemon itself
  */
  $timeNow=time();
  if ( ($sleepDate + $iterationCycle) <= $timeNow ) {
    $timeDelta=( $timeNow - ($sleepDate + $iterationCycle));
    sendAlarm("Daemon poller had an iteration overrun.  Confirm daemon is not overloaded in logs delta is " . $timeDelta . " seconds" , 2, $monitorType."Poller"."-iterationComplete-" . $iterationCycle);
    $logger->error("Iteration overrun.  Cycle took $timeDelta seconds beyond the iteration defined for $pullMonitorsCount monitors");
    $newIterationCycle=1;
  }
  else {
    $timeDelta=($timeNow - $sleepDate);
    foreach($activeEvents as $validEvent) {
      if ( $validEvent['eventName'] == $monitorType."Poller"."-iterationComplete-" . $iterationCycle ) {
        sendAlarm("Daemon poller iteration complete.  Daemon is not overloaded in logs delta is " . $timeDelta . " seconds for $pullMonitorsCount monitors", 0, $monitorType."Poller"."-iterationComplete-" . $iterationCycle);
        $logger->debug("Sent trap for iteration overrun alarm");
      }
    }
    // Attempt to send our perf metric of the poller to graphite
    $pollerMetric=array( 'Monitors' => $pullMonitorsCount, 'Devices' => $countExpandedList, 'Time' => $timeDelta, 'Memory' => memory_get_usage(true));
    // echo json_encode($pollerMetric,1) . "\n"; // DEBUG

    $sent=sendPollerPerformance($pollerName, json_encode($pollerMetric,1), $monitorType."-".$cycle, null, "poller", null);
    if ( $sent == 1 ) {
      $logger->error("Failed to save metric data");
    }
    else {
      $logger->info("Saved metric data");
    }
    $logger->info("Iteration complete.  Cycle took $timeDelta seconds to complete for $pullMonitorsCount monitors against $countExpandedList devices");
    $newIterationCycle=( $iterationCycle - $timeDelta );
  }
  sleep($newIterationCycle);
  $sleepDate=time();
}

/*
  This function needs to be declared outside of both the loop and include functions to work correctly.
  Donno why however.
*/
function process_child_run($data_set, $identifier = "") {
  // Still need to be able to log stuff inside here
  global $logger;

  if ( $data_set[0]['type'] == "get" || $data_set[0]['type'] == "walk") {
    $pollerType="snmp";
  }
  else {
    $pollerType=$data_set[0]['type'];
  }
  // echo "Im a child working on: type ". $pollerType . " specifically "  . $data_set[0]['checkName'] . " hostname " . $data_set[0]['hostProperties']['hostname'] . "  "  . ($identifier == "" ? "" : " (id:$identifier)") . "\n"; // DEBUG

  /*
    Time to split the fuctionality out on a pollerType basis.  SNMP, NRPE, SHELL, PING are the current distinct types
    Each of these will call their own functions and return the data
    If we hit default, that means someone screwed up with an unsupported poller type
  */
  $returnData=array();
  $returnData['hostname'] = $data_set[0]['hostProperties']['hostname'];
  $returnData['address'] = $data_set[0]['hostProperties']['address'];
  $returnData['checkName'] = $data_set[0]['checkName'];
  $returnData['storage'] = $data_set[0]['storage'];
  $returnData['checkAction'] = $data_set[0]['checkAction'];
  $returnData['type'] = $data_set[0]['type'];

  /*
    If a host is "dead" from the alive check disable all additional checks.
    There is simply no reason to do them on a down host.  Just make VERY
    sure that we still allow the alive check to happen! (duh)
  */
  //  print_r($data_set);
  if ( $pollerType !== "alive" && $data_set[0]['hostProperties']['isAlive'] == 'dead' ) {  // Do not check services on a dead host, duh
    $logger->warning("Host " . $returnData['hostname'] . " is showing offline.  Service check bypassed " . $data_set[0]['checkAction']);
  }
  else {
    switch ($pollerType) {
    case "alive":
      $result=shellAlive($data_set[0]['hostProperties']['hostname'], $data_set[0]['hostProperties']['address'], $data_set[0]['checkAction']);
      $returnData['output'] = $result['output'];
      $returnData['exitCode'] = $result['exitCode'];
      $returnData['command'] = $result['command'];
      if ($result['exitCode'] !== 0) {
        // echo "NOT ALIVE " . $data_set[0]['hostProperties']['hostname'] . " COMMAND " . $result['command']. "\n"; // DEBUG
        isAlive($data_set[0]['hostProperties']['hostname'], $data_set[0]['hostProperties']['address'], "dead");
      }
      else {
        // echo "ALIVE " . $data_set[0]['hostProperties']['hostname'] . " COMMAND " . $result['command']."\n";  // DEBUG
        isAlive($data_set[0]['hostProperties']['hostname'], $data_set[0]['hostProperties']['address'], "alive");
      }
      break;
    case "ping":
      // Legacy daemon.  Use alive.  Some hosts cant do ping, but will respond to other checks
      $result=shellPing($data_set[0]['hostProperties']['address']);
      $returnData['output'] = $result['output'];
      $returnData['exitCode'] = $result['exitCode'];
      $returnData['command'] = $result['command'];
      if ($result['exitCode'] !== 0) {
        isAlive($data_set[0]['hostProperties']['hostname'], $data_set[0]['hostProperties']['address'], "dead");
      }
      else {
        isAlive($data_set[0]['hostProperties']['hostname'], $data_set[0]['hostProperties']['address'], "alive");
      }
      break;
    case "nrpe":
      global $nrpePath;
      $result=shellNrpe($nrpePath, $data_set[0]['hostProperties']['address'], $data_set[0]['checkAction']);
      // echo "TEST " . $nrpePath . " ADDRESS " . $data_set[0]['hostProperties']['address'] . " ACTION ".  $data_set[0]['checkAction'] . "\n"; // DEBUG
      $returnData['output'] = json_encode($result['output'],1);
      $returnData['exitCode'] = $result['exitCode'];
      $returnData['command'] = $result['command'];
      // echo "RESULT " . $returnData['command'] . " OUTPUT " . json_encode($returnData['output'],1) . " EXIT " . $returnData['exitCode'] . "\n"; // DEBUG
      break;
    case "shell":
      $result=shellShell($data_set[0]['hostProperties']['hostname'], $data_set[0]['hostProperties']['address'], $data_set[0]['checkAction']);
      $returnData['output'] = $result['output'];
      $returnData['exitCode'] = $result['exitCode'];
      $returnData['command'] = $result['command'];
      break;
    case "snmp":
      $output=array();  // reset your output array after each use
      if ( isset($data_set[0]['hostProperties']['Properties']) ) {
        if ( $data_set[0]['hostProperties']['Properties']['snmpVersion'] == 2 ) {
          $data_set[0]['hostProperties']['Properties']['snmpVersion']="2c" ;
        }
        // echo "DEBUG host " . $data_set[0]['hostProperties']['address'] . " community " . $data_set[0]['hostProperties']['Properties']['snmpCommunity'] . " version " . $data_set[0]['hostProperties']['Properties']['snmpVersion'] . "\n";
        if ( $data_set[0]['type'] == "get" ) {
          $result=shellSnmpGet($data_set[0]['hostProperties']['address'], $data_set[0]['hostProperties']['Properties']['snmpVersion'], $data_set[0]['hostProperties']['Properties']['snmpCommunity'],$data_set[0]['checkAction']);
          //print_r($result); // DEBUG
        }
        else {
          $result=shellSnmpWalk($data_set[0]['hostProperties']['address'], $data_set[0]['hostProperties']['Properties']['snmpVersion'], $data_set[0]['hostProperties']['Properties']['snmpCommunity'],$data_set[0]['checkAction']);
          //print_r($result); // DEBUG
        }
        $returnData['output'] = $result['output'];
        $returnData['exitCode'] = $result['exitCode'];
        $returnData['command'] = $result['command'];
        break;
        /*
        this is all debugging stuff
        echo "Beginning EXEC \n";
        $result=exec( $cmd, $output, $result_code);
        $returnData['output'] = $output;
        $returnData['exitCode'] = $result_code;
        $returnData['command'] = $cmd;
        echo "HOST " . $data_set[0]['hostProperties']['hostname'] . " CMD TYPE " . $data_set[0]['type'] . " OID " . $data_set[0]['checkAction'] . " EXIT CODE " . $result_code . "\n";
        echo "CHECK " . $data_set[0]['checkName'] . " RESULTS " . json_encode($output,1) . " \n\n";
        echo "CHECK " . $data_set[0]['checkName'] . " RESULTS " . print_r($result) . " \n\n";
        */
      }
      else {
        // This is a FAILURE for SNMP
        $returnData['output'] = "HOST " . $data_set[0]['hostProperties']['hostname'] . "  Has no properties set to query";
        $returnData['exitCode'] = 2;
        $returnData['command'] = "check validation";
        $logger->error("HOST " . $data_set[0]['hostProperties']['hostname'] . "  Has no SNMP properties set to query");
        break;
      } // end  if
    default:
      // This is a FAILURE of poller TYPE
      $returnData['output'] = "HOST " . $data_set[0]['hostProperties']['hostname'] . "  Has no poller type that it can use";
      $returnData['type'] = $data_set[0]['type'];
      $returnData['exitCode'] = 2;
      $returnData['command'] = "poller validation";
      $logger->error("HOST " . $data_set[0]['hostProperties']['hostname'] . "  Has no poller type that it can use for poller supporting " . $data_set[0]['type'] );
      break;
    }  // end  switch
  } // end if ! alive host
  $pollerType='';  // Unset each run
  return $returnData;
} // end function

?>
