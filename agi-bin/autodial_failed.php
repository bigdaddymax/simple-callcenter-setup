#!/usr/bin/php -q
<?
$stdlog = fopen("/var/log/asterisk/autodial_failed.log", "a");

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

function put_data($dialed) {
    global $date_str, $conn;

    $query = "UPDATE c_debtors SET status='F',date_called=now() WHERE phone='$dialed'";
    $stmt = mysql_query($query);
    if (!$stmt) {
        log_write(mysql_error());
        die(mysql_error());
    }
}

// +++++++++  HERE WE START ++++++++++
// parse agi headers into $agi array 
while ($env = read()) {
    $s = split(": ", $env);
    $agi[str_replace("agi_", "", $s[0])] = trim($s[1]);
    if (($env == "") || ($env == "\n")) {
        break;
    }
}

$res = execute_agi("GET VARIABLE sub_num");
put_data($res['data']);
log_write("Put failed for $res");
fclose($stdlog);
fclose($out);
fclose($in);
//ocilogoff($conn);
exit;
?>