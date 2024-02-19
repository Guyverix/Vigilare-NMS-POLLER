<?php
/*
  Functions that different daemons can use without changes.
  Since these are simply functions, do NOT use the logger
  interface here.
*/


require_once __DIR__ . "/../../../../templates/generalMetricSaver.php";

// Debugging database object
function dumpDatabaseObject() {
  if ( isset($db) ) {
    $data[] = var_dump($db);
    $data[] = print_r($db);
    $data[] = $db->error;
  }
  else {
    $data[] = "Database object or variable db does not exist";
  }
  return $data;
}

// Internal way to get raw values to pretty
function convert($size) {
    $unit=array('b','kb','mb','gb','tb','pb');
    return @round($size/pow(1024,($i=floor(log($size,1024)))),2).' '.$unit[$i];
}

// Send event via API call
function sendAlarm ( ?string $alarmEventSummary = "Oops.  Someone forgot to set the event summary for information", ?int $alarmEventSeverity = 2, ?string $alarmEventName = "smartSnmpPoller" ) {
  global $apiUrl;
  global $apiHost;
  global $apiPort;
  global $apiKey;
  $alarm = new Curl();
  // ALWAYS RESET OUR ARRAY BEFORE USING IT
  $alarmInfo=array( "device" => gethostname(), "eventSummary" => "" , "eventName" => "", "eventSeverity" => 0 );
  // Limited changes are allowed for event generation to keep it simple
  $alarmInfo['eventSummary'] = $alarmEventSummary;
  $alarmInfo["eventSeverity"] = $alarmEventSeverity;
  $alarmInfo["eventName"] = $alarmEventName;
  // Set our details here
  $alarm->url = $apiUrl . ":" . $apiPort . "/trap";
  $alarm->method = "post";
  $alarm->data($alarmInfo);
  $alarm->headers = ["X-Api-Key: $apiKey"];
  $alarm->send();
  $alarm->close();
  // No need to keep this in RAM, as object should be rarely used
  unset ($alarm);
  return "ok";
}

// Send event via API call
function sendHostAlarm ( $alarmEventSummary, $alarmEventSeverity , $alarmEventName , $hostname, ?int $ageOut = 600, $details = null ) {
  global $apiUrl;
  global $apiHost;
  global $apiPort;
  global $apiKey;
  $alarm2 = new Curl();
  // ALWAYS RESET OUR ARRAY BEFORE USING IT
  $alarmInfo2=array( "device" => "undefined", "eventSummary" => "" , "eventName" => "", "eventSeverity" => 0, "eventDetails" => "none" );

  // Details can be empty
  if (null === $details) {
    // $alarmInfo2['eventDetails'] = "No details given";
    // Dont set anything in the post at all
    $junk=0;
  }
  else {
    $alarmInfo2['eventDetails'] = $details;
  }
  // Limited changes are allowed for event generation to keep it simple
  $alarmInfo2['device'] = $hostname;
  $alarmInfo2['eventSummary']=$alarmEventSummary;
  $alarmInfo2["eventSeverity"]=$alarmEventSeverity;
  $alarmInfo2["eventName"]=$alarmEventName;
  $alarmInfo2["eventAgeOut"]=$ageOut;

  // Set our details here
  $alarm2->url = $apiUrl . ":" . $apiPort . "/trap";
  $alarm2->method = "post";
  $alarm2->headers = ["X-Api-Key: $apiKey"];
  $alarm2->data($alarmInfo2);
  $alarm2->send();
  $alarm2->close();
  // No need to keep this in RAM, as object should be rarely used
  unset ($alarm2);
  return "ok";
}

// Define the API destination since this is not an event
function heartBeat( $pollerName, $pollerCycle, $pollerPid = null ) {
  global $apiHost;
  global $apiPort;
  global $apiKey;

  if ( null === $pollerPid ) {
    $pollerPid = 0;
  }
  $heartBeat = new Curl();
  $heartBeat->url = $apiHost . ":" . $apiPort . "/monitoringPoller/heartbeat";
  $heartBeat->method = "post";
  $heartBeatValues['pollerName']  = $pollerName;
  $heartBeatValues['pollerCycle'] = $pollerCycle;
  $heartBeatValues['pollerPid']   = $pollerPid;
  $heartBeat->headers = ["X-Api-Key: $apiKey"];
  $heartBeat->data($heartBeatValues);
  $heartBeat->send();
  $heartBeat->close();
  unset($heartBeat);
  return "ok";
}

// Define the API destination for aliveness results
function isAlive( $hostname, $address, $isAliveResult = null ) {
  global $apiHost;
  global $apiPort;

  if ( null === $isAliveResult ) {
    // assume death unless explicitly stating it is alive
    // this does NOT do work on perf.  Just alive or dead.
    $isAliveResult = "dead";
  }
  $isAlive = new Curl();
  $isAlive->url = $apiHost . ":" . $apiPort . "/monitoringPoller/isAlive";
  $isAlive->method = "post";
  $isAliveValues['hostname'] = $hostname;
  $isAliveValues['address']  = $address;
  $isAliveValues['isAlive']  = $isAliveResult;

  $isAlive->data($isAliveValues);
  $isAlive->send();
  $isAlive->close();
  unset($isAlive);
  return "ok";
}


// Just what it says
function pullActiveEvents() {
  global $apiHost;
  global $apiPort;

  $pullEvents = new Curl();
  $pullEvents->url = $apiHost . ":" . $apiPort . "/events/monitorList";
  $pullEvents->send();
  $data = $pullEvents->content();
  // echo "DATA: " . print_r($pullEvents); // DEBUG
  $pullEvents->close();
  unset($pullEvents);
  return $data;
}

// Retrieve all monitors for a given type and iteration cycle
function pullMonitors( $monitorType, $monitorCycle ) {
  global $apiHost;
  global $apiPort;

  $pullMonitors = new Curl();
  $pullMonitors->url = $apiHost . ":" . $apiPort . "/monitoringPoller/" . $monitorType . "?cycle=" . $monitorCycle;
  $pullMonitors->send();
  $data = $pullMonitors->content();
  $pullMonitors->close();
  return $data;
}

// Create API to do this work
// All prep work and formatting for the given storage type
// must be done before calling this
function storeResults($storage, $hostname, $result, $value) {
  global $apiHost;
  global $apiPort;

  $storeResults = new Curl();
  $storeResults->url = $apiHost . ":" . $apiPort . "/storage";
  $storeResults->method="post";
  $storeResultsData['storage']  = $storage;
  $storeResultsData['hostname'] = $hostname;
  $storeResultsData['result']   = $result;
  $storeResultsData['value']    = $value;
  $storeResults->data = $storeResultsData;
  $storeResults->send();
  $storeResults->close();
  return "ok";
}

// Used for grapite when needed such as FQDN or IP addresses
function convertPeriodToUnderbar($value) {
  $value = preg_replace('/./','_',$value);
  return $value;
}

// Used when grapite data has a / such as hard drives
function convertSlashToUnderbar($value) {
  $value = preg_replace('/\//','_',$value);
  return $value;
}

// This will quit the daemon when a signal is received
function signalHandler($signal) {
  global $iterationCycle;
  global $logSeverity;
  global $daemonPidFile;
  global $daemonPid;
print "Caught signal $signal";
  ftruncate($daemonPidFile, 0);
  echo "PIDFILE is " . $daemonPidFile . "\n";
  $logger2 = new Logger(basename(__FILE__), $logSeverity, $iterationCycle);
  $logger2->info("Daemon shutdown");
  exit;
}

/*
 * CALLBACK FUNCTIONS
*/

/* registered call back function */
function logger($message) {
  echo "logger: " . $message . PHP_EOL;
}

// Run inside loop as a non-blocking style
function job_nonblocking() {
  global $expandedList;
  global $server;
  $data_set=$expandedList;
  // echo "Adding ARRAY of work\n";  // DEBUG
  $server->addwork($data_set);
  //echo "Job Count at start " . $server->work_sets_count() . "\n";  // DEBUG
  //echo "Processing work in non-blocking mode\n"; // DEUBG

  /* process work non blocking mode */
  $server->process_work(false);

  /* wait until all work allocated */
  while ($server->work_sets_count() > 0) {
    // echo "work set count: " . $server->work_sets_count() . "\n"; // DEBUG
    $server->process_work(false);
    usleep(3000);
  }

  /* wait until all children finish */
  while ($server->children_running() > 0) {
    // echo "waiting for " . $server->children_running() . " children to finish\n"; // DEBUG
    usleep(500000);
  }
}

// Run inside loop as a blocking style
function job_blocking() {
  global $expandedList;
  global $server;
  $data_set=$expandedList;
  // echo "Adding ARRAY of work\n"; // DEBUG
  $server->addwork($data_set);
  //  echo "Processing work in blocking mode\n"; // DEBUG

  /* process work non blocking mode */
//  $server->process_work(false);
  $server->process_work(true);

  /* wait until all work allocated */
  while ($server->work_sets_count() > 0) {
    // echo "work set count: " . $server->work_sets_count() . "\n"; // DEBUG
    $server->process_work(false);
    usleep(3000);
  }

  /* wait until all children finish */
  while ($server->children_running() > 0) {
    // echo "waiting for " . $server->children_running() . " children to finish\n"; // DEBUG
    usleep(500000);
  }
}

function getMonitorHostDetails($monitorList) {
  // Begin building a pristine array here with our hostgroupName => hostid values
  $returnList=array();
  $idGlobalList=array();
  // print_r($monitorList);  // DEBUG
  foreach ($monitorList as $monitorListSingle) {
    // This will only exist for each "row"
    // print_r($monitorListSingle); // DEUBG
    $idTransient=array();
    $idProgress='';

    if ( ! empty ($monitorListSingle['hostGroup']) || $monitorListSingle['hostGroup'] !== '' ) {
      // Clean any cruft found in hostGroup so it can explode

      $pattern = '/[\[\]"]/i';
      $monitorListSingle['hostGroup'] = preg_replace( $pattern, '', $monitorListSingle['hostGroup']);

      // If we have more than one hostGroup defined get the id list for each of them
      $monitorListSingleHostgroups = explode("," , $monitorListSingle['hostGroup']);

      foreach ($monitorListSingleHostgroups as $monitorListSingleHostgroup) {
        $monitorListSingleHostgroup=str_replace(" ","", $monitorListSingleHostgroup);
        // echo "\nHOSTGROUP SINGLE CHECK " . $monitorListSingle['checkName'] . " hostgroup " . $monitorListSingleHostgroup . "\n"; // DEBUG

        // If we have not searched for this hostgruoup name before, search now if it is defined
        if ( ! empty ($monitorListSingleHostgroup) && empty ($idGlobalList[$monitorListSingleHostgroup] )) {
          $tmp = getHostgroupList($monitorListSingleHostgroup);
          // echo "HOSTGROUP LIST " . print_r($tmp) . "\n"; // DEBUG

          $idGlobalList["$monitorListSingleHostgroup"] = $tmp[0]['hostname'] ;
          $idTransient[$monitorListSingleHostgroup] = $idGlobalList[$monitorListSingleHostgroup] ;
        }
        // We have seen it before, do not call API again
        elseif ( ! empty ($monitorListSingleHostgroup) &&  empty($idTransient[$monitorListSingleHostgroup])  ) {
          $idTransient[$monitorListSingleHostgroup] = $idGlobalList[$monitorListSingleHostgroup];
        }
        else {
          // We know that the hostgroup is empty
          $junk=0;
        }
      } // end foreach 2

      // We now should have a idTransient array of hostids that we can glue together
      //echo print_r($idTransient) . "\n"; // DEBUG

      foreach ( $idTransient as $key => $value) {
        $idProgress .= "," .$value;
      } // end foreach 3
    } // end if we have hostgroups defined


    // echo "HOSTID " . $monitorListSingle['hostid']. "\n";  // DEBUG
    // Append any hostid to the hostgroup list
    if ( ! empty($monitorListSingle['hostid']) ) {
      $idProgress .= ',' . $monitorListSingle['hostid'];
    }
    // remove any extra commas that might get added due to glitches
    $idProgress=ltrim($idProgress, ',');
    $idProgress=rtrim($idProgress, ',');
    $idProgress=cleanStringUnique($idProgress);

    // Now get the hostname, address, Properties for the check
    $hostDetails = getHostnameList($idProgress);

    // If Properties were returned for the host, we need to change it from json to an array
    $retProp=array();
    foreach ($hostDetails as $cleanProperties) {
      if ( isset($cleanProperties['Properties']) && (! empty($cleanProperties['Properties']) || $cleanProperties['Properties'] !== "NULL") ) {
        $cleanProperties['Properties']=json_decode($cleanProperties['Properties'],True);
        $retProp[] = $cleanProperties;
      }
      else {
        $retProp[] = $cleanProperties;
      }
      $monitorListSingle['hostProperties'] = $retProp;
    }
    // All of that to create our monitoring array
    // print_r($monitorListSingle);
     $returnList[] = $monitorListSingle;
  } // end foreach 1
  return $returnList;
} // end function getMonitorHostDetails

function getMonitor($apiHost, $apiPort, $cycle) {
  $ch=curl_init();
  curl_setopt($ch, CURLOPT_URL, $apiHost . ":" . $apiPort . "/monitoringPoller/snmp?cycle=$cycle");
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  $output = curl_exec($ch) ;
  curl_close($ch);
  $output = json_decode($output, true);  // Convert from JSON to ARRAY type
  return $output['data'];
//  return $output;
}

function getHostgroupList($hostgroupName) {
  global $apiHost;
  global $apiPort;
  $ch=curl_init();
  curl_setopt($ch, CURLOPT_URL, $apiHost . ":" . $apiPort . "/monitoringPoller/hostgroup?hostgroup=" . $hostgroupName);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  $output = curl_exec($ch) ;
  curl_close($ch);
  $output = json_decode($output, true);  // Convert from JSON to ARRAY type

  //  echo "hostgroupName " . $hostgroupName . "\n";  // DEBUG
  //  echo "RESULT1 " . json_encode($output,1) . "\n";  // DEBUG
  //  echo "RESULT1.5 " . print_r($output) . "\n";  // DEBUG
  //  echo "RESULT2 " . json_decode($output,true) . "\n";  // DEBUG

  $pattern = '/[\[\]"]/i';
  if ( is_null($output['data'][0]['hostname'])) { $output['data'][0]['hostname'] = ''; }
  $output['data'][0]['hostname'] = preg_replace( $pattern, '', $output['data'][0]['hostname']);

  return $output['data'];
}

function getHostnameList($hostgroupList) {
  global $apiHost;
  global $apiPort;
  $ch=curl_init();
  curl_setopt($ch, CURLOPT_URL, $apiHost . ":" . $apiPort . "/monitoringPoller/hostname");
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_POST, 1);
  curl_setopt($ch, CURLOPT_POSTFIELDS, "idList=$hostgroupList" );
  $output = curl_exec($ch) ;
  curl_close($ch);
  $output = json_decode($output, true);  // Convert from JSON to ARRAY type
  return $output['data'];
}

function cleanString($inputString) {
  /*
    This is going to clean up adhoc
    strings passed to it and return a clean
    string to pass elsewhere.
  */
  $pattern = '/[\[\]"]/i';
  $inputString = preg_replace( $pattern, '',$inputString);
  $pattern = '/,,/';
  $inputString = preg_replace( $pattern, ',',$inputString);
  return $inputString;
}

function cleanStringUnique($inputString) {
  /*
    this is going to take csv and convert to array
    so that we can make sure we have unique id numbers.
    If we dont, then devices will get monitored twice
  */
  $cleanUp=explode(',', $inputString);
  $cleanUp=array_unique($cleanUp);
  $cleanUp=array_filter($cleanUp);
  $cleanUp=implode(",",$cleanUp);
  return $cleanUp;
}

function shellSnmpGet($address, $version, $community, $checkValue) {
  if ( $version == '2' ) { $version = '2c'; }
  $cmd="snmpget -Ovq -v " . $version . " -c " . $community . " " . $address . " " . $checkValue . " -t 5 -r 2 2>&1";
  // echo "CMD " . $cmd . "\n";  // DEBUG
  $result=exec( $cmd, $output, $result_code);
  $data['output'] = $output;
  $data['exitCode'] = $result_code;
  $data['command'] = $cmd;
  return $data;
}

// One will return raw oid numeric, and raw values without things like (enabled) 1 in the results
function shellSnmpWalk($address, $version, $community, $checkValue) {
if ( empty ($version) ) {
  echo "FAIL " . $address . " " . $checkValue . "\n";
}
  if ( $version == '2' ) { $version = '2c'; }
  $cmd="snmpwalk -Onq -v " . $version . " -c " . $community . " " . $address . " " . $checkValue  . " -t 5 -r 2 2>&1";
  // echo "CMD " . $cmd . "\n"; // DEBUG
  $result=exec( $cmd, $output, $result_code);
  $output = preg_replace('/[ ]/',' : ', $output, 1);
  $data['output'] = $output;
  $data['exitCode'] = $result_code;
  $data['command'] = $cmd;
  return $data;
}

function shellNrpe($nrpePath, $address, $checkValue) {
  global $logger;
  $cmd="$nrpePath -H $address -c $checkValue 2>&1";
  eval("\$cmd = \"$cmd\";");
  $result=exec( $cmd, $output, $result_code);
  $data['output'] = $output;
  $data['exitCode'] = $result_code;
  $data['command'] = $cmd;
  $logger->debug("NRPE CHECK " . json_encode($data,1));
  return $data;
}

// NRPE or nagios style results need cleaned for use in performance
function cleanNrpeMetrics($nrpeResult) {
  global $logger;
  // OK - 53.5% (17552100 kB) free.|TOTAL=32780696KB;;;; USED=15228596KB;29502626;31141661;; FREE=17552100KB;;;; CACHES=15847980KB;;;;
  // First see if there are metrics Nagios style.  If there is a | then
  // there are likely metrics.  STRIP out all semicolons, for metrics we dont
  // need to warn and crit values, as they are defined in the check itself.
  // echo "RAW " . $nrpeResult . "\n"; // DEBUG
  $initialSplit=preg_split('/\|/', $nrpeResult,2);
  //echo "RESULT " . print_r($initialSplit) . "\n"; // DEBUG
  $normalOutput=$initialSplit[0];

  if ( isset($initialSplit[1] ) && $initialSplit[1] !== '') {
    // If there are no = signs we cannot even attempt to parse perf
    if ( ! substr_count($initialSplit[1], '=') ) {
      $nrpeResults['perf'] = "false";
    }
    else {
      $nrpeResults['perf'] = "true";
    }
    $perfOutput=$initialSplit[1];
    $perfOutput=ltrim(rtrim($perfOutput));

    // Nagios has rules, follow them to explode an array out
    $tempArray=explode(' ', $perfOutput);
    $cleanResult=array();
    foreach ($tempArray as $cleanArray) {
      $cleanArray=preg_replace('/;.*./','', $cleanArray);  // Strip the ; until EOL
      if ( empty($cleanResult)) { $cleanResult = ''; }
      $cleanResult=preg_replace('/[ ]/','',$cleanResult);  // strip white space
      $cleanResult=(preg_split('/=/',$cleanArray));        // Create array from = 
      $patternSlash= '/\//';
      if ( ! is_null ($cleanResult[0])) {
        $cleanResult[0]=preg_replace($patternSlash,'_', $cleanResult[0]);   // change / to underbar for names (think /etc to _etc)
        $cleanResult[0]=ltrim(rtrim($cleanResult[0]));       // again strip whitespace
      }
      if ( ! is_null ($cleanResult[1])) {
        $cleanResult[1]=preg_replace('/[[:alpha:]]/','', $cleanResult[1]);  // strip everything except numbers
        $cleanResult[1]=ltrim(rtrim($cleanResult[1]));       // again strip whitespace
      }
      if ( ! empty($cleanResult[0]) ||  $cleanResult[1] !== "" ) {
        // echo "NAME " . $cleanResult[0] . " METRIC VALUE " . $cleanResult[1] . "\n";  // DEBUG
        $nrpeResults['data'][$cleanResult[0]] = $cleanResult[1];
      }
      unset($cleanResult);
    }
  }
  else {
    // There are no perf metrics to return
    $nrpeResults['perf'] = "false";
    $nrpeResults['data'] = "";
  }
  $nrpeResults['output'] = $normalOutput;
  if ( empty($nrpeResults['data'])) {
    $nrpeResults['perf'] = "false";
  }
  //print_r($nrpeResults);  // DEBUG
  $logger->debug("NRPE CHECK METRICS " . json_encode($nrpeResults,1));

  return $nrpeResults;
}

function shellShell($hostname, $address, $checkValue) {
  // In theory it should take either hostname or address and add
  // to the template command in checkValue
  // $checkValue="ping $hostname -c 4 -q";
  $cmd=$checkValue;
  eval("\$cmd = \"$cmd\";");
  $result=exec( $cmd, $output, $result_code);
  return $data;
}

function shellPing($address) {
  $cmd="ping $address -c 4 -q";
  eval("\$cmd = \"$cmd\";");
  $result=exec( $cmd, $output, $result_code);
  $data['output'] = $output;
  $data['exitCode'] = $result_code;
  $data['command'] = $cmd;
  return $data;
}

// Due to what damage it can do, we are only
// going to give it hostname and IP address
function shellAlive($hostname, $address, $command) {
  $cmd=$command;
  eval("\$cmd = \"$cmd\";");

  $result=exec( $cmd, $output, $result_code);
  $data['output'] = $output;
  $data['exitCode'] = $result_code;
  $data['command'] = $cmd;
  return $data;
}


//function sendPollerPerformance($hostname, $metricData, $metricName, $metricAction = null, $type, $cycle = null) {
function sendPollerPerformance($hostname, $metricData, $metricName, $type, $metricAction = null, $cycle = null) {
  if (is_null ($cycle)) {
    $metricNameFull=explode('-',$metricName);
    $cycle=$metricNameFull[1];
  }

  // require_once __DIR__ . "/../../../../templates/generalMetricSaver.php";
  if (is_array($metricData)) {
    $metricData2=json_encode($metricData,1);
  }
  else {
    // safety check to make certain we are working with JSON
    $metricData2=$metricData;
  }
  $sent=sendMetricToGraphite($hostname, $metricData2, $metricName, null, $type, null);
  $sent2=sendMetricToFile($hostname, $metricData2, $metricName, null, $type , null);
  $sent3=sendMetricToRrd($hostname, $metricName2, $metricData, null, $type, $cycle);
  if ($sent == 1) { return 1; }
  return 0;
}

function sendPerformanceDatabase($hostname, $action, $value) {
  global $apiHost;
  global $apiPort;

  $sendPerformance = new Curl();
  $sendPerformance->url = $apiHost . ":" . $apiPort . "/monitoringPoller/savePerformance";
  $sendPerformance->method = "post";
  $sendPerformanceValues['hostname'] = $hostname;
  $sendPerformanceValues['checkName']   = $action;
  $sendPerformanceValues['value']    = $value;
  $sendPerformance->data($sendPerformanceValues);
  $sendPerformance->send();
  $sendPerformance->close();
  $sendPerformanceResult=json_decode(json_encode($sendPerformance,1),1);
  if ( strpos($sendPerformanceResult['content'], "200") !== false) {
    return 0;
  }
  else {
    return "Did not recieve a 200 response when calling method sendPerformanceDatabase in daemonFuctions.php" . print_r($sendPerformance);
  }
  unset($sendPerformance);
}

function sendEvents($completeResultSingle) {
  global $logger;
  global $iterationCycle;  // calculate retention period
  if ( $iterationCycle <= 120 ) {
    $expire=($iterationCycle * 40);
  }
  else {
    $expire=($iterationCycle * 4);
  }

  $severity=3;
  // NRPE exclusive return values from check
  if ($completeResultSingle['type'] == "nrpe") {
    switch($completeResultSingle['exitCode']) {
      case "1":
        $severity="4";
        break;
     case "2":
        $severity="5";
        break;
     case "3":
        $severity="3";
        break;
     default:
        break;
    }
  }
  // Clean up the stdout from NRPE results..  Gah... fix this earlier
  if ($completeResultSingle['type'] == "nrpe") {
    //$logger->debug("DEBUG -- 0a -- SHELL checking sendComplete " . $completeResultSingle['output']);
    //$logger->debug("DEBUG -- 0b -- SHELL checking sendComplete " . $completeResultSingle['output'][0]);
    if ( ! empty($completeResultSingle['output'])) {
      $cleanout = json_decode($completeResultSingle['output'], true);
      //$logger->debug("DEBUG -- 1a -- SHELL Changed sendComplete Once");
      if ( ! is_null($cleanout[0])) {
        $completeResultSingle['output'] = $cleanout[0];
        //$logger->debug("DEBUG -- 1c -- SHELL Changed sendComplete value " . $completeResultSingle['output']);
      }
    }
  }

  if ($completeResultSingle['exitCode'] > 0) {
    // Seems a bit unpredictable with the stdout in output.  Inconsistent results here depending on what
    // is being called.  This will require more debugging


    // $logger->debug("DEBUG SHELL STDOUT " . json_encode($completeResultSingle['output'],1));
    //    sendHostAlarm("Poller command ". $completeResultSingle['command']. " ", $severity, $completeResultSingle['checkName'], $completeResultSingle['hostname'], $expire, json_encode($completeResultSingle['output'],1));
    if ( ! is_array($completeResultSingle['output'][0]) || empty($completeResultSingle['output'][0])|| ! isset($completeResultSingle['output'][0])) { $sendComplete = json_encode($completeResultSingle['output'],1) ; }
    else { $sendComplete = $completeResultSingle['output'][0]; }
    //$logger->debug("DEBUG -- 2 --  SHELL STDOUT " . $sendComplete);
    //$cleanup01 = json_decode($sendComplete, true);
    //$cleanup01 = json_decode($cleanup01, true);
    //$logger->debug("DEBUG -- 3 --  SHELL STDOUT " . $cleanup01[0]);
    sendHostAlarm("Check Failure exit code ". $sendComplete . " ", $severity, $completeResultSingle['checkName'], $completeResultSingle['hostname'], $expire, json_encode($completeResultSingle['output'],1));
    $logger->info("Sending set event for " . $completeResultSingle['hostname'] . " service check " . $completeResultSingle['checkName']);
  }
  elseif (preg_match('/Timeout/',$completeResultSingle['output'][0])) {
    //    sendHostAlarm("Poller command ". $completeResultSingle['command']. " failed. ", $severity, $completeResultSingle['checkName'], $completeResultSingle['hostname'], $expire,json_encode($completeResultSingle['output'],1));
    sendHostAlarm("Check Failure timeout ". $completeResultSingle['output'][0] . " failed. ", $severity, $completeResultSingle['checkName'], $completeResultSingle['hostname'], $expire,json_encode($completeResultSingle['output'],1));
    $logger->info("Sending set event for " . $completeResultSingle['hostname'] . " service check " . $completeResultSingle['checkName']);
  }
  elseif (preg_match('/No Such Object available/',$completeResultSingle['output'][0])) {
    //    sendHostAlarm("Poller command ". $completeResultSingle['command']. " failed. ", $severity, $completeResultSingle['checkName'], $completeResultSingle['hostname'], $expire,json_encode($completeResultSingle['output'],1));
    sendHostAlarm("Check Failure no such object ". $completeResultSingle['output'][0] . " failed. ", $severity, $completeResultSingle['checkName'], $completeResultSingle['hostname'], $expire,json_encode($completeResultSingle['output'],1));
    $logger->info("Sending set event for " . $completeResultSingle['hostname'] . " service check " . $completeResultSingle['checkName']);
  }
  elseif ( empty($completeResultSingle['output'][0]) && $completeResultSingle['type'] !== "nrpe") {
    sendHostAlarm("Poller command ". $completeResultSingle['output'][0] . " failed. No results returned.", $severity, $completeResultSingle['checkName'], $completeResultSingle['hostname'], $expire);
    $logger->info("Sending set event for " . $completeResultSingle['hostname'] . " service check " . $completeResultSingle['checkName']);
  }
  $completeResultSingle=array(); // Clear the varaible as we are done
  return 0;
}

function clearEvents($activeEventsFull, $completeResultSingle) {
  global $logger;
  foreach ($activeEventsFull['data'] as $activeEventsArray) {
    //     print_r($activeEventsArray);  // DEBUG
    //    echo "Active Alarm " . $activeEventsArray['device'] . " event name " . $activeEventsArray['eventName'] .  " MATCH ME " . $completeResultSingle['hostname'] . " MONITOR " . $value . " MATCH ". $completeResultSingle['checkName'] . " with exit code of " . $completeResultSingle['exitCode'] . "\n";  // DEBUG
    if ( $activeEventsArray['device'] == $completeResultSingle['hostname'] && $activeEventsArray['eventName'] == $completeResultSingle['checkName'] && $completeResultSingle['exitCode'] == 0 ) {
      // echo "****************** MATCH FOUND!!!!!" . "\n";
      sendHostAlarm("Poller command ". $completeResultSingle['command']. " success. ", 0, $completeResultSingle['checkName'], $completeResultSingle['hostname'], 3600,json_encode($completeResultSingle['output'],1));
      $logger->info("Sending Clear event for " . $completeResultSingle['hostname'] . " service check " . $completeResultSingle['checkName']);
    } // end if
  } // end foreach
  $completeResultSingle=array();  // Clear the varaible as we are done
  return 0;
}

function storageForward($completeResultSingle) {
  global $logger;          // Log our results if necessary
  global $iterationCycle;  // rrd needs this value
  //  if ( empty($completeResultSingle['output']) ) {
  if ( ! array_key_exists('output',$completeResultSingle) ) {
    $logger->info("Bypass recording metrics as there are none.  Output is empty for host " . $completeResultSingle['hostname'] . " check name as " . $completeResultSingle['checkName']);
    $sent = 0;
  }
  // grapite and databaseMetrics rely on templates to exist
  switch ($completeResultSingle['storage']) {
    case "graphite":
      $finalOutput=array();
      // double quotes, and return seem to do bad things to the array,  Initially focus on quotes for cleanup and see if that is enough
      // $completeResultSingle['output'] = preg_replace('/[\x00-\x1F\x7F]/u','',$completeResultSingle['output']); // might bite in the future
      // $completeResultSingle['output'] = preg_replace('/[[:cntrl:]]/','',$completeResultSingle['output']);  // might bite in the future
      // print_r($completeResultSingle['output']); // DEBUG
      if ( $completeResultSingle['type'] !== "nrpe" ) {
        foreach ($completeResultSingle['output'] as $monOutArray) {
          // $monOutArray=preg_replace('/[[:cntrl:]]/','',$monOutArray);  // might bite in the future
          $monOutArray=preg_replace('/"/','',$monOutArray);
          $messyOutput=preg_split('/ /',$monOutArray,2);
          if ( ! empty($messyOutput[0])) {
            // band-aid until we get snmp returns to be 100% uniform with only oid numeric values in the templates  // FUTURE
            $cleanOid=preg_replace( '/^.1/', 'iso' , $messyOutput[0]);
            if (empty($messyOutput[1])) { $messyOutput[1] = " "; }
            $cleanResult=strstr($messyOutput[1],': ');
            $cleanResult=preg_replace('/^:/', '', $cleanResult);
            //echo "OID " . $cleanOid . " VALUE " . $cleanResult . "\n"; // DEBUG
            $finalOutput[$cleanOid] = $cleanResult;
          }
        }
      }
      else {
        $finalOutput = $completeResultSingle['output'];
      }
      //print_r($finalOutput);  // DEBUG
      // echo json_encode($finalOutput,true) . "\n\n";  // DEBUG
      // exit(); // DEBUG

      // NRPE will return metrics, however there is no distinct template per check as the format is uniform (hopefully)
      // We have to inform the function to use generic nrpe template when apprioriate to do so
      if ( $completeResultSingle['type'] == "nrpe" ) {
        // echo "TYPE " . $completeResultSingle['type'] . " ACTION " . $completeResultSingle['checkName'] . " OUTPUT " . json_encode($finalOutput,true) . "\n"; // DEBUG
        $logger->debug("NRPE storageForward Graphite " . json_encode($completeResultSingle,1));

        $sent=sendMetricToGraphite($completeResultSingle['hostname'], json_encode($finalOutput,true),$completeResultSingle['checkName'], null, $completeResultSingle['type'], null);
      }
      elseif ($completeResultSingle['type'] == "walk" || $completeResultSingle['type'] == "get" || $completeResultSingle['type'] == "snmp") {
        // echo "GRAPHITE NON NRPE JSON TYPE " . $completeResultSingle['type'] . " HOSTNAME " . $completeResultSingle['hostname'] . " VALUE " . $completeResultSingle['checkAction'] . " OUTPUT CUT TOO BIG \n"; // DEBUG
        $sent=sendMetricToGraphite($completeResultSingle['hostname'], json_encode($finalOutput,true), $completeResultSingle['checkName'],$completeResultSingle['checkAction'], $completeResultSingle['type'] , null);
      }
      else {
        // echo "GRAPHITE NON NRPE JSON TYPE " . $completeResultSingle['type'] . " HOSTNAME " . $completeResultSingle['hostname'] . " VALUE " . $completeResultSingle['checkAction'] . " OUTPUT CUT TOO BIG \n"; // DEBUG
        $sent=sendMetricToGraphite($completeResultSingle['hostname'], json_encode($finalOutput,true), $completeResultSingle['checkName'],$completeResultSingle['checkAction'], $completeResultSingle['type'], null);
      }
      break;
    case "databaseMetric":
      if ( $completeResultSingle['type'] == "snmp" || $completeResultSingle['type'] == "walk" || $completeResultSingle['type'] == "get" ) {
        foreach ($completeResultSingle['output'] as $monOutArray) {
          // $monOutArray=preg_replace('/[[:cntrl:]]/','',$monOutArray);  // might bite in the future
          $monOutArray=preg_replace('/"/','',$monOutArray);
          $messyOutput=preg_split('/ /',$monOutArray,2);
          if ( ! empty($messyOutput[0])) {
            // band-aid until we get snmp returns to be 100% uniform with only oid numeric values in the templates  // FUTURE
            $cleanOid=preg_replace( '/^.1/', 'iso' , $messyOutput[0]);
            $cleanResult=strstr($messyOutput[1],': ');
            $cleanResult=preg_replace('/^:/', '', $cleanResult);
            // echo "OID " . $cleanOid . " VALUE " . $cleanResult . "\n"; // DEBUG
            $finalOutput[$cleanOid] = $cleanResult;
          }
        }
      }
      else {
        $finalOutput = $completeResultSingle['output'];
      }
      // JSON encode the array from the output
      // echo "DB JSON TYPE " . $completeResultSingle['type'] . " HOSTNAME " . $completeResultSingle['hostname'] . " VALUE " . $completeResultSingle['checkAction'] . " OUTPUT CUT TOO BIG \n"; // DEBUG
      // print_r($completeResultSingle['output']); // DEBUG
      if ( $completeResultSingle['type'] == "snmp" || $completeResultSingle['type'] == "walk" || $completeResultSingle['type'] == "get" ) {
        $sent=sendMetricToDatabase($completeResultSingle['hostname'], json_encode($finalOutput,1), $completeResultSingle['checkName'], $completeResultSingle['checkAction'], $completeResultSingle['type'], null);
      }
      else {
        // This will only work with a template file named template_$completeResultSingle['checkAction'].php
        $sent=sendMetricToDatabase($completeResultSingle['hostname'], json_encode($finalOutput,1), $completeResultSingle['checkName'], $completeResultSingle['checkAction'], $completeResultSingle['type'], null);
      }
      break;
    case "database":
      // We are only returning as a SINGLE string into the database, no need to mess with array
      // print_r($completeResultSingle); // DEBUG
      $sent=sendPerformanceDatabase($completeResultSingle['hostname'], $completeResultSingle['checkName'], $completeResultSingle['output'][0]);
      break;
    case "rrd":
      if ( $completeResultSingle['type'] !== "nrpe" ) {
        foreach ($completeResultSingle['output'] as $monOutArray) {
          // $monOutArray=preg_replace('/[[:cntrl:]]/','',$monOutArray);  // might bite in the future
          $monOutArray=preg_replace('/"/','',$monOutArray);
          $messyOutput=preg_split('/ /',$monOutArray,2);
          if ( ! empty($messyOutput[0])) {
            // band-aid until we get snmp returns to be 100% uniform with only oid numeric values in the templates  // FUTURE
            $cleanOid=preg_replace( '/^.1/', 'iso' , $messyOutput[0]);
            if ( empty($messyOutput[1])) { $messyOutput[1] = ' '; }
            $cleanResult=strstr($messyOutput[1],': ');
            $cleanResult=preg_replace('/^:/', '', $cleanResult);
            //echo "OID " . $cleanOid . " VALUE " . $cleanResult . "\n"; // DEBUG
            $finalOutput[$cleanOid] = $cleanResult;
          }
        }
      }
      else {
        $finalOutput = $completeResultSingle['output'];
      }
      //print_r($finalOutput);
      $sent=sendMetricToRrd($completeResultSingle['hostname'], json_encode($finalOutput,1), $completeResultSingle['checkName'], $completeResultSingle['checkAction'], $completeResultSingle['type'], $iterationCycle);
      break;
    case "influxdb":
      // require_once __DIR__ . "/../../templates/sendMetricToInfluxdb.php";
      $sent=sendPerformanceDatabase($completeResultSingle['hostname'], json_encode($completeResultSingle['output'],1), $completeResultSingle['checkName'], $completeResultSingle['checkAction'], $completeResultSingle['type'], null);
      break;
    case "file":
      // print_r($completeResultSingle['output']); // DEBUG
      if ( $completeResultSingle['type'] !== "nrpe" ) {
        foreach ($completeResultSingle['output'] as $monOutArray) {
          // $monOutArray=preg_replace('/[[:cntrl:]]/','',$monOutArray);  // might bite in the future
          $monOutArray=preg_replace('/"/','',$monOutArray);
          $messyOutput=preg_split('/ /',$monOutArray,2);
          if ( ! empty($messyOutput[0])) {
            // band-aid until we get snmp returns to be 100% uniform with only oid numeric values in the templates  // FUTURE
            $cleanOid=preg_replace( '/^.1/', 'iso' , $messyOutput[0]);
            $cleanResult=strstr($messyOutput[1],': ');
            $cleanResult=preg_replace('/^:/', '', $cleanResult);
            //echo "OID " . $cleanOid . " VALUE " . $cleanResult . "\n"; // DEBUG
            $finalOutput[$cleanOid] = $cleanResult;
          }
        }
      }
      else {
        $finalOutput = $completeResultSingle['output'];
      }
      $sent=sendMetricToFile($completeResultSingle['hostname'], json_encode($finalOutput,1), $completeResultSingle['checkName'], $completeResultSingle['checkAction'], $completeResultSingle['type'], null);
      break;
   case "debugger":
      if ( $completeResultSingle['type'] !== "nrpe" ) {
        foreach ($completeResultSingle['output'] as $monOutArray) {
          // $monOutArray=preg_replace('/[[:cntrl:]]/','',$monOutArray);  // might bite in the future
          $monOutArray=preg_replace('/"/','',$monOutArray);
          $messyOutput=preg_split('/ /',$monOutArray,2);
          if ( ! empty($messyOutput[0])) {
            // band-aid until we get snmp returns to be 100% uniform with only oid numeric values in the templates  // FUTURE
            $cleanOid=preg_replace( '/^.1/', 'iso' , $messyOutput[0]);
            $cleanResult=strstr($messyOutput[1],': ');
            $cleanResult=preg_replace('/^:/', '', $cleanResult);
            //echo "OID " . $cleanOid . " VALUE " . $cleanResult . "\n"; // DEBUG
            $finalOutput[$cleanOid] = $cleanResult;
          }
        }
      }
      else {
        $finalOutput = $completeResultSingle['output'];
      }
      $sent=sendMetricToDebugger($completeResultSingle['hostname'], json_encode($finalOutput,1), $completeResultSingle['checkName'], $completeResultSingle['checkAction'], $completeResultSingle['type'], $iterationCycle);
      $logger->info("Service check " . $completeResultSingle['checkName'] ." of type " . $completeResultSingle['type'] . " raw data sent to debugger file.");
      break;
   case "none":
      $sent=0;
      $logger->debug("Service check " . $completeResultSingle['checkName'] ." of type " . $completeResultSingle['type'] . " is not storing any metric data.");
      break;
    default:
      $logger->error("Service check " . $completeResultSingle['checkName'] ." of type " . $completeResultSingle['type'] . " does not have a known storage type " . $completeResultSingle['storage']);
      break;
  } // end switch
  if ($sent !== 0) { $logger->error("Failed to send metric to " . $completeResultSingle['storage']. " for " . $completeResultSingle['hostname'] . " monitor " .  $completeResultSingle['checkName'] . " Detail from call: " . $sent); }
  if ($sent == 0) { $logger->debug("Sent metric to " . $completeResultSingle['storage']. " for " . $completeResultSingle['hostname'] . " monitor " .  $completeResultSingle['checkName'] . ""); }
  $completeResultSingle=array(); // clear out the array after use
}

function routeProtocol($number) {
  switch ($number) {
    case "14":
      return "bgp"; break ;
    case "13":
      return "ospf"; break ;
    case "12":
      return "bbnSfIgp"; break ;
    case "11":
      return "ciscoIgrp"; break ;
    case "10":
      return "es-is"; break ;
    case "9":
      return "is-is"; break ;
    case "8":
      return "rip"; break ;
    case "7":
      return "hello"; break ;
    case "6":
      return "ggp"; break ;
    case "5":
      return "egp"; break ;
    case "4":
      return "icmp"; break ;
    case "3":
      return "netmgmt"; break ;
    case "2":
      return "local"; break ;
    case "1":
      return "other"; break ;
    default:
      return "$number"; break ;
  }
}

function convertIfType($type) {
  switch ($type) {
    case "other*": $ifType=1 ; break ;
    case "regular1822*": $ifType=2 ; break;
    case "hdh1822*": $ifType=3 ; break;
    case "ddn-x25*": $ifType=4 ; break;
    case "rfc877-x25*": $ifType=5 ; break;
    case "ethernet-csmacd*": $ifType=6 ; break;
    case "iso88023-csmacd*": $ifType=7 ; break;
    case "iso88024-tokenBus*": $ifType=8 ; break;
    case "iso88025-tokenRing*": $ifType=9 ; break;
    case "iso88026-man*": $ifType=10 ; break;
    case "starLan*": $ifType=11 ; break;
    case "proteon-10Mbit*": $ifType=12 ; break;
    case "proteon-80Mbit*": $ifType=13 ; break;
    case "hyperchannel*": $ifType=14 ; break;
    case "fddi*": $ifType=15 ; break;
    case "lapb*": $ifType=16 ; break;
    case "sdlc*": $ifType=17 ; break;
    case "ds1*": $ifType=18 ; break;
    case "e1*": $ifType=19 ; break;
    case "basicISDN*": $ifType=20 ; break;
    case "primaryISDN*": $ifType=21 ; break;
    case "propPointToPointSerial*": $ifType=22 ; break;
    case "ppp*": $ifType=23 ; break;
    case "softwareLoopback*": $ifType=24 ; break;
    case "eon*": $ifType=25 ; break;
    case "ethernet-3Mbit*": $ifType=26 ; break;
    case "nsip*": $ifType=27 ; break;
    case "slip*": $ifType=28 ; break;
    case "ultra*": $ifType=29 ; break;
    case "ds3*": $ifType=30 ; break;
    case "sip*": $ifType=31 ; break;
    case "frame-relay*": $ifType=32 ; break;
    default: $ifType=255 ; break;
  }
  return $ifType;
}

function verifyPidFileRunning($pidFile) {
  if ( ! file_exists($pidFile) || ! is_file($pidFile)) return "pidfile is missing";
  $pid = intval(file_get_contents($pidFile));
  if ( ! file_exists("/proc/$pid") || ! is_dir("/proc/$pid") )   return "/proc does not show pid running.  Supposed to be " . $pid . " from file.";
  return "running";
}

function verifyPid($pid) {
  $pid = ltrim(rtrim($pid));
  if ( ! file_exists("/proc/$pid") || ! is_dir("/proc/$pid") )   return "/proc does not show pid running for pid number " . $pid;
  return "running";
}

function findAllEvents() {
  global $apiHost;
  global $apiPort;
  $findAllEvents = new Curl();
  $findAllEvents->url = $apiHost . ":" . $apiPort . "/events";
  $findAllEvents->method = "get";
  $findAllEvents->send();
  $data = $findAllEvents->content();
  $findAllEvents->close();
  unset($findAllEvents);
  if ( ! is_array($data)) { $data = json_decode($data,1); }
  return $data['data'];
}

function findAgeOutEvents() {
  global $apiHost;
  global $apiPort;
  $ageOutEvents = new Curl();
  $ageOutEvents->url = $apiHost . ":" . $apiPort . "/events/ageOut";
  $ageOutEvents->method = "get";
  $ageOutEvents->send();
  $data = $ageOutEvents->content();
  $ageOutEvents->close();
  unset($ageOutEvents);
  if ( ! is_array($data)) { $data = json_decode($data,1); }
  return $data['data'];
}

function moveToHistory($id, $reason) {
  global $apiHost;
  global $apiPort;
  $moveEvents = new Curl();
  $moveEvents->url = $apiHost . ":" . $apiPort . "/events/moveToHistory";
  $moveEvents->method = "post";
  $sendMoveEvents['reason']    = $reason;
  $sendMoveEvents['id']    = $id;
  $moveEvents->data($sendMoveEvents);
  $moveEvents->send();
  $data = $moveEvents->content();
  $moveEvents->close();
  unset($moveEvents);
  if ( ! is_array($data)) { $data = json_decode($data,1); }
  return $data;
}

function getHeartbeats() {
  global $apiHost;
  global $apiPort;
  $getHeartbeat = new Curl();
  $getHeartbeat->url = $apiHost . ":" . $apiPort . "/monitoringPoller/housekeeping";
  $getHeartbeat->method = "post";
  $getHeartbeat->send();
  $data = $getHeartbeat->content();
  $getHeartbeat->close();
  unset($getHeartbeat);
  if ( ! is_array($data)) { $data = json_decode($data,1); }
  return $data['data'];
}

function findMaintenance() {
  global $apiHost;
  global $apiPort;
  $findMaintenance = new Curl();
  $findMaintenance->url = $apiHost . ":" . $apiPort . "/maintenance/find";
  $findMaintenance->method = "get";
  $findMaintenance->send();
  $data = $findMaintenance->content();
  $findMaintenance->close();
  unset($findMaintenance);
  if ( ! is_array($data)) { $data = json_decode($data,1); }
  return $data['data'];
}

// Generic file cleanup
function deleteOldFiles($dir, $days) {
  global $logger;
  $now = time(); // Current time
  $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
  foreach ($iterator as $file) {
    if ($file->isDir()) {
      continue; // Skip directories
    }
    if (($now - $file->getMTime()) > ($days * 86400)) { // 86400 seconds in a day
      $logger->info("file has gone " . $days . " days without an update.  Removing stale file " . $file->getRealPath());
      unlink($file->getRealPath()); // Delete file
    }
  }
}

// This is simple with WC on the end.. not as useful as the deleteOldFilesWildcard2
function deleteOldFilesWildcard($dir, $days) {
  global $logger;
  $now = time(); // Current time
  $dirs = glob($dirPattern, GLOB_ONLYDIR);
  foreach ($dirs as $dir) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($iterator as $file) {
      if ($file->isDir()) {
        continue; // Skip directories
      }
      if (($now - $file->getMTime()) > ($days * 86400)) { // 86400 seconds in a day
      $logger->info("file has gone " . $days . " days without an update.  Removing stale file " . $file->getRealPath());
      unlink($file->getRealPath()); // Delete file
      }
    }
  }
}

function deleteOldFilesWildcard2($dirPattern, $days) {
  global $logger;
  $now = time(); // Current time
  // Use GLOB_BRACE if your environment supports it for more complex patterns
  $paths = glob($dirPattern, GLOB_BRACE | GLOB_ONLYDIR);
  foreach ($paths as $path) {
    if (is_dir($path)) {
      $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
      foreach ($iterator as $fileinfo) {
        if ($fileinfo->isDir()) {
          continue; // Optionally, handle directory deletion here
        }
        else {
          if (($now - $fileinfo->getMTime()) > ($days * 86400)) {
            $logger->info("file has gone " . $days . " days without an update.  Removing stale file " . $fileinfo->getRealPath());
            unlink($fileinfo->getRealPath()); // Delete file
          }
        }
      }
    }
  }
}

function housekeepingDatabaseClean($days) {
  global $apiHost;
  global $apiPort;
  global $logger;
  global $apiKey;
  $cleanPerf = new Curl();
  $cleanPerf->url = $apiHost . ":" . $apiPort . "/monitoringPoller/deletePerformance";
  $cleanPerf->method = "post";
  $cleanPerfTime['days'] = $days;
  $cleanPerf->data($cleanPerfTime);
  $cleanPerf->headers = ["X-Api-Key: $apiKey"];
  $cleanPerf->send();
  $data = $cleanPerf->content();
  $cleanPerf->close();
  unset($cleanPerf);
  // This is annoying, but the json simply did not want to format correctly
  if ( is_array($data)) {
    $data = json_encode($data,1);
  }
  else {
    $data=json_encode(json_decode($data,true),1);
  }
  $logger->info("Database performance table cleanup result " . $data);
}

?>
