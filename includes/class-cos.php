<?php
namespace VDP;

/**
 * 腾讯云 COS 对象存储处理类
 * 签名算法参考：腾讯云 COS XML API 文档
 */
defined('ABSPATH') || exit;

class COS {
    
    private $secret_id;
    private $secret_key;
    private $region;
    private $bucket;
    private $cdn_domain;
    private $preview_pages;
    
    public function __construct() {
        $settings = get_option('vdp_cos_settings', array());
        $this->secret_id     = isset($settings['secret_id']) ? trim($settings['secret_id']) : '';
        $this->secret_key    = isset($settings['secret_key']) ? trim($settings['secret_key']) : '';
        $this->region        = isset($settings['region']) ? trim($settings['region']) : 'ap-guangzhou';
        $this->bucket        = isset($settings['bucket']) ? trim($settings['bucket']) : '';
        $this->cdn_domain    = isset($settings['cdn_domain']) ? trim($settings['cdn_domain']) : '';
        $this->preview_pages = isset($settings['preview_pages']) ? intval($settings['preview_pages']) : 3;
    }
    
    /**
     * 检查配置是否完整
     */
    public function is_configured() {
        return !empty($this->secret_id) && !empty($this->secret_key) && !empty($this->bucket);
    }
    
    /**
     * 获取配置
     */
    public function get_config() {
        return array(
            'secret_id'     => $this->secret_id,
            'secret_key'    => $this->secret_key,
            'region'        => $this->region,
            'bucket'        => $this->bucket,
            'cdn_domain'    => $this->cdn_domain,
            'preview_pages' => $this->preview_pages,
        );
    }
    
    /**
     * 获取完整的存储桶域名
     */
    public function get_bucket_endpoint() {
        return $this->bucket . '.cos.' . $this->region . '.myqcloud.com';
    }
    
    /**
     * URL 安全的 rawurlencode（保留 / 用于路径）
     */
    private function url_encode_path($path) {
        // 路径中的中文和特殊字符需要 URL 编码，但保留 /
        $parts = explode('/', $path);
        $encoded = array();
        foreach ($parts as $part) {
            $encoded[] = rawurlencode($part);
        }
        return implode('/', $encoded);
    }
    
    /**
     * 生成 COS XML API 签名
     * 
     * @param string $method      HTTP 方法 (GET/PUT/DELETE/HEAD)
     * @param string $uri_path    对象路径（如 /docs/2026/05/file.pdf）
     * @param array  $headers     请求头 [key => value]
     * @param array  $params      URL 查询参数 [key => value]
     * @param int    $expires     签名有效期（秒）
     * @return string             Authorization 头部值
     */
    private function build_authorization($method, $uri_path, $headers = array(), $params = array(), $expires = 3600) {
        $start_time = time();
        $end_time = $start_time + $expires;
        $key_time = $start_time . ';' . $end_time;
        
        // 1. 计算 SignKey
        $sign_key = hash_hmac('sha1', $key_time, $this->secret_key);
        
        // 2. 构建格式化请求（HttpString）
        // 方法（COS 使用小写）
        $http_method = strtolower($method);
        
        // URI 路径（注意：COS 签名使用原始路径，不进行 URL 编码）
        $uri = '/' . ltrim($uri_path, '/');
        
        // 查询参数（按 key 排序，URL编码）
        ksort($params);
        $query_parts = array();
        // 处理 x-cos-security-token 需要特殊处理
        foreach ($params as $k => $v) {
            $encoded_key = strtolower(rawurlencode($k));
            $encoded_val = rawurlencode($v);
            $query_parts[] = $encoded_key . '=' . $encoded_val;
        }
        $query_string = implode('&', $query_parts);
        
        // 请求头（按 key 排序，key 转小写，value 去两端空格）
        // 必须包含 Host
        if (!isset($headers['Host'])) {
            $headers['Host'] = $this->get_bucket_endpoint();
        }
        ksort($headers);
        $header_lines = array();
        $header_list = array();
        foreach ($headers as $k => $v) {
            $key = strtolower(trim($k));
            $val = trim($v);
            if ($key === '' || $val === '') continue;
            $header_lines[] = $key . '=' . $val;
            $header_list[] = $key;
        }
        $header_string = implode("\n", $header_lines);
        
        // 3. 构建 HttpString
        $http_string = $http_method . "\n" . $uri . "\n" . $query_string . "\n" . $header_string . "\n";
        
        // 4. 构建 StringToSign
        $sha1_http = sha1($http_string);
        $string_to_sign = "sha1\n" . $key_time . "\n" . $sha1_http . "\n";
        
        // 5. 计算签名
        $signature = hash_hmac('sha1', $string_to_sign, $sign_key);
        
        // 6. 构建 Authorization
        $header_list_str = implode(';', $header_list);
        $param_list_str = implode(';', array_keys($params));
        
        $auth = 'q-sign-algorithm=sha1' .
                '&q-ak=' . $this->secret_id .
                '&q-sign-time=' . $key_time .
                '&q-key-time=' . $key_time .
                '&q-header-list=' . $header_list_str .
                '&q-url-param-list=' . $param_list_str .
                '&q-signature=' . $signature;
        
        return $auth;
    }
    
    /**
     * 上传文件到 COS（使用 cURL 直接发送）
     *
     * @param string $local_path    本地文件路径
     * @param string $cos_key       COS 对象键
     * @param string $content_type  文件 MIME 类型，留空自动从路径检测
     * @return array|\WP_Error
     */
    public function upload_file($local_path, $cos_key, $content_type = '') {
        if (!$this->is_configured()) {
            return new \WP_Error('cos_not_configured', 'COS 尚未配置，请在插件设置中填写 COS 参数');
        }
        
        if (!file_exists($local_path)) {
            return new \WP_Error('file_not_found', '文件不存在: ' . $local_path);
        }
        
        $file_content = file_get_contents($local_path);
        if ($file_content === false) {
            return new \WP_Error('file_read_error', '无法读取文件');
        }
        
        if (empty($content_type)) {
            $content_type = $this->get_mime_type($local_path);
        }
        
        $endpoint = $this->get_bucket_endpoint();
        $uri_path = '/' . ltrim($cos_key, '/');
        
        // 只签名 Host 头部，最小化签名范围
        $headers = array(
            'Host' => $endpoint,
        );
        
        // 生成签名（只对 Host 签名）
        $authorization = $this->build_authorization('PUT', $uri_path, $headers);
        
        // URL
        $url = 'https://' . $endpoint . $this->url_encode_path($uri_path);
        
        // 使用 cURL 发送 PUT 请求
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL             => $url,
            CURLOPT_CUSTOMREQUEST   => 'PUT',
            CURLOPT_POSTFIELDS      => $file_content,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_HEADER          => true,
            CURLOPT_TIMEOUT         => 600,
            CURLOPT_SSL_VERIFYPEER  => false,
            CURLOPT_HTTPHEADER      => array(
                'Host: ' . $endpoint,
                'Content-Type: ' . $content_type,
                'Content-Length: ' . strlen($file_content),
                'Authorization: ' . $authorization,
            ),
        ));
        
        $raw_response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return new \WP_Error('cos_curl_error', 'cURL 错误: ' . $error);
        }
        
        if ($http_code !== 200) {
            // 提取响应体（去掉头部）
            $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $body = substr($raw_response, $header_size);
            return new \WP_Error('cos_upload_error', '上传到 COS 失败 (HTTP ' . $http_code . '): ' . substr($body, 0, 500));
        }
        
        $file_url = $this->get_file_url($cos_key);
        
        return array(
            'url' => $file_url,
            'key' => $cos_key,
        );
    }
    
    /**
     * 从 COS 删除文件
     */
    public function delete_file($cos_key) {
        if (!$this->is_configured()) {
            return false;
        }
        
        $endpoint = $this->get_bucket_endpoint();
        $uri_path = '/' . ltrim($cos_key, '/');
        
        $headers = array(
            'Host' => $endpoint,
        );
        
        $authorization = $this->build_authorization('DELETE', $uri_path, $headers);
        $headers['Authorization'] = $authorization;
        
        $url = 'https://' . $endpoint . $this->url_encode_path($uri_path);
        
        $response = wp_remote_request($url, array(
            'method'  => 'DELETE',
            'headers' => $headers,
            'sslverify' => false,
        ));
        
        return !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 204;
    }
    
    /**
     * 获取文件的公开访问 URL
     */
    public function get_file_url($cos_key) {
        $path = $this->url_encode_path('/' . ltrim($cos_key, '/'));
        if ($this->cdn_domain) {
            return 'https://' . rtrim($this->cdn_domain, '/') . $path;
        }
        return 'https://' . $this->get_bucket_endpoint() . $path;
    }
    
    /**
     * 获取文件的临时签名 URL
     */
    public function get_private_file_url($cos_key, $expires = 3600) {
        $endpoint = $this->get_bucket_endpoint();
        $uri_path = '/' . ltrim($cos_key, '/');
        
        $headers = array(
            'Host' => $endpoint,
        );
        
        $authorization = $this->build_authorization('GET', $uri_path, $headers, array(), $expires);
        
        $url = 'https://' . $endpoint . $this->url_encode_path($uri_path);
        return $url . '?auth=' . rawurlencode($authorization);
    }
    
    /**
     * 生成 COS 对象键（安全文件名，保留字母数字和下划线）
     */
    public function generate_cos_key($filename, $post_id = 0) {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $raw_name = pathinfo($filename, PATHINFO_FILENAME);
        
        // 保留中文字符、字母、数字，其他转为下划线
        $safe_name = preg_replace('/[^\p{Han}\w\-]/u', '_', $raw_name);
        $safe_name = preg_replace('/_+/', '_', $safe_name);
        $safe_name = trim($safe_name, '_');
        
        // 如果处理完为空，使用 MD5
        if (empty($safe_name)) {
            $safe_name = 'file_' . substr(md5($filename), 0, 8);
        }
        
        $date_path = date('Y/m');
        $unique_id = uniqid() . '_' . wp_rand(1000, 9999);
        
        if ($post_id) {
            return 'docs/' . $date_path . '/' . $post_id . '_' . $unique_id . '_' . $safe_name . '.' . $ext;
        }
        
        return 'docs/' . $date_path . '/' . $unique_id . '_' . $safe_name . '.' . $ext;
    }
    
    /**
     * 根据文件扩展名获取 MIME 类型
     */
    private function get_mime_type($file_path) {
        $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        $mime_types = array(
            'pdf'  => 'application/pdf',
            'doc'  => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'ppt'  => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'xls'  => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'txt'  => 'text/plain',
            'zip'  => 'application/zip',
            'rar'  => 'application/vnd.rar',
            '7z'   => 'application/x-7z-compressed',
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif'  => 'image/gif',
        );
        return isset($mime_types[$ext]) ? $mime_types[$ext] : 'application/octet-stream';
    }
    
    /**
     * 测试 COS 连接（列出存储桶中文件）
     */
    public function test_connection() {
        if (!$this->is_configured()) {
            return new \WP_Error('cos_not_configured', 'COS 尚未配置');
        }
        
        $endpoint = $this->get_bucket_endpoint();
        $uri_path = '/';
        
        $headers = array(
            'Host' => $endpoint,
        );
        
        $params = array(
            'max-keys' => '1',
        );
        
        $authorization = $this->build_authorization('GET', $uri_path, $headers, $params);
        $headers['Authorization'] = $authorization;
        
        $url = 'https://' . $endpoint . '/?max-keys=1';
        
        $response = wp_remote_get($url, array(
            'headers' => $headers,
            'sslverify' => false,
            'timeout' => 15,
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code === 200) {
            $body = wp_remote_retrieve_body($response);
            // 解析 XML 获取桶内文件数量
            $xml = simplexml_load_string($body);
            $count = 0;
            if ($xml && isset($xml->Contents)) {
                $count = count($xml->Contents);
            }
            return array('message' => '连接成功，存储桶内有 ' . $count . ' 个文件');
        }
        
        $body = wp_remote_retrieve_body($response);
        return new \WP_Error('cos_test_failed', '连接测试失败 (HTTP ' . $status_code . '): ' . substr($body, 0, 300));
    }
}
