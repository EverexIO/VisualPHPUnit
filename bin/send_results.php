#!/usr/bin/env php
<?php
require dirname(__FILE__) . '/../app/config/bootstrap.php';

use \app\lib\PDO_MySQL;

$opts  = array(
    'f:'  => 'xml_configuration_file:',
    'd::' => 'snapshot_directory::',
    'e::' => 'sandbox_errors::',
    's::' => 'store_statistics::',
    't::' => 'time::',
    'p::' => 'project::'
);
$options = getopt(implode(array_keys($opts)), array_values($opts));
$time = time();
if(isset($options['t'])){
    $time = (int)$options['t'];
}
$now = date('Y-m-d H:i:s', $time);

$email = \app\lib\Library::retrieve('email');
if($email){

    $db_options = \app\lib\Library::retrieve('db');
    $db = new $db_options['plugin']();
    if(!$db->connect($db_options)){
        die(
            "There was an error connecting to the database:\n"
            . implode(' ', $db->get_errors()) . "\n"
        );
    }

    $sql =
        "SELECT * " .
        "FROM `TestResult` " .
        "WHERE`run_date` = ? " .
        "LIMIT 1";
    if(!$db->query(
        $sql,
        array(
            $date,
            $key
        )
    )){
        die("SQL errors:\n" . implode(' ', $db->get_errors()) . "\n");
    }
    $res = $db->fetch(PDO::FETCH_ASSOC);

    if($res){
        $ok = $res['succeeded'] == $res['total'];

        $res +=
            $res['total']
                ? array(
                    'percentSucceeded'  => 100 * $res['succeeded'] / $res['total'],
                    'percentSkipped'    => 100 * $res['skipped'] / $res['total'],
                    'percentIncomplete' => 100 * $res['incomplete'] / $res['total'],
                    'percentFailed'     => 100 * $res['failed'] / $res['total'],
                ) : array(
                    'percentSucceeded'  => 0,
                    'percentSkipped'    => 0,
                    'percentIncomplete' => 0,
                    'percentFailed'     => 0,
                );


        mail(
            $email,
            sprintf(
                "[ UNIT TESTS from %s ] %s",
                getenv('NODE_ENV'),
                $ok ? "OK" : "PROBLEMS"
            ),
            sprintf(
                "Succeeded: %d / %d (%.2f %%)\n" .
                "Skipped: %d / %d (%.2f %%)\n" .
                "Incomplete: %d / %d (%.2f %%)\n" .
                "Failed: %d / %d (%.2f %%)\n",
                $res['succeeded'], $res['total'], $res['percentSucceeded'],
                $res['skipped'], $res['total'], $res['percentSkipped'],
                $res['incomplete'], $res['total'], $res['percentIncomplete'],
                $res['failed'], $res['total'], $res['percentFailed']
            )
        );

        echo "\nE-mail sent.\n\n";
    }
}
