#!/usr/bin/php
<?php
/**
 * AGI script for monitoring outgoing calls of operators
 */
$stdlog = fopen("/var/log/asterisk/outgoing.log", "a");

// toggle debugging output (more verbose) 
$debug = true;

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

// +++++++++  HERE WE START ++++++++++

$db_link = db_init();

// parse agi headers into $agi array 
while ($env = read()) {
    $s = split(": ", $env);
    $agi[str_replace("agi_", "", $s[0])] = trim($s[1]);
    if (($env == "") || ($env == "\n")) {
        break;
    }
}

// Lets get SIPURI variable for operator's interface

$result = execute_agi("GET VARIABLE SIPURI");
$interface = 'SIP/' . substr(substr($result['data'], 4), 0, strpos($result['data'], '@') - 4);

$select = 'SELECT id FROM agents WHERE interface like "%' . $interface . '%"';

$res = mysql_query($select);
$row = mysql_fetch_row($res);
//errlog('Interface is '.$interface);
//errlog('Operator ID = '.$row[0]);
execute_agi('SET VARIABLE CDR(userfield) ' . $interface);
execute_agi('SET VARIABLE CDR(accountcode) ' . $row[0]);

//errlog('Extention dialed is '.$agi['dnid']);

$query = 'INSERT INTO queue_log (time, callid, queuename, agent, event, data) VALUES(now(), ' . $agi['uniqueid'] . ', "outgoing", "' . $interface . '", "ENTERQUEUE", "|' . $agi['dnid'] . '|1")';
//$res = mysql_query($query);

$query = 'INSERT INTO queue_log (time, callid, queuename, agent, event, data) VALUES(now(), ' . $agi['uniqueid'] . ', "outgoing", "' . $interface . '", "CONNECT", "1|' . $agi['uniqueid'] . '|")';
//$res = mysql_query($query);
if (!$res) {
    die(mysql_error());
}

exit;
?>
                        