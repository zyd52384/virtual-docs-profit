# 虚拟资料赚钱机 (Virtual Docs Profit)

基于子比主题 (Zibll Theme) 的虚拟资料文库 WordPress 插件。

## 功能特性

- 📄 **批量上传** — 上传文档到腾讯云 COS，自动生成付费文章
- 💰 **独立支付** — 集成虎皮椒支付，独立收款
- 👁️ **文档预览** — 腾讯云数据万象文档预览能力
- 📊 **会员系统** — 支持VIP会员、积分体系
- 📈 **数据统计** — 后台销量、浏览统计面板

## 依赖

- [子比主题 (Zibll Theme)](https://www.zibll.com/)
- PHP 7.4+
- WordPress 5.0+
- 腾讯云对象存储 COS
- 腾讯云数据万象 CI（用于文档预览）

## 安装

1. 将插件目录上传到 `/wp-content/plugins/`
2. 在 WordPress 后台激活「虚拟资料赚钱机」
3. 进入设置页面配置腾讯云 COS 和支付参数

## 配置

### 腾讯云 COS
在插件设置页填入：
- SecretId / SecretKey
- Bucket 名称及所属地域
- 数据万象预览开关

### 支付
在插件设置页配置虎皮椒支付参数。

## 文件结构

```
virtual-docs-profit/
├── assets/              # 前端资源 (CSS/JS)
│   ├── css/wenku.css
│   └── js/wenku-upload.js
├── includes/            # PHP 逻辑
│   ├── class-admin.php  # 后台管理
│   ├── class-cos.php    # COS 集成
│   ├── class-frontend.php # 前台展示
│   ├── class-member.php # 会员功能
│   ├── class-pay.php    # 支付处理
│   └── def.php          # 辅助函数
└── virtual-docs-profit.php  # 插件主文件
```

## License

## 我一直在做虚拟资料方面的项目，深知大家在做站这方面的困恼，市面上所有的文库程序都非常的贵。感谢AI的强大，我自己在AI的帮助在，开发这个插件。
## 功能已经满足基本的项目运行
## 后期开发计划 
## 1、增加123网盘的支持，腾讯COS 价格贵，必须是备案域名，不方便我们做站群
## 2、自动同步发布结果到小红书，公众号
## 3、对接AI，自动生成文章摘要简介
## 4、等你提出需求
## 整个流程环节打通，他真就是自动躺赚机器了
## 欢迎联系讨论：baomafenxiang520
