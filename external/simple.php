<?php
/* Things you may want to tweak in here:
 *  - xhprof_enable() uses a few constants.
 *  - The values passed to rand() determine the the odds of any particular run being profiled.
 *  - The MongoDB collection and such.
 *
 * I use unsafe writes by default, let's not slow down requests any more than I need to. As a result you will
 * indubidubly want to ensure that writes are actually working.
 *
 * The easiest way to get going is to either include this file in your index.php script, or use php.ini's
 * auto_prepend_file directive http://php.net/manual/en/ini.core.php#ini.auto-prepend-file
 */

if(isset($_GET['enable_mytest_xhgui'])) {
    register_shutdown_function(function () {
        setcookie('enable_mytest_xhgui', $_GET['enable_mytest_xhgui'] ? '1' : '0', $expire = null, $path = '/');
    });

    if ($_GET['enable_mytest_xhgui'] != 1) {
        return;
    }
} else {
    if(isset($_COOKIE['enable_mytest_xhgui']) && $_COOKIE['enable_mytest_xhgui'] == 1) {

    } elseif(isset($_SERVER['PHP_VALUE']) && false !== strpos($_SERVER['PHP_VALUE'], 'external/header.php')){

    } else {
        return;
    }
}




if (!isset($_SERVER['REQUEST_TIME_FLOAT'])) {
    $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
}

tideways_enable(TIDEWAYS_FLAGS_CPU | TIDEWAYS_FLAGS_MEMORY | TIDEWAYS_FLAGS_NO_SPANS);

register_shutdown_function(function () {
    $data = array();

    $data['profile'] = tideways_disable();

    $dir = dirname(__DIR__);
    require_once $dir . '/src/Xhgui/Config.php';
    Xhgui_Config::load($dir . '/config/config.default.php');
    if (file_exists($dir . '/config/config.php')) {
        Xhgui_Config::load($dir . '/config/config.php');
    }
    unset($dir);

    // ignore_user_abort(true) allows your PHP script to continue executing, even if the user has terminated their request.
    // Further Reading: http://blog.preinheimer.com/index.php?/archives/248-When-does-a-user-abort.html
    // flush() asks PHP to send any data remaining in the output buffers. This is normally done when the script completes, but
    // since we're delaying that a bit by dealing with the xhprof stuff, we'll do it now to avoid making the user wait.
    ignore_user_abort(true);
    flush();

    if (!defined('XHGUI_ROOT_DIR')) {
        require dirname(dirname(__FILE__)) . '/src/bootstrap.php';
    }

    $uri = array_key_exists('REQUEST_URI', $_SERVER)
        ? $_SERVER['REQUEST_URI']
        : null;
    if (empty($uri) && isset($_SERVER['argv'])) {
        $cmd = basename($_SERVER['argv'][0]);
        $uri = $cmd . ' ' . implode(' ', array_slice($_SERVER['argv'], 1));
    }

    $time = array_key_exists('REQUEST_TIME', $_SERVER)
        ? $_SERVER['REQUEST_TIME']
        : time();
    $requestTimeFloat = explode('.', $_SERVER['REQUEST_TIME_FLOAT']);
    if (!isset($requestTimeFloat[1])) {
        $requestTimeFloat[1] = 0;
    }

    if (Xhgui_Config::read('save.handler') === 'file') {
        $requestTs = array('sec' => $time, 'usec' => 0);
        $requestTsMicro = array('sec' => $requestTimeFloat[0], 'usec' => $requestTimeFloat[1]);
    } else {
        $requestTs = new MongoDate($time);
        $requestTsMicro = new MongoDate($requestTimeFloat[0], $requestTimeFloat[1]);
    }

    $data['meta'] = array(
        'url' => $uri,
        'SERVER' => $_SERVER,
        'get' => $_GET,
        'env' => $_ENV,
        'simple_url' => Xhgui_Util::simpleUrl($uri),
        'request_ts' => $requestTs,
        'request_ts_micro' => $requestTsMicro,
        'request_date' => date('Y-m-d', $time),
    );

    try {
        $config = Xhgui_Config::all();
        $config += array('db.options' => array());
        $saver = Xhgui_Saver::factory($config);
        $saver->save($data);
    } catch (Exception $e) {
        error_log('xhgui - ' . $e->getMessage());
    }
});
