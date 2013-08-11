#!/usr/bin/php
<?php
/**
 *  AGI script for performing operators Queues login and logout.
 */

//debug mode writes extra data to the log file below whenever an AGI command is executed
$debug = false; 

$stdlog = fopen('/var/log/asterisk/check_operator.log', 'a');

// Do function definitions before we start the main loop 
 
// Write debug info to console and to file
function errlog($line, $level = 1) {
    global $stdlog;
    if (!is_numeric($level)) {
        $level = 1;
    }
    fwrite(STDOUT, "VERBOSE \"$line\" $level \n");
    fflush(STDOUT);
    fputs($stdlog, date("d-m-y H:i") . " Debug: $line\n");
}

// Read data from STDIN
function read() {
    global $debug;
    $input = str_replace("\n", "", fgets(STDIN, 4096));
    if ($debug)
        errlog($input);
    return $input;
}

// Write data to STDOUT
function write($line) {
    global $debug;
    if ($debug)
        errlog($line);
    fwrite(STDOUT, $line . "\n");
    fflush(STDOUT);
}

function db_init() {
    $link = mysql_connect('localhost', 'asterisk', 'ast_pwd');
    if (!$link)
        die(mysql_error());
    mysql_select_db('asterisk');
    return $link;
}

function execute_agi($command) {
    write($command);
    $result = read();
    $ret = array('code' => -1, 'result' => -1, 'timeout' => false, 'data' => '');
    if (preg_match("/^([0-9]{1,3}) (.*)/", $result, $matches)) {
        $ret['code'] = $matches[1];
        $ret['result'] = 0;
        if (preg_match('/^result=([0-9a-zA-Z]*)(?:\s?\((.*?)\))?$/', $matches[2], $match)) {
            $ret['result'] = $match[1];
            $ret['timeout'] = ($match[2] === 'timeout') ? true : false;
            $ret['data'] = $match[2];
        }
    }
    return $ret;
}

// +++++++++++++++++++++++ HERE WE START ++++++++++++++++++++++++++++++++
// Get connected to MySQL
$db_link = db_init();

/*
  This script answers the call and prompts for an extension, provided your caller ID is approved.
  When it receives 4 digits it will read them back to you and hang up.
 */

// parse agi headers into $agi array 
while ($env = read()) {
    $s = split(": ", $env);
    $agi[str_replace("agi_", "", $s[0])] = trim($s[1]);
    if (($env == "") || ($env == "\n")) {
        break;
    }
}

// Lets ask for personal code
$gotcode = FALSE;
$i = 0;
while ($gotcode != TRUE) {
    $i++;
    // Ask for operator code 3 times
    $result = execute_agi("GET DATA odeko/vvedit_kod_operatora 5000 4");
    if ($i < 4) {
        if ($result['result']) {
            $operator_code = $result['result'];
            $query = 'SELECT pin, level FROM agents WHERE agentId="' . $operator_code . '"';
            $res = mysql_query($query);
            if (!res) {
                die('Error in query' . mysql_error());
            }
            if (mysql_num_rows($res) != 0) {
                $row = mysql_fetch_row($res);
                $pin = $row[0];
                $level = $row[1];
                //errlog('PIN from database: ' . $pin . '; Level: ' . $level);
                $gotcode = TRUE;
            }
        }
    } else {
        execute_agi("STREAM FILE odeko/diakuju_do_pobachennia \"\"");
        execute_agi("HANGUP");
        exit;
    }
}

// Now lets ask for PIN
$gotcode = FALSE;
$i = 0;
while ($gotcode != TRUE) {
    $i++;
    if ($i < 4) {
        $result = execute_agi("GET DATA odeko/vvedit_parol 5000 4");
        if ($result['result'] > 1) {
            $operator_pin = $result['result'];
            if ($pin == $operator_pin) {
                $gotcode = TRUE;
            }
        }
    } else {
        execute_agi("STREAM FILE odeko/diakuju_do_pobachennia \"\"");
        execute_agi("HANGUP");
        exit;
    }
}
//errlog('Received operator:' . $operator_code . ', pin:' . $operator_pin . ', level: ' . $level, 1);

// Lets get SIPURI variable for operator's interface and decide which queue put our operator into

$result = execute_agi("GET VARIABLE SIPURI");
$interface = 'SIP/' . substr($result['data'], 4);

if ($level == 'operator') {
    $penalty = 1;
    $queue = 'callcenterq';
}
if ($level == 'dispatcher') {
    $penalty = 2;
    $queue = 'callcenterq';
}

if ($level == 'tech') {
    $queue = 'techsupport';
}

// OK, done. We have 1) $operator_code, 2) $pin and 3) operator's $interface
// Now lets update database (table AGENTS) with these data

// errlog('Extention dialed is ' . $agi['extension']);

// Extension 2880 - for login; 2881 - for logout
if ($agi['extension'] == 2880) {
    
    // Lets check if operator is already logged in somewhere
    $query = 'SELECT interface FROM agents WHERE agentId = "' . $operator_code . '"';
    $res = mysql_query($query);
    if (!$res) {
        die('Cannot select data from MySQL: ' . mysql_error());
    }
    
    // If so, kick him out
    if (mysql_num_rows($res) > 0) {
        while ($row = mysql_fetch_row($res)) {
            if ($row[0]) {
                execute_agi("EXEC REMOVEQUEUEMEMBER " . $queue . "," . $row[0]);
                errlog('Removed agent ' . $operator_code . ' on interface ' . $row[0]);
            }
        }
    }

    // Now lets check if this interface is already in use
    $query = 'SELECT agentId FROM agents WHERE interface = "' . $interface . '"';
    $res = mysql_query($query);
    if (!$res) {
        die('Cannot select data from MySQL: ' . mysql_error());
    }

    // If so, kick previous user out
    if (mysql_num_rows($res) > 0) {
        while ($row = mysql_fetch_row($res)) {
            if ($row[0]) {
                execute_agi("EXEC REMOVEQUEUEMEMBER " . $queue . "," . $interface);
                log_agi('Removed operator ' . $row[0] . ' on interface ' . $interface);
            }
        }
    }

    $stateinterface = substr($interface, 0, (strpos($interface, '@')));
    $interface = $stateinterface;

    $query = "UPDATE agents SET interface='" . $interface . "' WHERE agentId=" . $operator_code;
    $res = mysql_query($query);
    if (!$res) {
        die('Cannot select data from MySQL ' . mysql_error());
    }
    execute_agi("EXEC ADDQUEUEMEMBER " . $queue . "," . $interface . "," . $penalty . ",,," . $stateinterface);
    execute_agi("STREAM FILE odeko/uspishno_vvijshly \"\"");
    errlog('Added member ' . $operator_code . ' on ' . $interface . ' with penalty ' . $penalty . ' with stateinterface: ' . $stateinterface);
}

if ($agi['extension'] == 2881) {
    $interface = substr($interface, 0, (strpos($interface, '@')));
    $query = 'SELECT agentId FROM agents WHERE interface="' . $interface . '"';
    $res = mysql_query($query);
    if (!$res) {
        die('Cannot select data from MySQL ' . mysql_error());
    }
    $row = mysql_fetch_row($res);
    errlog('Operator ' . $row[0] . ' is logging off on ' . $interface);
    if ($row[0] == $operator_code) {
        execute_agi("EXEC RemoveQueueMember " . $queue . "," . $interface);
        errlog('Removed member from queue on ' . $interface);
        execute_agi("STREAM FILE odeko/uspishno_vyjshly \"\"");
    }
}
// Bye bye
execute_agi("STREAM FILE odeko/diakuju_do_pobachennia \"\"");

fclose($stdlog);
mysql_close($db_link);
exit;
?>
                        