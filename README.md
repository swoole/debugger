# 为 Swoole Server 设计的远程终端

基于`swoole-1.8`的多协议特性实现，业务无需做任何修改，只需要加一行代码即可引入一个功能强大的远程终端。

## 安装

```shell
composer require swoole/debugger
```

## 注册 Shell 到 Swoole Server 对象

* 建议只监听本机`127.0.0.1`或局域网`192.168.1.100`

```php
\Swoole\Debugger\RemoteShell::listen($serv, '127.0.0.1', 9599);
```

## 连接到远程终端

```shell
htf@htf-All-Series:~/workspace/proj/remote-shell/tests$ telnet 127.0.0.1 9599
Trying 127.0.0.1...
Connected to 127.0.0.1.
Escape character is '^]'.
p|print [variant]	打印一个PHP变量的值
e|exec [code]	执行一段PHP代码
w|worker [id]	切换Worker进程
l|list	打印服务器所有连接的fd
s|stats	打印服务器状态
c|coros	打印协程列表
cs|costats	打印协程状态
el|elapsed [cid]	打印某个协程运行的时间
b|bt	打印协程调用栈
i|info [fd]	显示某个连接的信息
h|help	显示帮助界面
q|quit	退出终端
#2>
```
