<?php
/*
  Utility functions to pull random stuff from RRD that is outside of
  create, update, graph.  Likely this will all get rolled up into a single
  set of functions later once the system is working better.
*/

// Manual parsing from shell output for the lastupdate rrdtool command
function returnLastUpdateManual($file) {
  $cmd="rrdtool lastupdate $file";
  $result=exec($cmd, $output, $exitCode);
  if ( $exitCode == 0) {
    $names=ltrim(rtrim($output[0])); // Clean the raw input to not have leading or trailing spaces
    $names=explode(" ", $names);
    $values=end($output);
    $dateStamp=preg_replace('/:.*./','', end($output));
    $values=preg_replace('/.*.:/','', $values);
    $values=ltrim(rtrim($values));
    $values=explode(" ", $values);
    $result = array_combine($names, $values);
    $result['dateStamp'] = $dateStamp;
    return $result;
    // print_r($result);
  }
  else {
    return "failure";
  }
}
// var_dump(returnLastUpdateManual('/opt/nmsApi/templates/render/../../rrd/guyver-office.iwillfearnoevil.com/snmp/interfaces/Realtek_Semiconductor_Co___Ltd__RTL8111_8168_8411_PCI_Express_Gigabit_Ethernet_Controller_32.rrd'));

// Cleans the returned RRD data into a key => value array with dateStamp
// use with builtin rrd_lastupdate only
function returnLastUpdate($arr) {
  if ( is_array($arr)) {
    $dateStamp=$arr['last_update'];
    $names=$arr['ds_navm'];
    $values=$arr['data'];
    $result = array_combine($names, $values);
    $result['dateStamp'] = $dateStamp;
    // print_r($result);
    return $result;
  }
  else {
    return "failure";
  }
}
//$foo=rrd_lastupdate('/opt/nmsApi/templates/render/../../rrd/guyver-office.iwillfearnoevil.com/snmp/interfaces/Realtek_Semiconductor_Co___Ltd__RTL8111_8168_8411_PCI_Express_Gigabit_Ethernet_Controller_32.rrd');
//$bar=returnLastUpdate($foo);
//print_r($bar);

// Retrieve rrd data that would be used for a graph as an array
// Would be useful if someone wanted to graph client side
function returnRawValues($file, $opts) {
  if (empty($opts)) {
    $opts=array( "AVERAGE", "--resolution", "300", "--start", "-1h" );
    $result = rrd_fetch( $file, $opts );
    if ( ! is_array($result)) {
      return "failure"; 
    }
    else {
      //print_r($result);
      return $result;
    }
  }
}
//var_dump(returnRawValues('/opt/nmsApi/templates/render/../../rrd/guyver-office.iwillfearnoevil.com/snmp/interfaces/Realtek_Semiconductor_Co___Ltd__RTL8111_8168_8411_PCI_Express_Gigabit_Ethernet_Controller_32.rrd', ''));

// Testing limits of shell exec hidden by rrd_graph
function graphMe($file, $args) {
  $ret2 = rrd_graph($file, $args);
  if(!is_array($ret2) ) {
    $err = rrd_error();
    echo "rrd_graph() ERROR: $err\n";
  }
}

function manualGraphMe($file, $args) {
  $cmd="rrdtool graph " . $file . " " . $args;  // necessary to not screw with quotes and slashes as much
  $result=exec($cmd, $output, $exitCode);
  if($exitCode !== 0 ) {
    $err = rrd_error();
    return "rrdtool graph ERROR: " . print_r($output);
  }
  else {
    return 0;
  }
}
// Silly, but we need to triple escape newline: \\\n
//$fil='/opt/nmsGui/public/event/enp2s0_2.jpg';
//$arg='--start -1d --vertical-label=B/s -w 500 DEF:inoctets=/opt/nmsApi/templates/render/../../rrd/guyver-office.iwillfearnoevil.com/snmp/interfaces/Realtek_Semiconductor_Co___Ltd__RTL8111_8168_8411_PCI_Express_Gigabit_Ethernet_Controller_32.rrd:ifInOctets:AVERAGE DEF:outoctets=/opt/nmsApi/templates/render/../../rrd/guyver-office.iwillfearnoevil.com/snmp/interfaces/Realtek_Semiconductor_Co___Ltd__RTL8111_8168_8411_PCI_Express_Gigabit_Ethernet_Controller_32.rrd:ifOutOctets:AVERAGE DEF:inoctets_max=/opt/nmsApi/templates/render/../../rrd/guyver-office.iwillfearnoevil.com/snmp/interfaces/Realtek_Semiconductor_Co___Ltd__RTL8111_8168_8411_PCI_Express_Gigabit_Ethernet_Controller_32.rrd:ifInOctets:MAX DEF:outoctets_max=/opt/nmsApi/templates/render/../../rrd/guyver-office.iwillfearnoevil.com/snmp/interfaces/Realtek_Semiconductor_Co___Ltd__RTL8111_8168_8411_PCI_Express_Gigabit_Ethernet_Controller_32.rrd:ifOutOctets:MAX CDEF:octets=inoctets,outoctets,+ CDEF:doutoctets=outoctets,-1,* CDEF:outbits=outoctets,8,* CDEF:outbits_max=outoctets_max,8,* CDEF:doutoctets_max=outoctets_max,-1,* CDEF:doutbits=doutoctets,8,* CDEF:doutbits_max=doutoctets_max,8,* CDEF:inbits=inoctets,8,* CDEF:inbits_max=inoctets_max,8,* VDEF:totin=inoctets,TOTAL VDEF:totout=outoctets,TOTAL VDEF:tot=octets,TOTAL VDEF:95thin=inbits,95,PERCENT VDEF:95thout=outbits,95,PERCENT VDEF:d95thout=doutbits,5,PERCENT AREA:inbits#92B73F LINE1.25:inbits#4A8328:"In " GPRINT:inbits:LAST:%6.2lf%s GPRINT:inbits:AVERAGE:%6.2lf%s GPRINT:inbits_max:MAX:%6.2lf%s GPRINT:95thin:%6.2lf%s\\\n AREA:doutbits#7075B8 LINE1.25:doutbits#323B7C:"Out" GPRINT:outbits:LAST:%6.2lf%s GPRINT:outbits:AVERAGE:%6.2lf%s GPRINT:outbits_max:MAX:%6.2lf%s GPRINT:95thout:%6.2lf%s\\\n GPRINT:tot:"Total %6.2lf%s" GPRINT:totin:"(In %6.2lf%s" GPRINT:totout:"Out %6.2lf%s)\l" LINE1:95thin#aa0000 LINE1:d95thout#aa0000';
//manualGraphMe($fil, $arg);


// Standardize our colors
function colorList($number) {
  $colors = array('#00cc00', '#0000ff', '#00ffff', '#ff0000','#ff9900', '#cc0000', '#0000cc', '#0080c0', '#8080c0', '#ff0080', '#800080', '#0000a0', '#408080', '#808000', '#000000', '#00ff00', '#fb31fb', '#0080ff', '#ff8000', '#800000');
  // Go backwards if we want inverted colors!
  if ( $number < 0 ) {
    $T = count($colors);
    $F = $T + $number;
    //    $colorResult=$colors[count($colors) $n];
    $colorResult=$colors[$F];
  }
  else {
    $colorResult = $colors[$number];
  }
  return $colorResult;
}

function isLoaded() {
  return "file loaded";
}
//$res=colorList(-1);
//echo $res;


?>
