#!/usr/bin/php -q
<?
/**
 * Autodial AGI script creates files in Asterisk spool directory for initiating
 * outgoing calls to provided numbers. It can process several different calling scenarios
 * simultaneously.
 */

$stdlog = fopen("/var/log/asterisk/autodial.log", "a");

// toggle debugging output (more verbose) 
$debug = true;
$call_tmp_dir = "/var/spool/asterisk/tmp/";
$call_out_dir = "/var/spool/asterisk/outgoing/";

// Number of simultaneous numbers processed and daily limit
$sim_calls = 5;
$total_daily = 5000;

$date_str = date("d M y H:i:s");

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

// Clean Asterisk spool directory just to be sure
function cleanup() {
    global $call_out_dir;
    log_write("Starting clean up procedure.");
    $call_out_dir_h = opendir($call_out_dir);
    while (($file = readdir($call_out_dir_h)) !== false) {
        if ($file != '.' && $file != '..') {
            //errlog("Found " . $file . " Checking, whether it was already processed.");
            $query = "SELECT * FROM c_debtors WHERE status='F' and phone='$file'";
            $res = mysql_query($query) or log_write(mysql_error());
            if (mysql_num_rows($res)) {
                //errlog("Deleting file $file.");
                if (!unlink($call_out_dir . $file))
                    errlog("Unable to unlink file $file");
            }
        }
    }
    closedir($call_out_dir_h);
}

function get_dialed_daily() {
    $query = "SELECT COUNT(*) num FROM c_debtors where date_format(date_called,'%Y%m%d') = date_format(now(), '%Y%m%d') AND status<>'F'";
    $res = mysql_query($query) or log_write(mysql_error());
    $row = mysql_fetch_row($res);
    $result = $row[0];
    errlog("$result successful/answered calls today.");
    return $result;
}

// Get all dialout scenarios
function get_dialplans() {
    $query = 'SELECT * FROM c_dial WHERE status="active" AND date_format(date_start,"%Y%m%d")<=date_format(now(),"%Y%m%d") AND date_format(date_stop,"%Y%m%d")>=date_format(now(), "%Y%m%d")';
    $res = mysql_query($query) or log_write(mysql_error());
    if (mysql_num_rows($res)) {
        $dials = array();
        while ($row = mysql_fetch_assoc($res)) {
            $dials[] = $row;
        }
    }
    return $dials;
}

// Get numbers to call for particular dial scenario
function get_data($dialId, $number) {
    $query = "SELECT * FROM c_debtors WHERE status = 'N' AND dialId = $dialId LIMIT 0, $number";
    $res = mysql_query($query);
    if (!res) {
        die(mysql_error());
    }
    // No fresh numbers, lets check if we can dial somebody once again
    if (!mysql_num_rows($res)) {
        $query = "SELECT * FROM c_debtors WHERE  dialId = $dialId AND status = 'F' AND inc = (SELECT min(inc) FROM c_debtors WHERE dialId = $dialId AND status='F') LIMIT 0, $number";
    }
    $res = mysql_query($query);
    if (!$res) {
        die(mysql_error());
    }
    
    while ($row = mysql_fetch_assoc($res)) {
        $result[$row['phone']]['debt'] = (string) $row['debt'];
        $result[$row['phone']]['dialId'] = $row['dialId'];
        $result[$row['phone']]['inc'] = $row['inc'];
        //errlog($row['phone'] . ', debt:' . $result[$row['phone']]['debt'] . ', dialId: ' . $result[$row['phone']]['dialId'] . ', inc: ' . $result[$row['phone']]['inc']);
    }
    return $result;
}

// Updating corresponding row for dialed number, incremeting number of call attempts
function put_data($dialed) {
    $query = "UPDATE c_debtors SET status='D',date_called=now(), inc = inc+1 WHERE phone='$dialed'";
    $stmt = mysql_query($query);
    if (!$stmt) {
        errlog(mysql_error());
        die(mysql_error());
    }
}

// +++++++++++++++++++++++++++++++++++
// +++++++++  HERE WE START ++++++++++
// parse agi headers into $agi array 
while ($env = read()) {
    $s = split(": ", $env);
    $agi[str_replace("agi_", "", $s[0])] = trim($s[1]);
    if (($env == "") || ($env == "\n")) {
        break;
    }
}

$db_link = db_init();
cleanup();
$today = get_dialed_daily();
if ($today > $total_daily) {
    errlog("Total number of planned calls reached. Not processing farther.");
    fclose($stdlog);
    mysql_close($db_link);
    exit;
}

// Lets check are there any files processed already (after cleaning)
$count = 0;
$call_out_dir_h = opendir($call_out_dir);
while (($file = readdir($call_out_dir_h)) !== false) {
    if ($file != '.' && $file != '..')
        $count++;
}
closedir($call_out_dir_h);
if ($count) {
    errlog("So far $count files are being processed in present time");
}

// Get number of calls we can place per one dialplan
$dialplans = get_dialplans();
$dialplans_number = count($dialplans);
if ($dialplans_number) {
    $calls = round(($sim_calls - $count) / $dialplans_number);
}
errlog('Number of sim calls: ' . $sim_calls . ', processed calls: ' . $count . ', dialplans: ' . $dialplans_number);
$numbers = array();

// Get phone numbers for each dialplan
// ++++++++++++++++ FIXME FIXME FIXME +++++++++++++++++
// Need to add uniform distribution of calls per dialplan if number of dialplans is bigger than $sim_calls
if ($dialplans)
    foreach ($dialplans as $dialplan) {
        $row = get_data($dialplan['dialplanId'], $calls);
        if (is_array($row))
            $numbers = $numbers + $row;
    }
foreach ($numbers as $key => $data) {
    errlog($key . ' ' . $data['debt'] . ' ' . $data['dialId']);
}

if (!count($numbers)) {
    errlog("No numbers to call");
    fclose($stdlog);
    mysql_close($db_link);
    exit;
}

// Create dialout files
$i = 1;
foreach ($numbers as $number => $data) {
    $len = strlen((string) $number);
    // We can call through different gateways in different cities
    if ($len < 7) {
        $gateway = 'tr_asterisk';
        $callerid = '72222';
    } else {
        $gateway = 'gateway';
        $callerid = '2360909';
    }
    errlog("Length is " . strlen((string) $number));
    errlog("Creating call file for subscriber " . $number);
    errlog("Number: " . $number . "; DialID " . $data['dialId'] . "; Debt: " . $data['debt'] . "; Gateway: " . $gateway . "; CallerId: " . $callerid);
    $f = fopen($call_tmp_dir . $number, "w");
    fwrite($f, "channel: SIP/" . $number . "@" . $gateway . "\n");
    fwrite($f, "retrytime: 60\n");
    fwrite($f, "maxretries: 0\n");
    fwrite($f, "context: dialout\n");
    fwrite($f, "priority: 1\n");
    fwrite($f, "callerid: " . $callerid . "\n");
    fwrite($f, "extension: s\n");
    fwrite($f, "set: debt=" . $data['debt'] . "\n");
    fwrite($f, "set: dialId=" . $data['dialId'] . "\n");
    fwrite($f, "set: sub_num=" . $number . "\n");
    fclose($f);
    $i++;
    errlog("File created.");
    if (!copy($call_tmp_dir . $number, $call_out_dir . $number)) {
        errlog("Failed to copy file: " . $call_tmp_dir . $number . " to file " . $call_out_dir . $number);
    }
    put_data($number);
}

log_write("Finished this attempt");
fclose($stdlog);
mysql_close($db_link);
exit;
?>