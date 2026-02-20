<?php
/**
 * Home Guardian - 异常处理器配置
 *
 * 将不同的 URL 路径映射到不同的异常处理器。
 * 空字符串 '' 表示全局默认处理器，所有未匹配的路径都走此处理。
 */

return [
    // 全局默认：使用自定义的 JSON 异常处理器
    '' => app\exception\Handler::class,
];
