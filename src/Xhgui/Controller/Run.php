<?php

class Xhgui_Controller_Run extends Xhgui_Controller
{
    public function __construct($app, $profiles, $watches)
    {
        $this->_app = $app;
        $this->_profiles = $profiles;
        $this->_watches = $watches;
    }

    public function index()
    {
        $request = $this->_app->request();

        $search = array();
        $keys = array('date_start', 'date_end', 'url');
        foreach ($keys as $key) {
            if ($request->get($key)) {
                $search[$key] = $request->get($key);
            }
        }
        $sort = $request->get('sort');

        $result = $this->_profiles->getAll(array(
            'sort' => $sort,
            'page' => $request->get('page'),
            'direction' => $request->get('direction'),
            'perPage' => $this->_app->config('page.limit'),
            'conditions' => $search,
            'projection' => true,
        ));

        $title = 'Recent runs';
        $titleMap = array(
            'wt' => 'Longest wall time',
            'cpu' => 'Most CPU time',
            'mu' => 'Highest memory use',
        );
        if (isset($titleMap[$sort])) {
            $title = $titleMap[$sort];
        }

        $paging = array(
            'total_pages' => $result['totalPages'],
            'page' => $result['page'],
            'sort' => $sort,
            'direction' => $result['direction']
        );

        $this->_template = 'runs/list.twig';
        $this->set(array(
            'paging' => $paging,
            'base_url' => 'home',
            'runs' => $result['results'],
            'date_format' => $this->_app->config('date.format'),
            'search' => $search,
            'has_search' => strlen(implode('', $search)) > 0,
            'title' => $title
        ));
    }

    public function clearAll()
    {
        $this->_profiles->truncate();

        header('Location: /all-cleared');
        die();
    }

    public function allCleared()
    {
        $this->_template = 'runs/all-cleared.twig';
        $this->set(array(
            'title' => 'All data is cleared'
        ));
    }

    public function import()
    {
        if (@$_SERVER['REQUEST_METHOD'] === 'POST'){
            $importedFiles = [];
            $errors = [];
            foreach ($_FILES as $FILE) {
                foreach ($FILE['tmp_name'] as $i => $file) {
                    try {
                        $fp = fopen($file, 'r');
                        if (!$fp) {
                            throw new RuntimeException('Can\'t open ' . $file);
                        }

                        $container = Xhgui_ServiceContainer::instance();
                        $saver = $container['saverMongo'];


                        while (!feof($fp)) {
                            $line = fgets($fp);
                            $data = json_decode($line, true);
                            if ($data) {
                                $saver->save($data);
                            }
                        }

                        fclose($fp);
                        $importedFiles[] = $FILE['name'][$i];
                    } catch (\Exception $e){
                        // ignore...
                        $errors[] = $e->getTraceAsString();
                    }
                }
            }

            if (@$_POST['_ajax']){
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'imported' => $importedFiles, 'errors' => $errors]);
                die;
            }
        }

        $this->_template = 'runs/import.twig';
        $this->set(array(
            'importedFiles' => isset($importedFiles) ? print_r($importedFiles, true) : 'NONE',
            'errors' => isset($errors) ? print_r($errors, true) : '',
            'title' => 'Import xhprof data'
        ));
    }

    public function importAllFromCache()
    {
        $allFiles = glob(__DIR__.'/../../../cache/*.dat');
        foreach($allFiles as $file){
            $fp = fopen($file, 'r');
            if (!$fp) {
                throw new RuntimeException('Can\'t open '.$file);
            }

            $container = Xhgui_ServiceContainer::instance();
            $saver = $container['saverMongo'];


            while (!feof($fp)) {
                $line = fgets($fp);
                $data = json_decode($line, true);
                if ($data) {
                    $saver->save($data);
                }
            }

            fclose($fp);
        }

        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'files' => $allFiles]);
        die();
    }

    public function view()
    {
        $request = $this->_app->request();
        $detailCount = $this->_app->config('detail.count');
        $result = $this->_profiles->get($request->get('id'));

        $result->calculateSelf();

        // Self wall time graph
        $timeChart = $result->extractDimension('ewt', $detailCount);

        // Memory Block
        $memoryChart = $result->extractDimension('emu', $detailCount);

        // Watched Functions Block
        $watchedFunctions = array();
        foreach ($this->_watches->getAll() as $watch) {
            $matches = $result->getWatched($watch['name']);
            if ($matches) {
                $watchedFunctions = array_merge($watchedFunctions, $matches);
            }
        }

        $profile = $result->sort('ewt', $result->getProfile());

        $this->_template = 'runs/view.twig';
        $this->set(array(
            'profile' => $profile,
            'result' => $result,
            'wall_time' => $timeChart,
            'memory' => $memoryChart,
            'watches' => $watchedFunctions,
            'date_format' => $this->_app->config('date.format'),
        ));
    }

    public function url()
    {
        $request = $this->_app->request();
        $pagination = array(
            'sort' => $request->get('sort'),
            'direction' => $request->get('direction'),
            'page' => $request->get('page'),
            'perPage' => $this->_app->config('page.limit'),
        );

        $search = array();
        $keys = array('date_start', 'date_end', 'limit', 'limit_custom');
        foreach ($keys as $key) {
            $search[$key] = $request->get($key);
        }

        $runs = $this->_profiles->getForUrl(
            $request->get('url'),
            $pagination,
            $search
        );

        if (isset($search['limit_custom']) && strlen($search['limit_custom']) > 0 && $search['limit_custom'][0] == 'P') {
            $search['limit'] = $search['limit_custom'];
        }

        $chartData = $this->_profiles->getPercentileForUrl(
            90,
            $request->get('url'),
            $search
        );

        $paging = array(
            'total_pages' => $runs['totalPages'],
            'sort' => $pagination['sort'],
            'page' => $runs['page'],
            'direction' => $runs['direction']
        );

        $this->_template = 'runs/url.twig';
        $this->set(array(
            'paging' => $paging,
            'base_url' => 'url.view',
            'runs' => $runs['results'],
            'url' => $request->get('url'),
            'chart_data' => $chartData,
            'date_format' => $this->_app->config('date.format'),
            'search' => array_merge($search, array('url' => $request->get('url'))),
        ));
    }

    public function compare()
    {
        $request = $this->_app->request();

        $baseRun = $headRun = $candidates = $comparison = null;
        $paging = array();

        if ($request->get('base')) {
            $baseRun = $this->_profiles->get($request->get('base'));
        }

        if ($baseRun && !$request->get('head')) {
            $pagination = array(
                'direction' => $request->get('direction'),
                'sort' => $request->get('sort'),
                'page' => $request->get('page'),
                'perPage' => $this->_app->config('page.limit'),
            );
            $candidates = $this->_profiles->getForUrl(
                $baseRun->getMeta('simple_url'),
                $pagination
            );

            $paging = array(
                'total_pages' => $candidates['totalPages'],
                'sort' => $pagination['sort'],
                'page' => $candidates['page'],
                'direction' => $candidates['direction']
            );
        }

        if ($request->get('head')) {
            $headRun = $this->_profiles->get($request->get('head'));
        }

        if ($baseRun && $headRun) {
            $comparison = $baseRun->compare($headRun);
        }

        $this->_template = 'runs/compare.twig';
        $this->set(array(
            'base_url' => 'run.compare',
            'base_run' => $baseRun,
            'head_run' => $headRun,
            'candidates' => $candidates,
            'url_params' => $request->get(),
            'date_format' => $this->_app->config('date.format'),
            'comparison' => $comparison,
            'paging' => $paging,
            'search' => array(
                'base' => $request->get('base'),
                'head' => $request->get('head'),
            )
        ));
    }

    public function symbol()
    {
        $request = $this->_app->request();
        $id = $request->get('id');
        $symbol = $request->get('symbol');

        $profile = $this->_profiles->get($id);
        $profile->calculateSelf();
        list($parents, $current, $children) = $profile->getRelatives($symbol);

        $this->_template = 'runs/symbol.twig';
        $this->set(array(
            'symbol' => $symbol,
            'id' => $id,
            'main' => $profile->get('main()'),
            'parents' => $parents,
            'current' => $current,
            'children' => $children,
        ));
    }

    public function symbolShort()
    {
        $request = $this->_app->request();
        $id = $request->get('id');
        $threshold = $request->get('threshold');
        $symbol = $request->get('symbol');
        $metric = $request->get('metric');

        $profile = $this->_profiles->get($id);
        $profile->calculateSelf();
        list($parents, $current, $children) = $profile->getRelatives($symbol, $metric, $threshold);

        $this->_template = 'runs/symbol-short.twig';
        $this->set(array(
            'symbol' => $symbol,
            'id' => $id,
            'main' => $profile->get('main()'),
            'parents' => $parents,
            'current' => $current,
            'children' => $children,
        ));
    }

    public function callgraph()
    {
        $request = $this->_app->request();
        $profile = $this->_profiles->get($request->get('id'));

        $this->_template = 'runs/callgraph.twig';
        $this->set(array(
            'profile' => $profile,
            'date_format' => $this->_app->config('date.format'),
        ));
    }

    public function callgraphData()
    {
        $request = $this->_app->request();
        $response = $this->_app->response();
        $profile = $this->_profiles->get($request->get('id'));
        $metric = $request->get('metric') ?: 'wt';
        $threshold = (float)$request->get('threshold') ?: 0.01;
        $callgraph = $profile->getCallgraph($metric, $threshold);

        $response['Content-Type'] = 'application/json';
        return $response->body(json_encode($callgraph));
    }

    public function flamegraph()
    {
        $request = $this->_app->request();
        $profile = $this->_profiles->get($request->get('id'));

        $this->_template = 'runs/flamegraph.twig';
        $this->set(array(
            'profile' => $profile,
            'date_format' => $this->_app->config('date.format'),
        ));
    }

    public function flamegraphData()
    {
        $request = $this->_app->request();
        $response = $this->_app->response();
        $profile = $this->_profiles->get($request->get('id'));
        $metric = $request->get('metric') ?: 'wt';
        $threshold = (float)$request->get('threshold') ?: 0.01;
        $flamegraph = $profile->getFlamegraph($metric, $threshold);

        $response['Content-Type'] = 'application/json';
        return $response->body(json_encode($flamegraph));
    }

    public function callgraphDataDot()
    {
        $request = $this->_app->request();
        $response = $this->_app->response();
        $profile = $this->_profiles->get($request->get('id'));
        $metric = $request->get('metric') ?: 'wt';
        $threshold = (float)$request->get('threshold') ?: 0.01;
        $callgraph = $profile->getCallgraphNodes($metric, $threshold);

        $response['Content-Type'] = 'application/json';
        return $response->body(json_encode($callgraph));
    }
}
