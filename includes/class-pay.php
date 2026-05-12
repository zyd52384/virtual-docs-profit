<?php
namespace VDP;

/**
 * 独立虎皮椒 V3 支付模块
 * 完全绕过子比主题的支付系统
 */
defined('ABSPATH') || exit;

class Pay {
    
    const API_URL = 'https://api.xunhupay.com/payment/do.html';
    const TABLE_NAME = 'vdp_orders';
    
    /**
     * 获取虎皮椒配置
     */
    public static function get_config() {
        return get_option('vdp_hupijiao_settings', array(
            'appid'      => '',
            'appsecret'  => '',
        ));
    }
    
    /**
     * 是否已配置
     */
    public static function is_configured() {
        $config = self::get_config();
        return !empty($config['appid']) && !empty($config['appsecret']);
    }
    
    /**
     * 激活时创建订单表
     */
    public static function create_table() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        $charset = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            order_num varchar(64) NOT NULL COMMENT '订单号',
            post_id bigint(20) NOT NULL DEFAULT 0 COMMENT '文章ID',
            user_id bigint(20) NOT NULL DEFAULT 0 COMMENT '用户ID',
            pay_price decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '支付金额',
            pay_type varchar(32) NOT NULL DEFAULT '' COMMENT '支付方式 wechat/alipay',
            status tinyint(1) NOT NULL DEFAULT 0 COMMENT '0待支付 1已支付 -1已关闭',
            trade_no varchar(128) NOT NULL DEFAULT '' COMMENT '虎皮椒交易号',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            paid_at datetime NULL COMMENT '支付时间',
            PRIMARY KEY (id),
            KEY order_num (order_num),
            KEY post_id (post_id),
            KEY user_id (user_id),
            KEY status (status)
        ) $charset;";
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
    
    /**
     * 生成订单号
     */
    public static function generate_order_num() {
        return 'VDP' . date('YmdHis') . mt_rand(1000, 9999);
    }
    
    /**
     * 创建订单
     */
    public static function create_order($post_id, $user_id, $price, $pay_type = 'wechat') {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        
        $order_num = self::generate_order_num();
        
        $wpdb->insert($table, array(
            'order_num' => $order_num,
            'post_id'   => intval($post_id),
            'user_id'   => intval($user_id),
            'pay_price' => floatval($price),
            'pay_type'  => $pay_type,
            'status'    => 0,
            'created_at' => current_time('mysql'),
        ));
        
        return $order_num;
    }
    
    /**
     * 检查用户是否已购买某文章
     */
    public static function has_paid($post_id, $user_id) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE post_id = %d AND user_id = %d AND status = 1",
            $post_id, $user_id
        ));
        
        return $count > 0;
    }
    
    /**
     * 调用虎皮椒 API 创建支付
     */
    public static function initiate_pay($order_num, $price, $title, $pay_type = 'wechat') {
        $config = self::get_config();
        if (!self::is_configured()) {
            return array('error' => '虎皮椒未配置');
        }
        
        $appid     = $config['appid'];
        $appsecret = $config['appsecret'];
        
        $site_url = home_url();
        $notify_url = home_url('?vdp_pay_notify=1');
        $return_url = home_url('?vdp_pay_return=1');
        
        // 支付方式
        $plugin_id = 'vdp_xunhupay_' . $pay_type;
        
        $data = array(
            'version'         => '1.0',
            'appid'           => $appid,
            'trade_order_id'  => $order_num,
            'total_fee'       => $price,
            'title'           => $title,
            'time'            => time(),
            'notify_url'      => $notify_url,
            'return_url'      => $return_url,
            'callback_url'    => $return_url,
            'nonce_str'       => substr(md5(uniqid()), 0, 16),
            'plugins'         => $plugin_id,
            'wap_url'         => $site_url,
            'wap_name'        => get_bloginfo('name'),
        );
        
        // 签名
        $data['hash'] = self::generate_hash($data, $appsecret);
        
        // 调用 API
        $response = self::http_post(self::API_URL, $data);
        
        if (!$response) {
            return array('error' => '请求虎皮椒接口失败');
        }
        
        $result = json_decode($response, true);
        if (!$result) {
            return array('error' => '虎皮椒返回异常: ' . substr($response, 0, 200));
        }
        
        if (!empty($result['errcode'])) {
            return array('error' => $result['errcode'] . ': ' . ($result['errmsg'] ?? '未知错误'));
        }
        
        return array(
            'url_qrcode' => $result['url_qrcode'] ?? '',
            'url'        => $result['url'] ?? '',
            'order_id'   => $result['order_id'] ?? '',
        );
    }
    
    /**
     * 虎皮椒签名
     */
    private static function generate_hash($data, $appsecret) {
        ksort($data);
        $arg = '';
        $i = 0;
        foreach ($data as $key => $val) {
            if ($key === 'hash') continue;
            if ($val === '' || is_null($val)) continue;
            $arg .= ($i > 0 ? '&' : '') . $key . '=' . $val;
            $i++;
        }
        return md5($arg . $appsecret);
    }
    
    /**
     * HTTP POST 请求
     */
    private static function http_post($url, $data) {
        $args = array(
            'body'        => $data,
            'timeout'     => 30,
            'redirection' => 0,
            'sslverify'   => false,
        );
        
        $response = wp_remote_post($url, $args);
        
        if (is_wp_error($response)) {
            return false;
        }
        
        return wp_remote_retrieve_body($response);
    }
    
    /**
     * AJAX: 发起支付
     */
    public static function ajax_initiate_pay() {
        // 从各种可能的来源获取参数
        $raw = array();
        $input = file_get_contents('php://input');
        if ($input) {
            parse_str($input, $raw);
        }
        $data = array_merge($_GET, $_POST, $raw);
        
        $post_id   = isset($data['post_id']) ? intval($data['post_id']) : 0;
        $pay_type  = isset($data['pay_type']) ? sanitize_text_field($data['pay_type']) : 'wechat';
        $user_id   = get_current_user_id();
        
        if (!$post_id) {
            wp_send_json_error('参数错误: 收到的原始数据=' . json_encode($data));
        }
        
        // 获取文章价格
        $meta = get_post_meta($post_id, 'posts_zibpay', true);
        $price = isset($meta['pay_price']) ? floatval($meta['pay_price']) : 0;
        
        // 已登录用户的VIP折扣
        if ($user_id && function_exists('zib_get_user_vip_level')) {
            $vip_level = zib_get_user_vip_level($user_id);
            if ($vip_level && isset($meta['vip_' . $vip_level . '_price'])) {
                $vip_price = floatval($meta['vip_' . $vip_level . '_price']);
                if ($vip_price > 0 && $vip_price < $price) {
                    $price = $vip_price;
                } elseif ($vip_price == 0 && $vip_price < $price) {
                    // VIP免费
                }
            }
        }
        
        if ($price <= 0) {
            wp_send_json_error('价格为0，无需支付');
        }
        
        // 创建订单
        $title = get_the_title($post_id);
        $title = mb_substr($title, 0, 30);
        
        $order_num = self::create_order($post_id, $user_id, $price, $pay_type);
        
        // 检查是否已存在待支付订单
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE post_id = %d AND user_id = %d AND status = 0 ORDER BY id DESC LIMIT 1",
            $post_id, $user_id
        ));
        if ($existing) {
            // 使用已有订单
            $order_num = $existing->order_num;
        }
        
        // 调用虎皮椒
        $result = self::initiate_pay($order_num, $price, $title, $pay_type);
        
        if (!empty($result['error'])) {
            wp_send_json_error($result['error']);
        }
        
        wp_send_json_success(array(
            'url_qrcode'  => $result['url_qrcode'] ?? '',
            'url'         => $result['url'] ?? '',
            'order_num'   => $order_num,
        ));
    }
    
    /**
     * 处理虎皮椒支付回调
     */
    public static function handle_notify() {
        if (empty($_POST['hash']) || empty($_POST['trade_order_id'])) {
            return;
        }
        
        $config = self::get_config();
        $appsecret = $config['appsecret'];
        
        $data = $_POST;
        foreach ($data as $k => $v) {
            $data[$k] = stripslashes($v);
        }
        
        // 验证签名
        $hash = self::generate_hash($data, $appsecret);
        if ($data['hash'] !== $hash) {
            echo 'failed';
            exit;
        }
        
        if ($data['status'] === 'OD') {
            // 更新订单状态
            global $wpdb;
            $table = $wpdb->prefix . self::TABLE_NAME;
            
            $wpdb->update(
                $table,
                array(
                    'status'  => 1,
                    'trade_no' => $data['transaction_id'] ?? '',
                    'paid_at'  => current_time('mysql'),
                ),
                array('order_num' => $data['trade_order_id']),
                array('%d', '%s', '%s'),
                array('%s')
            );
            
            // 如果是会员订单，激活会员
            if (class_exists('VDP\\Member')) {
                \VDP\Member::activate_from_order($data['trade_order_id']);
            }
            
            echo 'success';
            exit;
        }
        
        echo 'failed';
        exit;
    }
    
    /**
     * 支付成功后的跳转
     */
    public static function handle_return() {
        $order_num = isset($_GET['trade_order_id']) ? sanitize_text_field($_GET['trade_order_id']) : '';
        if (!$order_num) return;
        
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE order_num = %s", $order_num));
        
        if ($order && $order->post_id) {
            wp_redirect(get_permalink($order->post_id));
            exit;
        }
    }
    
    /**
     * AJAX: 查询订单状态
     */
    public static function ajax_check_order() {
        $order_num = isset($_POST['order_num']) ? sanitize_text_field($_POST['order_num']) : '';
        if (!$order_num) {
            wp_send_json_error('参数错误');
        }
        
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE order_num = %s", $order_num));
        
        if (!$order) {
            wp_send_json_error('订单不存在');
        }
        
        wp_send_json_success(array(
            'status' => intval($order->status),
            'paid'   => $order->status == 1,
        ));
    }
}
