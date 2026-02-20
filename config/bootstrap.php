<?php
/**
 * Home Guardian - Bootstrap 配置
 *
 * 在此注册需要在 Worker 启动时初始化的引导类。
 * 引导类必须实现 Webman\Bootstrap 接口的 start(Worker $worker) 方法。
 *
 * 执行顺序：按数组顺序依次执行，Session 必须在最前面。
 */

return [
    // Session 初始化（框架内置，必须保留）
    support\bootstrap\Session::class,
];
