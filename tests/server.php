<?php
require_once dirname(__DIR__) . '/src/RemoteShell.php';
$serv = new swoole_server("127.0.0.1", 9501);
$serv->set(array(
    'worker_num' => 2,   //工作进程数量
));
$serv->on('connect', function ($serv, $fd)
{
    echo "Client#$fd: Connect.\n";
});
$serv->on('receive', function ($serv, $fd, $from_id, $data)
{
    $serv->send($fd, 'Swoole: ' . $data);
});
$serv->on('close', function ($serv, $fd)
{
    echo "Client#$fd: Close.\n";
});

$serv->on("workerStart", function ($server, $workerId) {
    if ($workerId == 1) {
        return;
    }
    go(function() {
        Test::test1();
    });
});

RemoteShell::listen($serv);

class Test
{
    static $a = 133;

    static function test1()
    {
        self::test2();
    }

    static function test2()
    {
        while(true) {
            co::sleep(2.0);
        }
    }
}

$serv->start();