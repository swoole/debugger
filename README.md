# 为swoole_server设计的远程终端

注册Shell到swoole_server对象
-----
```php
RemoteShell::listen($serv, '127.0.0.1', 9599);
```

连接到远程终端
----
```shell
htf@htf-All-Series:~/workspace/proj/remote-shell/tests$ telnet 127.0.0.1 9599
Trying 127.0.0.1...
Connected to 127.0.0.1.
Escape character is '^]'.
e|exec [code]	执行PHP代码
w|worker [id]	切换Worker进程
l|list	打印服务器所有连接的fd
s|stats	打印服务器状态
i|info [fd]	显示某个连接的信息
h|help	显示帮助界面
q|quit	退出终端
#2>
```
