<?php
namespace VDP;

defined('ABSPATH') || exit;

/**
 * 独立会员系统
 * 时间维度会员等级，到期自动失效
 */
class Member {

    const TABLE_NAME = 'vdp_memberships';

    /**
     * 创建会员表
     */
    public static function create_table() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id         bigint(20)   NOT NULL AUTO_INCREMENT,
            user_id    bigint(20)   NOT NULL COMMENT '用户ID',
            level      varchar(32)  NOT NULL DEFAULT 'monthly' COMMENT '等级标识',
            order_num  varchar(64)  NOT NULL DEFAULT '' COMMENT '关联订单号',
            start_date datetime     NOT NULL COMMENT '开始时间',
            end_date   datetime     NOT NULL COMMENT '到期时间',
            status     tinyint(1)   NOT NULL DEFAULT 1 COMMENT '1有效 0已过期',
            created_at datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY status (status)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * 获取会员产品配置
     * 后台可自定义：名称、价格、时长（天）、描述
     */
    public static function get_products() {
        $settings = get_option('vdp_membership_settings', array());
        $saved = isset($settings['products']) ? $settings['products'] : array();

        // 内置默认产品（始终包含，确保新加的产品不会丢失）
        $defaults = array(
            'monthly' => array(
                'name'  => '月度会员',
                'price' => 29.9,
                'days'  => 30,
                'desc'  => '30天无限下载',
            ),
            'yearly' => array(
                'name'  => '年度会员',
                'price' => 199.0,
                'days'  => 365,
                'desc'  => '365天无限下载，最划算',
            ),
            'lifetime' => array(
                'name'  => '终身会员',
                'price' => 499.0,
                'days'  => 0,
                'desc'  => '永久无限下载',
            ),
        );

        // 合并：用已保存的覆盖默认，默认中没有的补上
        if (empty($saved)) {
            return $defaults;
        }
        $products = array_merge($defaults, $saved);
        return $products;
    }

    /**
     * 会员功能是否启用
     */
    public static function is_enabled() {
        $settings = get_option('vdp_membership_settings', array());
        return !empty($settings['enabled']);
    }

    /**
     * 检查用户是否有有效会员
     *
     * @param int $user_id
     * @return array|false 返回会员信息数组，false表示无有效会员
     */
    public static function has_active_membership($user_id = 0) {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }
        if (!$user_id) return false;

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE user_id = %d AND status = 1 ORDER BY end_date DESC LIMIT 1",
            $user_id
        ));

        if (!$row) return false;

        // 终身会员或长期有效会员（end_date >= 2099年）
        $is_lifetime = ($row->end_date >= '2099-01-01');

        if (!$is_lifetime) {
            // 检查是否过期
            $now = current_time('mysql');
            if ($row->end_date <= $now) {
                // 自动标记过期
                $wpdb->update($table, array('status' => 0), array('id' => $row->id));
                return false;
            }
        }

        return array(
            'id'         => intval($row->id),
            'level'      => $row->level,
            'start_date' => $row->start_date,
            'end_date'   => $row->end_date,
            'remaining_days' => $is_lifetime ? 99999 : ceil((strtotime($row->end_date) - current_time('timestamp')) / 86400),
        );
    }

    /**
     * 判断用户能否下载某篇文章（会员 OR 已购买）
     *
     * @param int $post_id
     * @param int $user_id
     * @return bool
     */
    public static function can_download($post_id, $user_id = 0) {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }
        if (!$user_id) return false;

        // 已购买此篇
        if (Pay::has_paid($post_id, $user_id)) {
            return true;
        }

        // 有有效会员
        if (self::is_enabled() && self::has_active_membership($user_id)) {
            return true;
        }

        return false;
    }

    /**
     * 激活会员
     * 虎皮椒回调成功后调用
     *
     * @param int    $user_id
     * @param string $level    产品标识
     * @param string $order_num
     * @return bool|int
     */
    public static function activate_membership($user_id, $level, $order_num = '') {
        $products = self::get_products();
        if (!isset($products[$level])) return false;

        $product = $products[$level];
        $days = intval($product['days']);
        $is_lifetime = ($days <= 0);

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        // 终身会员不可续费
        if (!$is_lifetime) {
            // 检查是否有未过期的同等级会员（续费场景）
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table WHERE user_id = %d AND level = %s AND status = 1 ORDER BY end_date DESC LIMIT 1",
                $user_id, $level
            ));

            if ($existing && strtotime($existing->end_date) > current_time('timestamp')) {
                // 续费：延长现有会员
                $new_end = date('Y-m-d H:i:s', strtotime($existing->end_date) + $days * 86400);
                $wpdb->update(
                    $table,
                    array('end_date' => $new_end, 'order_num' => $order_num),
                    array('id' => $existing->id)
                );
                return $existing->id;
            }
        }

        // 新会员（或终身会员）
        $now = current_time('mysql');
        $end = $is_lifetime ? '2099-12-31 23:59:59' : date('Y-m-d H:i:s', current_time('timestamp') + $days * 86400);

        $wpdb->insert($table, array(
            'user_id'    => $user_id,
            'level'      => $level,
            'order_num'  => $order_num,
            'start_date' => $now,
            'end_date'   => $end,
            'status'     => 1,
        ));

        return $wpdb->insert_id;
    }

    /**
     * 生成会员订单号
     */
    public static function generate_order_num() {
        return 'MVP' . date('YmdHis') . mt_rand(1000, 9999);
    }

    /**
     * AJAX: 购买会员
     */
    public static function ajax_buy_membership() {
        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error('请先登录');
        }

        $raw = array();
        $input = file_get_contents('php://input');
        if ($input) parse_str($input, $raw);
        $data = array_merge($_GET, $_POST, $raw);

        $level = isset($data['level']) ? sanitize_text_field($data['level']) : '';
        $pay_type = isset($data['pay_type']) ? sanitize_text_field($data['pay_type']) : 'wechat';

        $products = self::get_products();
        if (!isset($products[$level])) {
            wp_send_json_error('会员等级不存在');
        }

        $product = $products[$level];
        $price = floatval($product['price']);

        // 创建订单
        $order_num = self::generate_order_num();

        global $wpdb;
        $order_table = $wpdb->prefix . Pay::TABLE_NAME;
        $wpdb->insert($order_table, array(
            'order_num'  => $order_num,
            'post_id'    => 0,
            'user_id'    => $user_id,
            'pay_price'  => $price,
            'pay_type'   => $pay_type,
            'status'     => 0,
        ));

        // 调用虎皮椒
        $title = $product['name'];
        $result = Pay::initiate_pay($order_num, $price, $title, $pay_type);

        if (!empty($result['error'])) {
            wp_send_json_error($result['error']);
        }

        wp_send_json_success(array(
            'url_qrcode' => $result['url_qrcode'] ?? '',
            'url'        => $result['url'] ?? '',
            'order_num'  => $order_num,
            'level'      => $level,
        ));
    }

    /**
     * AJAX: 检查会员状态（前端轮询用）
     */
    public static function ajax_check_membership() {
        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error('未登录');
        }

        $member = self::has_active_membership($user_id);

        if ($member) {
            wp_send_json_success(array(
                'active' => true,
                'level'  => $member['level'],
                'end_date' => $member['end_date'],
                'remaining_days' => $member['remaining_days'],
            ));
        } else {
            wp_send_json_success(array('active' => false));
        }
    }

    /**
     * 从虎皮椒回调激活会员
     * 由 Pay::handle_notify() 调用
     *
     * @param string $order_num
     * @return bool
     */
    public static function activate_from_order($order_num) {
        global $wpdb;
        $order_table = $wpdb->prefix . Pay::TABLE_NAME;
        $order = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $order_table WHERE order_num = %s AND status = 1",
            $order_num
        ));
        if (!$order) return false;

        // 判断是否为会员订单（post_id=0 且 order_num 以 MVP 开头）
        if ($order->post_id == 0 && strpos($order_num, 'MVP') === 0) {
            // 从 products 中倒查 level
            $products = self::get_products();
            $found_level = '';
            foreach ($products as $key => $product) {
                if (abs(floatval($product['price']) - floatval($order->pay_price)) < 0.01) {
                    $found_level = $key;
                    break;
                }
            }
            if ($found_level) {
                self::activate_membership($order->user_id, $found_level, $order_num);
                return true;
            }
        }

        return false;
    }
}
