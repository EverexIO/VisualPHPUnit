<?php

namespace app\controller;

class Graph extends \app\core\Controller {

    // GET
    public function index($request) {
        if ( $request->is('get') ) {
            if (
                !empty($request->query['details']) &&
                !empty($request->query['type'])
            ) {
                return $this->renderDetails(
                    (int)$request->query['details'],
                    $request->query['type']
                );
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
            default:
                $interval = 86400;
                $sql_format = 'Y-m-d';
                $output = 'm/d';
                break;
        }
        $current = $start = strtotime($request->data['start_date']);
        $end = strtotime($request->data['end_date']) + $interval;

        $categories = array();
        $plot_values = array(
            'failed'     => array(),
            'incomplete' => array(),
            'skipped'    => array(),
            'succeeded'  => array(),
            'details'    => array(),
        );
        while ( $current < $end ) {
            $categories[] = date($output, $current);
            $next = $current + $interval;

            $data = array(
                'failed'     => 0,
                'incomplete' => 0,
                'skipped'    => 0,
                'succeeded'  => 0
            );

            $sql =
            //    "SELECT `failed`, `incomplete`, `skipped`, `succeeded`, `details` " .
                "SELECT `failed`, `incomplete`, `skipped`, `succeeded`, `id_details` " .
                "FROM {$table} `t` " .
            //    "LEFT OUTER JOIN `details` `d` " .
            //    "ON `d`.`id` = `t`.`details` " .
                "WHERE `t`.`run_date` >= ? AND `t`.`run_date` < ?";
            $params = array(
                date($sql_format, $current),
                date($sql_format, $next)
            );
            $db->query($sql, $params);

            $results = $db->fetch_all();
            $num_rows = count($results);

            if ( $num_rows > 0 ) {
                foreach ( $results as $notFirst => $result ) {
                    foreach ( $result as $key => $value ) {
                        if ('id_details' != $key) {
                            $data[$key] += $value;
                        } elseif(!$notFirst) {
                            $plot_values['details'][] = $value;
                        }
                    }
                }
            } else {
                $plot_values['details'][] = 0;
            }

            foreach ( $data as $key => $val ) {
                if ( $num_rows > 0 ) {
                    $plot_values[$key][] = round($val / $num_rows, 2);
                } else {
                    $plot_values[$key][] = 0;
                }
            }

            $current = $next;
        }

        $db->close();

        return array(
            'type'       => $request->data['graph_type'],
            'timeFrame'  => $request->data['time_frame'],
            'categories' => $categories,
            'failed'     => $plot_values['failed'],
            'succeeded'  => $plot_values['succeeded'],
            'skipped'    => $plot_values['skipped'],
            'incomplete' => $plot_values['incomplete'],
            'details'    => $plot_values['details'],
        );
    }

    /**
     * Renders details page.
     *
     * @param  int    $detailsId
     * @param  string $type
     * @return string
     */
    protected function renderDetails($detailsId, $type){
        $result = $this->render_html('graph/details', array());

        return $result;
    }
}
