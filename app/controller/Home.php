<?php

namespace app\controller;

class Home extends \app\core\Controller {

    protected function _create_snapshot($view_data) {
        $directory = \app\lib\Library::retrieve('snapshot_directory');
        $filename = realpath($directory). '/' . date('Y-m-d_H-i') . '.html';

        $contents = $this->render_html('partial/test_results', $view_data);

        $handle = @fopen($filename, 'a');
        if ( !file_exists($directory) || !$handle ) {
            return array(
                'type'    => 'failed',
                'title'   => 'Error Creating Snapshot',
                'message' => 'Please ensure that the '
                    . '<code>snapshot_directory</code> in '
                    . '<code>app/config/bootstrap.php</code> exists and '
                    . 'has the proper permissions.'
            );
        }

        fwrite($handle, $contents);
        fclose($handle);
        return array(
            'type'    => 'succeeded',
            'title'   => 'Snapshot Created',
            'message' => "Snapshot can be found at <code>{$filename}</code>."
        );
    }

    // GET
    public function help($request) {
        return array(
            'munin_link' => \app\lib\Library::retrieve('munin_link')
        );
    }

    // GET/POST
    public function index($request) {
        if ( $request->is('get') ) {
            $normalize_path = function($path) {
                return str_replace('\\', '/', realpath($path));
            };
            $test_directories = json_encode(array_map(
                $normalize_path, \app\lib\Library::retrieve('test_directories')
            ));

            $suites = array();
            $stats = array();
            $store_statistics = \app\lib\Library::retrieve('store_statistics');
            $create_snapshots = \app\lib\Library::retrieve('create_snapshots');
            $sandbox_errors = \app\lib\Library::retrieve('sandbox_errors');
            $xml_configuration_files = \app\lib\Library::retrieve(
                'xml_configuration_files'
            );
            $munin_link = \app\lib\Library::retrieve('munin_link');
            return compact(
                'create_snapshots',
                'sandbox_errors',
                'stats',
                'store_statistics',
                'suites',
                'test_directories',
                'xml_configuration_files',
                'munin_link'
            );
        }

        $tests = explode('|', $request->data['test_files']);
        $vpu = new \app\lib\VPU();

        if ( $request->data['sandbox_errors'] ) {
            error_reporting(\app\lib\Library::retrieve('error_reporting'));
            set_error_handler(array($vpu, 'handle_errors'));
        }

        $xml_config = false;

        $notifications = array();
        if ( $xml_file_index = $request->data['xml_configuration_file'] ) {
            $files = \app\lib\Library::retrieve('xml_configuration_files');
            $xml_config = $files[$xml_file_index - 1];
            if ( !$xml_config || !$xml_config = realpath($xml_config) ) {
                $notifications[] = array(
                    'type'    => 'failed',
                    'title'   => 'No Valid XML Configuration File Found',
                    'message' => 'Please ensure that the '
                    . '<code>xml_configuration_file</code> in '
                    . '<code>app/config/bootstrap.php</code> exists and '
                    . 'has the proper permissions.'
                );
            }
        }

        $results = ( $xml_config )
            ? $vpu->run_with_xml($xml_config)
            : $vpu->run_tests($tests);
        $results = $vpu->compile_suites($results, 'web');

        if ( $request->data['sandbox_errors'] ) {
            restore_error_handler();
        }

        $suites = $results['suites'];
        $stats = $results['stats'];
        $errors = $vpu->get_errors();
        $to_view = compact('suites', 'stats', 'errors');

        if ( $request->data['create_snapshots'] ) {
            $notifications[] = $this->_create_snapshot($to_view);
        }
        if ( $request->data['store_statistics'] ) {
            $notifications[] = $this->_store_statistics($stats, $results);
        }

        return $to_view + compact('notifications');
    }

    protected function _store_statistics($stats, $results) {
        $db_options = \app\lib\Library::retrieve('db');
        $db = new $db_options['plugin']();
        if ( !$db->connect($db_options) ) {
            return array(
                'type'    => 'failed',
                'title'   => 'Error Connecting to Database',
                'message' => implode(' ', $db->get_errors())
            );
        }
        $dbLink = @mysql_connect($db_options['host'], $db_options['username'], $db_options['password']);
        mysql_select_db($db_options['database']);

        $time = time();
        $now = date('Y-m-d H:i:s', $time);
        foreach ( $stats as $key => $stat ) {
            $sql =
                "SELECT `id`, `details` ".
                "FROM `details` " .
                "WHERE " .
                    "`run_date` = '" . date('Y-m-d') . "' AND " .
                    "`type` = '" . $key . "'";
            ($r = @mysql_query($sql)) or die(print_r(mysql_error(), TRUE));
            if(mysql_num_rows($r)){
                list($detailsId, $details) = mysql_fetch_row($r);
                $details = unserialize($details);
            }else{
                $detailsId = 0;
                $details = array(
                    'succeeded'         => 0,
                    'skipped'           => 0,
                    'incomplete'        => 0,
                    'failed'            => 0,
                    'total'             => 0,
                    'percentSucceeded'  => 0,
                    'percentSkipped'    => 0,
                    'percentIncomplete' => 0,
                    'percentFailed'     => 0,
                    'data'              => array()
                );
            }
            foreach($stat as $statKey => $value){
                $details[$statKey] += $value;
            }
            foreach($results['suites'] as $testName => $aTests){
                foreach($aTests['tests'] as $aTest){
                    if('succeeded' == $aTest['status']){
                        continue;
                    }
                    if(!isset($details['data'][$testName])){
                        $details['data'][$testName] = array();
                    }
                    $details['data'][$testName][] = $aTest;
                }
            }
            if($detailsId){
                $sql =
                    "UPDATE `details` " .
                    "SET `details` = '" .
                    mysql_real_escape_string(
                        serialize($details)
                    ) . "' " .
                    "WHERE `id` = {$detailsId}";
            }else{
                $sql =
                    "INSERT INTO `details` " .
                    "SET " .
                        "`run_date` = '" . date('Y-m-d') . "', " .
                        "`type` = '{$key}', " .
                        "`details` = '" .
                        mysql_real_escape_string(
                            serialize($details)
                        ) . "'";
            }
            mysql_query($sql) or die(print_r(mysql_error(), TRUE));
            if(!$detailsId){
                $detailsId = mysql_insert_id();
            }

            $data = array(
                'run_date'   => $now,
                'failed'     => $stat['failed'],
                'incomplete' => $stat['incomplete'],
                'skipped'    => $stat['skipped'],
                'succeeded'  => $stat['succeeded'],
                'id_details' => $detailsId,
            );
            $table = ucfirst(rtrim($key, 's')) . 'Result';
            if ( !$db->insert($table, $data) ) {
                return array(
                    'type'    => 'failed',
                    'title'   => 'Error Inserting Record',
                    'message' => implode(' ', $db->get_errors())
                );
            }
        }

        return array(
            'type'    => 'succeeded',
            'title'   => 'Statistics Stored',
            'message' => 'The statistics generated during this test run were '
                . 'successfully stored.'
        );

    }

}

?>
