# PHP 邮件模板校验接入

`EmailTemplateValidator.php` 是后台保存前的校验器，依据 Exchange `0a4f27233` 的 `packages/common/emailtemplate/{template,style,rows}.go`。适用 PHP 8.1+，使用标准 PCRE、JSON、filter 函数，不依赖 Composer、DOM、mbstring 或框架。

## 调用方法

将 `EmailTemplateValidator.php` 放入后台工程，通过自动加载或 `require_once` 引入：

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/EmailTemplateValidator.php';

use Freedex\Email\EmailTemplateValidator;

$template = [
    'subject' => '{{siteName}} 验证码',
    'textBody' => '验证码：{{code}}，有效期至 {{expiresAt}}。',
    'htmlBody' => '<div style="padding:24px;background-color:#fff">'
        . '<img src="{{logoUrl}}" width="96" alt="Logo">'
        . '<p>验证码：<strong>{{code}}</strong></p>'
        . '<p>有效期至 {{expiresAt}}</p></div>',
];

try {
    EmailTemplateValidator::validate(
        $template,
        ['cdn.example.com'], // 来自可信后台配置，并与User的EmailAuth.ResourceHosts对齐。
        'https://cdn.example.com/brand/logo.png', // 合并保存补丁后的实际Logo值。
        true // 新的分行邮件模式；旧格式使用false。
    );
    // 校验成功后调用控制面的受控发布入口；仍需权限、并发版本和事务校验。
} catch (InvalidArgumentException $e) {
    // 适配为后台已有的参数错误响应；这里不定义新的HTTP状态码或公开业务码。
    $validationError = $e->getMessage();
}
```

可独立调用：

```php
EmailTemplateValidator::validateLogo($logoUrl, $trustedResourceHosts);
EmailTemplateValidator::validateStyle('padding:16px;color:#333');
```

`validate` 返回 `void`；通过则无异常，失败抛出 `InvalidArgumentException`。它只校验，不清洗、不重写、不发送邮件、不下载图片。保存的HTML应与传入校验的原文相同；校验之后再次转换或拼接HTML需要重新校验。

## 覆盖范围

- 标题、正文类型、UTF-8、长度及模板变量。
- HTML 标签、属性、CSS 属性和值白名单。
- HTTPS链接、静态图片及动态Logo的资源主机白名单。
- `logoUrl`只允许出现在分行模式的完整`img src="{{logoUrl}}"`，禁止编码占位符或URL拼接。

本方法接收一条正文，不接受整个站点配置。**不负责**站点权限、版本并发、purpose/locale规范化、默认语言、同用途/语言重复项、整套模板大小、快照摘要、发布事务及最终变量展开后的长度；这些继续由控制面发布器和User负责。仅修改Logo时要先合并当前配置，省略Logo表示保留、空字符串表示清除；不要把缺省的`templates`补成空数组。

## HTML白名单

标签仅允许：`p br div span strong b em i table tbody tr td a img`。不支持完整HTML文档、注释、DOCTYPE、脚本、样式标签。

| 属性 | 范围与值 |
|---|---|
| style | 任一允许标签，须通过CSS白名单 |
| alt、title | 任一允许标签，静态文本，无模板变量 |
| width、height | 任一允许标签，1至4位十进制数字，不带单位 |
| role | 仅table，值为presentation |
| cellpadding、cellspacing、border | 仅table，1至2位十进制数字 |
| align | 仅table/td，left、center或right |
| href | 仅a，允许的HTTPS资源 |
| src | 仅img，允许的HTTPS资源或完整Logo变量 |

其余属性拒绝，包括class、id、target、colspan、rowspan、事件属性及data-*。

CSS完整值规则位于`styleValue()`。颜色只接受3/6位十六进制或允许位置的transparent；不接受任意CSS、URL、函数、负数、小数、!important或变量。每个style最多2048字节、24个不重复属性。HTML标签及属性名不区分ASCII大小写；CSS属性名和值按源码精确匹配。

## 与Go的边界

这是一个**严格的保存前校验子集**，不是Go HTML5 tokenizer的逐字移植，也不是HTML树结构/视觉校验器：

- HTML属性必须加单引号或双引号，属性之间必须有空白，拒绝重复属性。
- HTML文本和属性中的`&`必须使用完整字符引用，例如`&amp;`；不接受省略分号的字符引用。URL查询参数在HTML中写`?a=1&amp;b=2`，独立的`logoUrl`配置仍写原始URL。
- 拒绝畸形标签、HTML中的NUL、URL中的原始空白/控制字符/反斜杠和显式空端口；文字里的小于号写成`&lt;`。
- 资源主机接受ASCII域名、punycode、IPv4及无zone的IPv6；国际化域名应先转punycode并同步可信白名单。禁止显式端口和用户信息。
- 实体解码使用PHP的`html_entity_decode(..., ENT_QUOTES | ENT_HTML5, 'UTF-8')`；不承诺所有畸形HTML或所有实体边界与Go一致。此限制不能绕过User的最终校验。

资源主机按完整主机名、不区分大小写匹配，无后缀或通配符匹配。允许主机必须由后台可信配置提供，不接受浏览器自己提交白名单。

## 验证与交付

本地安装PHP的后端可执行：

```sh
php -l EmailTemplateValidator.php
php test.php
```

2026-11-18：PHP 8.1.34和8.5.12均通过155条用例，包含8套Go默认模板。环境无原生PHP，本次使用本地PHP WebAssembly CLI执行，没有连接共享数据库或发送邮件。相同用例与Go公共校验器逐项对照；`cases.jsonl`中`valid`为PHP预期，`goValid`为Go预期，其中5条记录明确体现更严格的PHP输入限制。测试不是任意输入的等价性证明。

上游规则：[`template.go`](../../../../packages/common/emailtemplate/template.go)、[`style.go`](../../../../packages/common/emailtemplate/style.go)、[`rows.go`](../../../../packages/common/emailtemplate/rows.go)。更新Go规则时必须同步本文件和回归用例。

PHP实体解码语义见[官方文档](https://www.php.net/manual/en/function.html-entity-decode.php)。
