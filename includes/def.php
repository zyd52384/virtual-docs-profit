<?php
/**
 * 辅助函数和常量定义
 */

defined('ABSPATH') || exit;

// 支持的文件格式
function vdp_supported_formats() {
    return array(
        'pdf'   => 'PDF',
        'doc'   => 'Word',
        'docx'  => 'Word',
        'ppt'   => 'PPT',
        'pptx'  => 'PPT',
        'xls'   => 'Excel',
        'xlsx'  => 'Excel',
        'txt'   => 'TXT',
        'zip'   => 'ZIP',
        'rar'   => 'RAR',
        '7z'    => '7Z',
    );
}

/**
 * 获取文件格式标识（小图标样式类名）
 */
function vdp_get_format_badge($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $formats = vdp_supported_formats();
    
    if (isset($formats[$ext])) {
        return $ext;
    }
    return 'file';
}

/**
 * 获取格式标识HTML
 */
function vdp_get_format_badge_html($filename) {
    $ext = vdp_get_format_badge($filename);
    $name = strtoupper($ext);
    return '<span class="vdp-format-badge vdp-format-' . esc_attr($ext) . '">' . esc_html($name) . '</span>';
}

/**
 * 获取文件大小的可读格式
 */
function vdp_format_file_size($bytes, $precision = 2) {
    if ($bytes <= 0) return '0 B';
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    $i = floor(log($bytes, 1024));
    return round($bytes / pow(1024, $i), $precision) . ' ' . $units[$i];
}

/**
 * 安全的获取posts_zibpay meta数据
 */
function vdp_get_zibpay_meta($post_id = 0) {
    if (!$post_id) {
        global $post;
        $post_id = $post->ID;
    }
    $meta = get_post_meta($post_id, 'posts_zibpay', true);
    return is_array($meta) ? $meta : array();
}

/**
 * 检测是否为文库文章（当前文章是否有文库相关meta）
 */
function vdp_is_doc_post($post_id = 0) {
    $meta = vdp_get_zibpay_meta($post_id);
    return !empty($meta['vdp_doc_file']) || !empty($meta['vdp_cos_key']);
}

/**
 * 获取文库文件信息
 */
function vdp_get_doc_file_info($post_id = 0) {
    $meta = vdp_get_zibpay_meta($post_id);
    return array(
        'file_name' => isset($meta['vdp_file_name']) ? $meta['vdp_file_name'] : '',
        'file_size' => isset($meta['vdp_file_size']) ? $meta['vdp_file_size'] : 0,
        'file_ext'  => isset($meta['vdp_file_ext']) ? $meta['vdp_file_ext'] : '',
        'cos_key'   => isset($meta['vdp_cos_key']) ? $meta['vdp_cos_key'] : '',
    );
}
