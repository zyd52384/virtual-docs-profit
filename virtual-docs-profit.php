<?php
/**
 * Plugin Name: 虚拟资料赚钱机
 * Plugin URI: https://your-site.com
 * Description: 基于子比主题的虚拟资料文库插件。批量上传文档到腾讯云COS，自动生成付费文章，支持文档预览、独立虎皮椒支付。做虚拟产品赚钱的自动化工具。
 * Version: 1.0.0
 * Author: 虚拟资料赚钱机
 * Text Domain: virtual-docs-profit
 * Domain Path: /languages
 * 
 * 依赖：子比主题 (Zibll Theme)
 */

defined('ABSPATH') || exit;

// 插件常量定义
define('VDP_VERSION', '1.0.0');
define('VDP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('VDP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('VDP_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * 检测子比主题是否激活
 */
function vdp_check_zibll_theme() {
    $theme = wp_get_theme();
    $parent = $theme->parent();
    $theme_name = $theme->get('Name');
    $parent_name = $parent ? $parent->get('Name') : '';
    
    // 子比主题的名称特征
    $zibll_names = array('Zibll', '子比', 'zibll', 'ZIBI');
    $found = false;
    
    foreach ($zibll_names as $name) {
        if (stripos($theme_name, $name) !== false || ($parent_name && stripos($parent_name, $name) !== false)) {
            $found = true;
            break;
        }
    }
    
    return $found;
}

/**
 * 插件激活时的检查
 */
register_activation_hook(__FILE__, 'vdp_activation_check');
function vdp_activation_check() {
    if (!vdp_check_zibll_theme()) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            '虚拟资料赚钱机 插件需要子比主题 (Zibll Theme) 支持，请先安装并激活子比主题。',
            '插件激活失败',
            array('back_link' => true)
        );
    }
    
    // 创建订单表和会员表
    if (class_exists('VDP\\Pay')) {
        VDP\Pay::create_table();
    }
    if (class_exists('VDP\\Member')) {
        VDP\Member::create_table();
    }
    
    // 刷新重写规则
    flush_rewrite_rules();
}

/**
 * 插件启用后创建数据表（重新激活时也触发）
 */
add_action('init', function() {
    if (class_exists('VDP\\Pay')) {
        if (is_admin() && !get_option('vdp_db_created')) {
            VDP\Pay::create_table();
            if (class_exists('VDP\\Member')) {
                VDP\Member::create_table();
            }
            update_option('vdp_db_created', true);
        }
    }
});

/**
 * 自动加载插件类文件
 */
spl_autoload_register(function ($class) {
    $prefix = 'VDP\\';
    $base_dir = VDP_PLUGIN_DIR . 'includes/';
    
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    
    $relative_class = substr($class, $len);
    $file = $base_dir . 'class-' . str_replace('_', '-', strtolower($relative_class)) . '.php';
    
    if (file_exists($file)) {
        require $file;
    }
});

/**
 * 插件初始化
 */
add_action('plugins_loaded', 'vdp_init');
function vdp_init() {
    // 检查依赖
    if (!vdp_check_zibll_theme()) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-warning"><p>虚拟资料赚钱机 需要子比主题支持，请确保已激活子比主题。</p></div>';
        });
        return;
    }
    
    // 加载各模块
    require_once VDP_PLUGIN_DIR . 'includes/def.php';
    require_once VDP_PLUGIN_DIR . 'includes/class-pay.php';
    require_once VDP_PLUGIN_DIR . 'includes/class-member.php';
    
    // 初始化管理后台
    if (is_admin()) {
        new VDP\Admin();
    }
    
    // 初始化前端功能
    new VDP\Frontend();
    
    // 初始化支付+会员模块 AJAX
    add_action('wp_ajax_vdp_initiate_pay', array('VDP\\Pay', 'ajax_initiate_pay'));
    add_action('wp_ajax_nopriv_vdp_initiate_pay', array('VDP\\Pay', 'ajax_initiate_pay'));
    add_action('wp_ajax_vdp_check_order', array('VDP\\Pay', 'ajax_check_order'));
    add_action('wp_ajax_nopriv_vdp_check_order', array('VDP\\Pay', 'ajax_check_order'));
    add_action('wp_ajax_vdp_buy_membership', array('VDP\\Member', 'ajax_buy_membership'));
    add_action('wp_ajax_vdp_check_membership', array('VDP\\Member', 'ajax_check_membership'));
    
    // 注册自定义查询变量（虎皮椒回调）
    add_filter('query_vars', function($vars) {
        $vars[] = 'vdp_pay_notify';
        $vars[] = 'vdp_pay_return';
        return $vars;
    });
    
    // 处理虎皮椒回调
    add_action('parse_request', function($wp) {
        if (!empty($wp->query_vars['vdp_pay_notify'])) {
            VDP\Pay::handle_notify();
        }
        if (!empty($wp->query_vars['vdp_pay_return'])) {
            VDP\Pay::handle_return();
        }
    });
}
