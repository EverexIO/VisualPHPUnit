<?php

namespace app\controller;


use \PDO;

class Graph extends \app\core\Controller {

    // GET
    public function index($request) {
        if ( $request->is('get') ) {
            if (
                !empty($request->query['details']) &&
                !empty($request->query['type'])
            ) {
                return $this->renderDetails($request);
            }

            return array(
                'munin_link' => \app\lib\Library::retrieve('munin_link')
            );
        }

        $table = "{$request->data['graph_type']}Result";
        if (
            !$request->data['start_date'] || !$request->data['end_date']
            || ($table != 'SuiteResult' && $table != 'TestResult')
        ) {
            return array(
                'type'       => $request->data['graph_type'],
                'timeFrame'  => $request->data['time_frame'],
                'categories' => array(),
                'failed'     => 0,
                'succeeded'  => 0,
                'skipped'    => 0,
                'incomplete' => 0,
                'details'    => 0
            );
        }

        $db_options = \app\lib\Library::retrieve('db');
        $db = new $db_options['plugin']();
        if ( !$db->connect($db_options) ) {
            return array(
                'error' => array(
                    'title'   => 'Error Connecting to Database',
                    'message' => implode(' ', $db->get_errors())
                )
            );
        }

        switch ( $request->data['time_frame'] ) {
            case 'Monthly':
                $interval = 2678400;
                $sql_format = 'Y-m-01';
                $output = 'M Y';
                break;
            case 'Weekly':
                $interval = 604800;
                $sql_format = 'Y-m-d';
                $output = 'm/d';
                break;
            default: // Daily
                $interval = 86400;
                $sql_format = 'Y-m-d';
                $output = 'm/d';
                break;
        }
        $current = $start = strtotime($request->data['start_date']);
        $end = strtotime($request->data['end_date']) + $interval;
        if('ByRuns' == $request->data['time_frame']){
            return $this->getByRuns($request, $db, $table, $start, $end);
        }

        $categories = array();
        $plot_values = array(
            'total'      => array(),
            'failed'     => array(),
            'incomplete' => array(),
            'skipped'    => array(),
            'succeeded'  => array(),
            'details'    => array(),
            'real_total' => array(),
        );
        while ( $current < $end ) {
            $categories[] = date($output, $current);
            $next = $current + $interval;

            $data = array(
                'failed'     => 0,
                'incomplete' => 0,
                'skipped'    => 0,
                'succeeded'  => 0,
            );

            $sql =
                "SELECT COUNT(*) `num_rows` " .
                "FROM {$table} " .
                "WHERE `run_date` >= ? AND `run_date` < ?";
            $params = array(
                date($sql_format, $current),
                date($sql_format, $next)
            );
            // file_put_contents('d:/q.log', date($sql_format, $next) . "\n", FILE_APPEND);###
            $db->query($sql, $params);
            $row = $db->fetch(PDO::FETCH_ASSOC);
            $num_rows = (int)$row['num_rows'];

            if ($num_rows > 0) {
                ### 1 {

                $sql =
                    "SELECT `failed`, `incomplete`, `skipped`, `succeeded`, `id_details`, `details` " .
                //    "SELECT `failed`, `incomplete`, `skipped`, `succeeded`, `id_details`, `real_total` " .
                    "FROM {$table} `t` " .
                    "LEFT OUTER JOIN `details` `d` " .
                    "ON `d`.`id` = `t`.`id_details` " .
                    "WHERE `t`.`run_date` >= ? AND `t`.`run_date` < ?";

                $params = array(
                    date($sql_format, $current),
                    date($sql_format, $next)
                );
                $db->query($sql, $params);

                $index = 0;
                while($result = $db->fetch(PDO::FETCH_ASSOC)){
                    foreach ( $result as $key => $value ) {
                        switch($key){
                            case 'id_details':
                                if(!$index){
                                    $plot_values['details'][] = (int)$value;
                                }
                                break;
                            case 'details':
                                if(!$index){ // ($index + 1) == $num_rows
                                    $value = unserialize($value);
                                    $plot_values['real_total'][] =
                                        isset($value['real_total'])
                                            ? (int)$value['real_total']
                                            : 0;
                                }
                                break;
                            default:
                                $data[$key] += $value;
                        }
                    }
                    ++$index;
                }
                $plot_values['total'][] = round(array_sum($data) / $num_rows, 2);
                $plot_values['total_whole'][] = array_sum($data);

                ### } 1

            } else {

                ### 2 {

                $plot_values['total'][] = 0;
                $plot_values['total_whole'][] = 0;
                $plot_values['details'][] = 0;
                $plot_values['real_total'][] = 0;

                ### } 2
            }

            foreach ( $data as $key => $val ) {
                if ( $num_rows > 0 ) {
                    $plot_values[$key][] = round($val / $num_rows, 2);
                    $plot_values[$key . '_whole'][] = $val;
                } else {
                    $plot_values[$key][] = 0;
                    $plot_values[$key . '_whole'][] = 0;
                }
            }

            $current = $next;
        }

        $db->close();

        return array(
            'type'       => $request->data['graph_type'],
            'timeFrame'  => $request->data['time_frame'],
            'categories' => $categories,
            'total'      => $plot_values['total'],
            'failed'     => $plot_values['failed'],
            'succeeded'  => $plot_values['succeeded'],
            'skipped'    => $plot_values['skipped'],
            'incomplete' => $plot_values['incomplete'],
            'details'    => $plot_values['details'],
            'real_total' => $plot_values['real_total'],
            'total_whole'      => $plot_values['total_whole'],
            'failed_whole'     => $plot_values['failed_whole'],
            'succeeded_whole'  => $plot_values['succeeded_whole'],
            'skipped_whole'    => $plot_values['skipped_whole'],
            'incomplete_whole' => $plot_values['incomplete_whole'],
        );
    }

    /**
     * Renders details page.
     *
     * @param  object $request
     * @return string
     */
    protected function renderDetails($request){
        $detailsId = (int)$request->query['details'];
        $type = $request->query['type'];
        $source = $request->query['source'];
        $dateTime = isset($request->query['dt']) ? $request->query['dt'] : '';

        $dbOptions = \app\lib\Library::retrieve('db');
        /**
         * @var \app\lib\MySQL
         */
        $db = new $dbOptions['plugin'];
        if(!$db->connect($dbOptions)){
            die(
                "There was an error connecting to the database:\n"
                . implode(' ', $db->get_errors()) . "\n"
            );
        }
        $sql =
            "SELECT `run_date`, `details` ".
            "FROM `details` " .
            "WHERE " .
                "`id` = ? AND " .
                "`type` = ? ";
        $db->query($sql, array($detailsId, $type));
        // print_r($db->get_errors());###
        $res = $db->fetch(PDO::FETCH_ASSOC);
        // var_dump($res);die;###

        $scope = array(
            'type'       => ucfirst($type),
            'source'     => $source,
        );
        if('' != $dateTime){
            $scope['run_date'] = $dateTime;
        }
        if($res){
            $details = $res['details'];
            $details = unserialize($details);
            $stats   = array();
            if('' != $dateTime){
                // Filter results by date/time
                $stats = array(
                    'succeeded'  => 0,
                    'skipped'    => 0,
                    'incomplete' => 0,
                    'failed'     => 0,
                    'total'      => 0,
                );
                foreach(array_keys($details['data']) as $name){
                    foreach(array_keys($details['data'][$name]) as $index){
                        if($dateTime != $details['data'][$name][$index]['run_time']){
                            unset($details['data'][$name][$index]);
                        }else{
                            $stats['total']++;
                            $stats[$details['data'][$name][$index]['status']]++;
                        }
                    }
                }
            }
            $scope += array('run_date' + $res['run_date']) + $stats + $details;
            $scope['percentSucceeded'] = $scope['succeeded'] * 100 / $scope['total'];
            $scope['percentSkipped'] = $scope['skipped'] * 100 / $scope['total'];
            $scope['percentIncomplete'] = $scope['incomplete'] * 100 / $scope['total'];
            $scope['percentFailed'] = $scope['failed'] * 100 / $scope['total'];
        }

        $result = $this->render_html('graph/details', $scope);

        return $result;
    }

    /**
     * Returns graph data by runs.
     *
     * @param  object $request
     * @param  MySQL  $db
     * @param  string $table
     * @param  int    $start    Timestamp
     * @param  int    $end      Timestamp
     * @return array
     */
    protected function getByRuns($request, $db, $table, $start, $end){
        $categories = array();
        $plot_values = array(
            'total'            => array(),
            'total_whole'      => array(),
            'failed_whole'     => array(),
            'incomplete_whole' => array(),
            'skipped_whole'    => array(),
            'succeeded_whole'  => array(),
            'details'          => array(),
        );
        $sql =
            "SELECT COUNT(*) `num_rows` " .
            "FROM {$table} " .
            "WHERE `run_date` >= ? AND `run_date` < ?";
        $sql_format = 'Y-m-d';
        $params = array(
            date($sql_format, $start),
            date($sql_format, $end),
        );
        $db->query($sql, $params);
        $row = $db->fetch(PDO::FETCH_ASSOC);
        $num_rows = (int)$row['num_rows'];

        if ($num_rows < 1) {
            $result = array(
                'type'       => $request->data['graph_type'],
                'timeFrame'  => $request->data['time_frame'],
                'categories' => $categories,
            ) + $plot_values;

            return $result;
        }

        $sql =
            "SELECT " .
                "`run_date`, " .
                "SUM(`failed`) `failed`, " .
                "SUM(`incomplete`) `incomplete`, " .
                "SUM(`skipped`) `skipped`, " .
                "SUM(`succeeded`) `succeeded`, " .
                "`id_details` " .
            "FROM {$table} " .
            "WHERE `run_date` >= ? AND `run_date` < ?" .
            "GROUP BY `run_date` " .
            "ORDER BY `run_date` ASC";

        $db->query($sql, $params);

        while($result = $db->fetch(PDO::FETCH_ASSOC)){
            $total = 0;
            foreach ( $result as $key => $value ) {
                switch($key){
                    case 'run_date':
                        $categories[] = $value;
                        break;
                    case 'id_details':
                        $plot_values['details'][] = (int)$value;
                        break;
                    default:
                        $value = (int)$value;
                        $plot_values[$key][] = $value;
                        $plot_values[$key . '_whole'][] = $value;
                        $total += $value;
                }
            }
            $plot_values['total'][] = $total;
            $plot_values['total_whole'][] = $total;
        }

        $result = array(
            'type'       => $request->data['graph_type'],
            'timeFrame'  => $request->data['time_frame'],
            'categories' => $categories,
        ) + $plot_values;

        return $result;
    }
}
