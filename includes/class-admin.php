<?php
namespace VDP;

defined('ABSPATH') || exit;

class Admin {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        
        // AJAX 处理上传
        add_action('wp_ajax_vdp_upload_file', array($this, 'ajax_upload_file'));
        
        // 保存设置
        add_action('admin_init', array($this, 'register_settings'));
        
        // 会员管理 → 整合到用户列表
        add_filter('manage_users_columns', array($this, 'add_vip_column'));
        add_action('manage_users_custom_column', array($this, 'render_vip_column'), 10, 3);
        add_action('show_user_profile', array($this, 'render_user_vip_section'));
        add_action('edit_user_profile', array($this, 'render_user_vip_section'));
        add_action('profile_update', array($this, 'save_user_vip_fields'));
        add_action('user_register', array($this, 'save_user_vip_fields'));
    }
    
    /**
     * 添加管理菜单
     */
    public function add_admin_menu() {
        // 主菜单
        add_menu_page(
            '虚拟资料赚钱机',
            '资料赚钱机',
            'manage_options',
            'virtual-docs-profit',
            array($this, 'render_dashboard_page'),
            'dashicons-portfolio',
            30
        );
        
        // 子页面：批量上传
        add_submenu_page(
            'virtual-docs-profit',
            '批量上传文档',
            '批量上传',
            'manage_options',
            'vdp-upload',
            array($this, 'render_upload_page')
        );
        
        // 子页面：设置
        add_submenu_page(
            'virtual-docs-profit',
            '插件设置',
            '设置',
            'manage_options',
            'vdp-settings',
            array($this, 'render_settings_page')
        );
        
        // 子页面：会员管理
        add_submenu_page(
            'virtual-docs-profit',
            '会员管理',
            '会员管理',
            'manage_options',
            'vdp-members',
            array($this, 'render_members_page')
        );
        
        // 子页面：联系开发者
        add_submenu_page(
            'virtual-docs-profit',
            '联系开发者',
            '联系开发者',
            'read',
            'vdp-contact',
            array($this, 'render_contact_page')
        );
    }
    
    /**
     * 注册设置项
     */
    public function register_settings() {
        register_setting('vdp_cos_settings_group', 'vdp_cos_settings', array(
            'sanitize_callback' => array($this, 'sanitize_cos_settings')
        ));
        register_setting('vdp_cos_settings_group', 'vdp_hupijiao_settings', array(
            'sanitize_callback' => array($this, 'sanitize_hupijiao_settings')
        ));
        register_setting('vdp_cos_settings_group', 'vdp_membership_settings', array(
            'sanitize_callback' => array($this, 'sanitize_membership_settings')
        ));
    }
    
    /**
     * 配置校验
     */
    public function sanitize_cos_settings($input) {
        $output = array();
        $output['secret_id']    = isset($input['secret_id']) ? sanitize_text_field($input['secret_id']) : '';
        $output['secret_key']   = isset($input['secret_key']) ? sanitize_text_field($input['secret_key']) : '';
        $output['region']       = isset($input['region']) ? sanitize_text_field($input['region']) : 'ap-guangzhou';
        $output['bucket']       = isset($input['bucket']) ? sanitize_text_field($input['bucket']) : '';
        $output['cdn_domain']   = isset($input['cdn_domain']) ? esc_url_raw($input['cdn_domain']) : '';
        $output['preview_pages'] = isset($input['preview_pages']) ? intval($input['preview_pages']) : 3;
        
        // 去掉 cdn_domain 末尾斜杠
        $output['cdn_domain'] = rtrim($output['cdn_domain'], '/');
        
        // 去掉 https:// 前缀（内部使用）
        $output['cdn_domain'] = preg_replace('#^https?://#', '', $output['cdn_domain']);
        
        return $output;
    }
    
    public function sanitize_hupijiao_settings($input) {
        $output = array();
        $output['appid']     = isset($input['appid']) ? sanitize_text_field($input['appid']) : '';
        $output['appsecret'] = isset($input['appsecret']) ? sanitize_text_field($input['appsecret']) : '';
        return $output;
    }
    
    public function sanitize_membership_settings($input) {
        $output = array();
        $output['enabled'] = isset($input['enabled']) ? 1 : 0;
        $output['products'] = array();
        if (!empty($input['products']) && is_array($input['products'])) {
            foreach ($input['products'] as $key => $product) {
                $key = sanitize_key($key);
                $output['products'][$key] = array(
                    'name'  => isset($product['name']) ? sanitize_text_field($product['name']) : '',
                    'price' => isset($product['price']) ? round(floatval($product['price']), 2) : 0,
                    'days'  => isset($product['days']) ? intval($product['days']) : 30,
                    'desc'  => isset($product['desc']) ? sanitize_text_field($product['desc']) : '',
                );
            }
        }
        return $output;
    }
    
    /**
     * 加载管理后台资源
     */
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'vdp-upload') === false && strpos($hook, 'virtual-docs-profit') === false) {
            return;
        }
        
        wp_enqueue_style('vdp-admin', VDP_PLUGIN_URL . 'assets/css/wenku.css', array(), VDP_VERSION);
        wp_enqueue_script('vdp-upload', VDP_PLUGIN_URL . 'assets/js/wenku-upload.js', array('jquery'), VDP_VERSION, true);
        
        // 获取所有分类
        $categories = get_categories(array(
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ));
        
        $cat_options = array();
        foreach ($categories as $cat) {
            $cat_options[] = array(
                'id'   => $cat->term_id,
                'name' => $cat->name,
                'parent' => $cat->parent,
            );
        }
        
        wp_localize_script('vdp-upload', 'vdp_ajax', array(
            'ajax_url'    => admin_url('admin-ajax.php'),
            'nonce'       => wp_create_nonce('vdp_upload_nonce'),
            'categories'  => $cat_options,
            'max_upload'  => wp_max_upload_size(),
            'max_upload_mb' => round(wp_max_upload_size() / 1024 / 1024),
            'uploading_text' => '上传处理中...',
            'success_text'   => '处理完成',
            'error_text'     => '处理失败',
        ));
    }
    
    /**
     * Dashboard 首页
     */
    public function render_dashboard_page() {
        $cos = new COS();
        $configured = $cos->is_configured();
        $doc_count = $this->get_doc_post_count();
        ?>
        <div class="wrap vdp-dashboard">
            <h1>虚拟资料赚钱机</h1>
            
            <div class="vdp-stats-grid">
                <div class="vdp-stat-card">
                    <div class="vdp-stat-number"><?php echo $doc_count; ?></div>
                    <div class="vdp-stat-label">文库文档数</div>
                </div>
                <div class="vdp-stat-card">
                    <div class="vdp-stat-number"><?php echo $configured ? '✅' : '❌'; ?></div>
                    <div class="vdp-stat-label">COS 配置状态</div>
                </div>
            </div>
            
            <div class="vdp-quick-actions">
                <h2>快速操作</h2>
                <a href="<?php echo admin_url('admin.php?page=vdp-upload'); ?>" class="button button-primary button-hero">
                    批量上传文档
                </a>
                <a href="<?php echo admin_url('admin.php?page=vdp-settings'); ?>" class="button button-hero">
                    插件设置
                </a>
            </div>
            
            <div class="vdp-setup-checklist">
                <h2>使用步骤</h2>
                <ol>
                    <li class="<?php echo $configured ? 'vdp-done' : ''; ?>">
                        在插件设置中配置腾讯云 COS 参数
                    </li>
                    <li>
                        在 WordPress 后台创建「文库资料」分类（或使用现有分类）
                    </li>
                    <li>
                        在子比主题设置中，将该分类设为「文档模式」
                    </li>
                    <li>
                        到批量上传页面，选择文件开始上传
                    </li>
                </ol>
            </div>
        </div>
        <?php
    }
    
    /**
     * 批量上传页面
     */
    public function render_upload_page() {
        $cos = new COS();
        $cos_config = $cos->get_config();
        if (!$cos->is_configured()) {
            echo '<div class="wrap"><h1>批量上传文档</h1>';
            echo '<div class="notice notice-error"><p>请先在「设置」中配置腾讯云 COS 参数。</p>';
            echo '<p><a href="' . admin_url('admin.php?page=vdp-settings') . '" class="button">前往设置</a></p></div></div>';
            return;
        }
        ?>
        <div class="wrap vdp-upload-page">
            <h1>批量上传文档</h1>
            
            <!-- 上传设置 -->
            <div class="vdp-upload-settings">
                <div class="vdp-upload-field">
                    <label>选择分类</label>
                    <select id="vdp-category-select">
                        <option value="">— 选择分类 —</option>
                    </select>
                </div>
                
                <div class="vdp-upload-field">
                    <label>付费设置</label>
                    <div class="vdp-pay-fields">
                        <select id="vdp-pay-type">
                            <option value="0">免费下载</option>
                            <option value="1">付费下载</option>
                        </select>
                        <input type="number" id="vdp-pay-price" placeholder="价格 (元)" min="0" step="0.01" style="display:none;">
                    </div>
                </div>
                
                <div class="vdp-upload-field">
                    <label>VIP限制</label>
                    <select id="vdp-vip-limit">
                        <option value="0">不限VIP</option>
                        <option value="1">VIP1及以上</option>
                        <option value="2">仅VIP2</option>
                    </select>
                </div>
                
                <div class="vdp-upload-field">
                    <label>文档预览页数</label>
                    <input type="number" id="vdp-preview-pages" value="<?php echo esc_attr($cos_config['preview_pages']); ?>" min="0" max="20" step="1">
                    <p class="vdp-field-desc">使用腾讯云数据万象生成前 N 页预览图，填 0 则不预览</p>
                </div>
            </div>
            
            <!-- 拖拽上传区域 -->
            <div class="vdp-dropzone" id="vdp-dropzone">
                <div class="vdp-dropzone-content">
                    <span class="vdp-dropzone-icon">📁</span>
                    <p>拖放文件到这里，或点击选择文件</p>
                    <p class="vdp-dropzone-hint">支持 PDF、DOC、DOCX、PPT、PPTX、XLS、XLSX、TXT、ZIP、RAR、7Z</p>
                    <p class="vdp-dropzone-hint">单个文件最大 <?php echo round(wp_max_upload_size() / 1024 / 1024); ?>MB</p>
                </div>
                <input type="file" id="vdp-file-input" multiple 
                       accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt,.zip,.rar,.7z"
                       class="vdp-file-input-invisible">
            </div>
            
            <!-- 文件列表 -->
            <div id="vdp-file-list" class="vdp-file-list"></div>
            
            <!-- 操作按钮 -->
            <div class="vdp-actions" style="display:none;" id="vdp-actions">
                <button id="vdp-start-upload" class="button button-primary button-hero">
                    开始上传并发布
                </button>
                <span class="vdp-upload-status" id="vdp-upload-status"></span>
            </div>
            
            <!-- 进度/结果 -->
            <div id="vdp-progress" class="vdp-progress-wrap" style="display:none;">
                <h3>处理进度</h3>
                <div class="vdp-progress-bar">
                    <div class="vdp-progress-fill" id="vdp-progress-fill"></div>
                </div>
                <div class="vdp-progress-text" id="vdp-progress-text">准备中...</div>
                <div class="vdp-results" id="vdp-results"></div>
            </div>
        </div>
        <?php
    }
    
    /**
     * 设置页面
     */
    public function render_settings_page() {
        $cos = new COS();
        $config = $cos->get_config();
        $hupijiao_config = \VDP\Pay::get_config();
        $member_config = get_option('vdp_membership_settings', array(
            'enabled'  => false,
            'products' => \VDP\Member::get_products(),
        ));
        
        // 确保所有内置产品在设置页显示
        if (!isset($member_config['products'])) $member_config['products'] = array();
        $member_config['products'] = array_merge(\VDP\Member::get_products(), $member_config['products']);
        ?>
        <div class="wrap vdp-settings-page">
            <h1>虚拟资料赚钱机 - 设置</h1>
            
            <form method="post" action="options.php">
                <?php settings_fields('vdp_cos_settings_group'); ?>
                
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="secret_id">SecretId</label></th>
                            <td>
                                <input type="text" id="secret_id" name="vdp_cos_settings[secret_id]" 
                                       value="<?php echo esc_attr($config['secret_id']); ?>" class="regular-text">
                                <p class="description">腾讯云 API 密钥 SecretId（访问管理 → API密钥管理）</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="secret_key">SecretKey</label></th>
                            <td>
                                <input type="password" id="secret_key" name="vdp_cos_settings[secret_key]" 
                                       value="<?php echo esc_attr($config['secret_key']); ?>" class="regular-text">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bucket">存储桶 (Bucket)</label></th>
                            <td>
                                <input type="text" id="bucket" name="vdp_cos_settings[bucket]" 
                                       value="<?php echo esc_attr($config['bucket']); ?>" class="regular-text">
                                <p class="description">例如：docs-1250000000</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="region">地域 (Region)</label></th>
                            <td>
                                <input type="text" id="region" name="vdp_cos_settings[region]" 
                                       value="<?php echo esc_attr($config['region']); ?>" class="regular-text">
                                <p class="description">例如：ap-guangzhou（广州）、ap-shanghai（上海）、ap-beijing（北京）</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="cdn_domain">CDN 域名（可选）</label></th>
                            <td>
                                <input type="text" id="cdn_domain" name="vdp_cos_settings[cdn_domain]" 
                                       value="<?php echo esc_attr($config['cdn_domain']); ?>" class="regular-text" 
                                       placeholder="cdn.example.com">
                                <p class="description">配置 CDN 加速域名后，文档下载链接将使用 CDN 地址。留空则使用 COS 默认域名。</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="preview_pages">默认文档预览页数</label></th>
                            <td>
                                <input type="number" id="preview_pages" name="vdp_cos_settings[preview_pages]" 
                                       value="<?php echo esc_attr($config['preview_pages']); ?>" class="small-text" min="0" max="20">
                                <p class="description">上传时未单独设置预览页数的文档，默认预览前 N 页。填 0 不预览。</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
                
                <h2 style="margin-top:30px;">虎皮椒V3 支付配置</h2>
                <p style="margin-bottom:15px;">独立支付模块，替代子比主题自带的支付系统。<br>
                申请地址：<a href="https://admin.xunhupay.com" target="_blank">https://admin.xunhupay.com</a></p>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="hupijiao_appid">APPID</label></th>
                            <td>
                                <input type="text" id="hupijiao_appid" name="vdp_hupijiao_settings[appid]" 
                                       value="<?php echo esc_attr($hupijiao_config['appid']); ?>" class="regular-text">
                                <p class="description">虎皮椒后台 → 应用管理 → APPID</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="hupijiao_appsecret">APPSECRET</label></th>
                            <td>
                                <input type="password" id="hupijiao_appsecret" name="vdp_hupijiao_settings[appsecret]" 
                                       value="<?php echo esc_attr($hupijiao_config['appsecret']); ?>" class="regular-text">
                                <p class="description">虎皮椒后台 → 应用管理 → APPSECRET</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
                
                <h2 style="margin-top:30px;">会员设置</h2>
                <p style="margin-bottom:15px;">开启后，会员有效期内可免费下载全站资料。</p>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row">启用会员功能</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="vdp_membership_settings[enabled]" value="1" 
                                           <?php checked($member_config['enabled'], 1); ?>>
                                    开启会员系统
                                </label>
                            </td>
                        </tr>
                        <?php
                        $products = isset($member_config['products']) ? $member_config['products'] : array();
                        $i = 0;
                        foreach ($products as $key => $product):
                        ?>
                        <tr>
                            <th scope="row">产品 #<?php echo ($i + 1); ?></th>
                            <td>
                                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                                    <input type="hidden" name="vdp_membership_settings[products][<?php echo $key; ?>][key]" value="<?php echo esc_attr($key); ?>">
                                    标识：<code><?php echo esc_html($key); ?></code>
                                    &nbsp;名称：
                                    <input type="text" name="vdp_membership_settings[products][<?php echo $key; ?>][name]" 
                                           value="<?php echo esc_attr($product['name']); ?>" style="width:100px;">
                                    &nbsp;价格：
                                    <input type="number" step="0.01" name="vdp_membership_settings[products][<?php echo $key; ?>][price]" 
                                           value="<?php echo esc_attr($product['price']); ?>" style="width:80px;"> 元
                                    &nbsp;时长：
                                    <input type="number" name="vdp_membership_settings[products][<?php echo $key; ?>][days]" 
                                           value="<?php echo esc_attr($product['days']); ?>" style="width:60px;"> 天
                                    &nbsp;描述：
                                    <input type="text" name="vdp_membership_settings[products][<?php echo $key; ?>][desc]" 
                                           value="<?php echo esc_attr($product['desc']); ?>" style="width:150px;">
                                </div>
                            </td>
                        </tr>
                        <?php $i++; endforeach; ?>
                    </tbody>
                </table>
                
                <?php submit_button('保存设置'); ?>
            </form>
            
            <?php if ($cos->is_configured()): ?>
            <hr>
            <div class="vdp-connection-test">
                <h2>连接测试</h2>
                <button id="vdp-test-cos" class="button">测试 COS 连接</button>
                <span id="vdp-test-result" style="margin-left:10px;"></span>
            </div>
            <script>
            jQuery(document).ready(function($) {
                $('#vdp-test-cos').on('click', function() {
                    var btn = $(this);
                    var result = $('#vdp-test-result');
                    btn.prop('disabled', true);
                    result.html('测试中...');
                    
                    $.post(ajaxurl, {
                        action: 'vdp_upload_file',
                        test_connection: '1',
                        _ajax_nonce: '<?php echo wp_create_nonce("vdp_upload_nonce"); ?>'
                    }, function(res) {
                        if (res.success) {
                            result.html('<span style="color:green;">✅ 连接成功！' + (res.data.message || '') + '</span>');
                        } else {
                            result.html('<span style="color:red;">❌ ' + (res.data || '连接失败') + '</span>');
                        }
                    }).fail(function() {
                        result.html('<span style="color:red;">❌ 请求失败</span>');
                    }).always(function() {
                        btn.prop('disabled', false);
                    });
                });
            });
            </script>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * AJAX: 上传单个文件并创建文章
     */
    public function ajax_upload_file() {
        check_ajax_referer('vdp_upload_nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('权限不足');
        }
        
        // 连接测试模式
        if (isset($_POST['test_connection'])) {
            $cos = new COS();
            $test_result = $cos->test_connection();
            if (is_wp_error($test_result)) {
                wp_send_json_error($test_result->get_error_message());
            }
            wp_send_json_success($test_result);
        }
        
        // 接收参数
        $category_id = isset($_POST['category_id']) ? intval($_POST['category_id']) : 0;
        $pay_type    = isset($_POST['pay_type']) ? sanitize_text_field($_POST['pay_type']) : '0';
        $pay_price   = isset($_POST['pay_price']) ? floatval($_POST['pay_price']) : 0;
        $vip_limit   = isset($_POST['vip_limit']) ? intval($_POST['vip_limit']) : 0;
        $preview_pages = isset($_POST['preview_pages']) ? intval($_POST['preview_pages']) : 3;
        
        // 处理上传文件
        if (empty($_FILES['file'])) {
            wp_send_json_error('未收到文件');
        }
        
        $file = $_FILES['file'];
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors = array(
                UPLOAD_ERR_INI_SIZE   => '文件超过服务器上传限制',
                UPLOAD_ERR_FORM_SIZE  => '文件超过表单限制',
                UPLOAD_ERR_PARTIAL    => '文件仅部分上传',
                UPLOAD_ERR_NO_FILE    => '没有选择文件',
            );
            $msg = isset($errors[$file['error']]) ? $errors[$file['error']] : '上传错误 (#' . $file['error'] . ')';
            wp_send_json_error($msg);
        }
        
        $filename = $file['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        // 校验文件格式
        $formats = vdp_supported_formats();
        if (!isset($formats[$ext])) {
            wp_send_json_error('不支持的文件格式: .' . $ext);
        }
        
        $tmp_path = $file['tmp_name'];
        $file_size = filesize($tmp_path);
        
        // 根据原始文件扩展名确定 MIME 类型（不能用临时路径的扩展名）
        $mime_ext_map = array(
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
        );
        $content_type = isset($mime_ext_map[$ext]) ? $mime_ext_map[$ext] : 'application/octet-stream';
        
        // 1. 检查是否已存在相同 MD5 的文件
        $md5 = md5_file($tmp_path);
        $existing = $this->find_existing_by_md5($md5);
        if ($existing) {
            wp_send_json_error(array(
                'duplicate' => true,
                'message'   => '文件已存在: ' . get_the_title($existing),
                'post_id'   => $existing,
            ));
        }
        
        // 2. 上传到 COS
        $cos = new COS();
        $cos_key = $cos->generate_cos_key($filename);
        
        $upload_result = $cos->upload_file($tmp_path, $cos_key, $content_type);
        
        if (is_wp_error($upload_result)) {
            wp_send_json_error('COS 上传失败: ' . $upload_result->get_error_message());
        }
        
        $file_url = $upload_result['url'];
        
        // 3. 创建文章
        $post_title = pathinfo($filename, PATHINFO_FILENAME);
        $post_title = sanitize_text_field($post_title);
        
        // 如果标题为空，用文件名
        if (empty($post_title)) {
            $post_title = $filename;
        }
        
        // 构建 posts_zibpay meta（子比主题付费下载格式）
        $pay_download = array(
            array(
                'link' => $file_url,
                'name' => '下载文件',
                'more' => '',
            )
        );
        
        $is_free = ($pay_type === '0');
        
        $zibpay_meta = array(
            'pay_modo'          => $is_free ? '0' : '1',
            // pay_type: 'no' = 免费, '2' = 付费资源（子比标准值）
            'pay_type'          => $is_free ? 'no' : '2',
            'pay_price'         => $pay_price,
            'pay_original_price'=> 0,
            'pay_title'         => $is_free ? '免费下载' : '付费资源',
            'pay_doc'           => '虚拟资料文件：' . $filename,
            'pay_download'      => $pay_download,
            'pay_limit'         => $vip_limit,
            'vip_1_price'       => 0,
            'vip_2_price'       => 0,
            // 插件自定义字段（存放在 posts_zibpay 里以便统一管理）
            'vdp_file_name'     => $filename,
            'vdp_file_size'     => $file_size,
            'vdp_file_ext'      => $ext,
            'vdp_cos_key'       => $cos_key,
            'vdp_file_md5'      => $md5,
            'vdp_preview_pages' => $preview_pages,
        );
        
        $post_data = array(
            'post_title'    => $post_title,
            'post_content'  => '', // 预览由 shortcode 自动追加
            'post_status'   => 'publish',
            'post_type'     => 'post',
            'post_category' => $category_id ? array($category_id) : array(),
            'meta_input'    => array(
                'posts_zibpay' => $zibpay_meta,
            ),
        );
        
        $post_id = wp_insert_post($post_data, true);
        
        if (is_wp_error($post_id)) {
            // 上传COS成功但创建文章失败，删除COS文件
            $cos->delete_file($cos_key);
            wp_send_json_error('创建文章失败: ' . $post_id->get_error_message());
        }
        
        // 4. 设置缩略图（使用数据万象第一页预览图）
        $cos_settings = get_option('vdp_cos_settings', array());
        if (!empty($cos_settings['bucket']) && !empty($cos_settings['region'])) {
            $preview_url = 'https://' . $cos_settings['bucket'] . '.cos.' . $cos_settings['region'] . '.myqcloud.com/' . $cos_key . '?ci-process=doc-preview&page=1&dstType=jpg';
            
            // 子比主题的 thumbnail_url 存储在 zib_other_data 复合 meta 中
            if (function_exists('zib_update_option_meta')) {
                zib_update_option_meta('post_meta', 'thumbnail_url', $preview_url, $post_id);
            } else {
                // 手动兼容
                $other_data = get_post_meta($post_id, 'zib_other_data', true);
                if (!is_array($other_data)) $other_data = array();
                $other_data['thumbnail_url'] = $preview_url;
                update_post_meta($post_id, 'zib_other_data', $other_data);
            }
            
            // 清除已有的 SVG 缩略图
            $thumb_id = get_post_thumbnail_id($post_id);
            if ($thumb_id) {
                delete_post_thumbnail($post_id);
            }
        }
        
        wp_send_json_success(array(
            'post_id'    => $post_id,
            'post_title' => $post_title,
            'file_url'   => $file_url,
            'edit_link'  => get_edit_post_link($post_id, ''),
            'permalink'  => get_permalink($post_id),
        ));
    }
    
    /**
     * 通过 MD5 查找已存在的文章
     */
    private function find_existing_by_md5($md5) {
        global $wpdb;
        
        $meta_key = 'posts_zibpay';
        $posts = $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} 
             WHERE meta_key = %s 
             AND meta_value LIKE %s",
            $meta_key,
            '%' . $wpdb->esc_like($md5) . '%'
        ));
        
        if (!empty($posts)) {
            foreach ($posts as $pid) {
                $meta = get_post_meta($pid, $meta_key, true);
                if (isset($meta['vdp_file_md5']) && $meta['vdp_file_md5'] === $md5) {
                    return $pid;
                }
            }
        }
        
        return 0;
    }
    
    /**
     * 为文库文章设置缩略图（动态生成 SVG 格式图标）
     */
    private function set_doc_thumbnail($post_id, $ext) {
        $svg = $this->generate_format_svg($ext);
        if (!$svg) return;
        
        // 用 SVG 作为文章缩略图（直接保存到上传目录）
        $upload_dir = wp_upload_dir();
        $thumb_dir = $upload_dir['basedir'] . '/vdp-thumbnails/';
        
        if (!file_exists($thumb_dir)) {
            wp_mkdir_p($thumb_dir);
        }
        
        $thumb_file = $thumb_dir . $post_id . '-' . $ext . '.svg';
        file_put_contents($thumb_file, $svg);
        
        $attachment_id = $this->import_to_media_library($thumb_file, $post_id, 'image/svg+xml');
        if ($attachment_id) {
            set_post_thumbnail($post_id, $attachment_id);
        }
    }
    
    /**
     * 生成格式 SVG 图标
     */
    private function generate_format_svg($ext) {
        $colors = array(
            'pdf'  => '#e74c3c',
            'doc'  => '#2980b9',
            'docx' => '#2980b9',
            'ppt'  => '#e67e22',
            'pptx' => '#e67e22',
            'xls'  => '#27ae60',
            'xlsx' => '#27ae60',
            'txt'  => '#7f8c8d',
            'zip'  => '#8e44ad',
            'rar'  => '#8e44ad',
            '7z'   => '#8e44ad',
        );
        
        $color = isset($colors[$ext]) ? $colors[$ext] : '#95a5a6';
        $label = strtoupper($ext);
        
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 240" width="200" height="240">
            <rect x="10" y="10" width="180" height="220" rx="12" fill="#f5f5f5" stroke="#ddd" stroke-width="2"/>
            <rect x="10" y="10" width="180" height="60" rx="12" fill="' . $color . '"/>
            <rect x="10" y="50" width="180" height="20" fill="' . $color . '"/>
            <text x="100" y="50" text-anchor="middle" fill="white" font-size="32" font-weight="bold" font-family="Arial">' . $label . '</text>
            <line x1="40" y1="100" x2="160" y2="100" stroke="#ddd" stroke-width="2"/>
            <line x1="40" y1="120" x2="140" y2="120" stroke="#eee" stroke-width="2"/>
            <line x1="40" y1="135" x2="150" y2="135" stroke="#eee" stroke-width="2"/>
            <line x1="40" y1="150" x2="130" y2="150" stroke="#eee" stroke-width="2"/>
            <line x1="40" y1="165" x2="145" y2="165" stroke="#eee" stroke-width="2"/>
            <line x1="40" y1="180" x2="120" y2="180" stroke="#eee" stroke-width="2"/>
            <line x1="40" y1="195" x2="135" y2="195" stroke="#eee" stroke-width="2"/>
            <line x1="40" y1="210" x2="125" y2="210" stroke="#eee" stroke-width="2"/>
        </svg>';
    }
    
    /**
     * 导入文件到媒体库
     */
    private function import_to_media_library($file_path, $post_id = 0, $mime_type = '') {
        if (!file_exists($file_path)) return 0;
        
        $filename = basename($file_path);
        
        if (empty($mime_type)) {
            $wp_filetype = wp_check_filetype($filename, null);
            $mime_type = $wp_filetype['type'];
        }
        
        $attachment = array(
            'post_mime_type' => $mime_type,
            'post_title'     => sanitize_file_name($filename),
            'post_content'   => '',
            'post_status'    => 'inherit',
        );
        
        $attach_id = wp_insert_attachment($attachment, $file_path, $post_id);
        return $attach_id;
    }
    
    /**
     * 获取文库文章数量
     */
    private function get_doc_post_count() {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} 
             WHERE meta_key = 'posts_zibpay' 
             AND meta_value LIKE '%vdp_file_name%'"
        );
    }
    
    /**
     * 会员管理页面
     */
    /**
     * 在用户列表中添加「会员」列
     */
    public function add_vip_column($columns) {
        $columns['vdp_vip'] = '会员';
        return $columns;
    }

    /**
     * 渲染用户列表中的会员状态
     */
    public function render_vip_column($output, $column_name, $user_id) {
        if ($column_name !== 'vdp_vip') return $output;
        
        $member = \VDP\Member::has_active_membership($user_id);
        $products = \VDP\Member::get_products();
        
        if ($member) {
            $level_name = '';
            foreach ($products as $key => $p) {
                if ($key === $member['level']) { $level_name = $p['name']; break; }
            }
            $remaining = $member['remaining_days'] >= 99999 ? '永久' : $member['remaining_days'] . '天';
            return '<span style="color:#27ae60;font-weight:600;">' . esc_html($level_name) . '</span><br><small style="color:#999;">剩余' . $remaining . '</small>';
        } else {
            return '<span style="color:#999;">-</span>';
        }
    }

    /**
     * 在用户编辑页显示会员信息 + 快捷操作
     */
    public function render_user_vip_section($user) {
        if (!current_user_can('edit_users')) return;
        
        $member = \VDP\Member::has_active_membership($user->ID);
        $products = \VDP\Member::get_products();
        ?>
        <h2>虚拟资料赚钱机 - 会员信息</h2>
        <table class="form-table">
            <tr>
                <th>当前状态</th>
                <td>
                    <?php if ($member): 
                        $level_name = '';
                        foreach ($products as $key => $p) {
                            if ($key === $member['level']) { $level_name = $p['name']; break; }
                        }
                        $remaining = $member['remaining_days'] >= 99999 ? '永久有效' : '剩余 ' . $member['remaining_days'] . ' 天';
                    ?>
                        <span style="color:#27ae60;font-weight:600;">✅ <?php echo esc_html($level_name); ?></span>
                        <br><small><?php echo $remaining; ?>（<?php echo $member['end_date']; ?> 到期）</small>
                    <?php else: ?>
                        <span style="color:#999;">无有效会员</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th>手动设置会员</th>
                <td>
                    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                        <select name="vdp_level_for_<?php echo $user->ID; ?>">
                            <?php foreach ($products as $key => $p): ?>
                            <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($p['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="number" name="vdp_extra_days_for_<?php echo $user->ID; ?>" value="30" style="width:70px;" min="0" placeholder="天数">
                        <span style="font-size:12px;color:#999;">（填0为终身）</span>
                        <span style="color:#999;font-size:12px;">← 保存用户后生效</span>
                    </div>
                </td>
            </tr>
        </table>
        <?php
    }
    
    /**
     * 保存用户编辑页的会员字段
     */
    public function save_user_vip_fields($user_id) {
        if (!current_user_can('edit_users')) return;
        
        // 检查是否有我们的字段被提交
        $level_key = 'vdp_level_for_' . $user_id;
        $days_key  = 'vdp_extra_days_for_' . $user_id;
        
        if (!isset($_POST[$level_key]) || !isset($_POST[$days_key])) return;
        
        $level = sanitize_text_field($_POST[$level_key]);
        $extra_days = intval($_POST[$days_key]);
        
        if (!$level || $extra_days < 0) return;
        
        $now = current_time('mysql');
        $end = ($extra_days <= 0) ? '2099-12-31 23:59:59' : date('Y-m-d H:i:s', current_time('timestamp') + $extra_days * 86400);
        
        global $wpdb;
        $mt = $wpdb->prefix . \VDP\Member::TABLE_NAME;
        $wpdb->insert($mt, array(
            'user_id'    => $user_id,
            'level'      => $level,
            'order_num'  => 'manual_' . time(),
            'start_date' => $now,
            'end_date'   => $end,
            'status'     => 1,
        ));
    }
    
    /**
     * 会员管理独立页面
     */
    public function render_members_page() {
        global $wpdb;
        $table = $wpdb->prefix . \VDP\Member::TABLE_NAME;
        
        // 处理手动添加/延期
        if (isset($_POST['vdp_add_member']) && wp_verify_nonce($_POST['_wpnonce'], 'vdp_member_action')) {
            $user_input = trim($_POST['user_id']);
            $level = sanitize_text_field($_POST['level']);
            $extra_days = intval($_POST['extra_days']);
            
            $user_id = is_numeric($user_input) ? intval($user_input) : 0;
            if (!$user_id) {
                $user = get_user_by('login', $user_input);
                $user_id = $user ? $user->ID : 0;
            }
            
            if ($user_id && $level && $extra_days >= 0) {
                $now = current_time('mysql');
                $end = ($extra_days <= 0) ? '2099-12-31 23:59:59' : date('Y-m-d H:i:s', current_time('timestamp') + $extra_days * 86400);
                $mt = $wpdb->prefix . \VDP\Member::TABLE_NAME;
                $wpdb->insert($mt, array(
                    'user_id'    => $user_id,
                    'level'      => $level,
                    'order_num'  => 'manual_' . time(),
                    'start_date' => $now,
                    'end_date'   => $end,
                    'status'     => 1,
                ));
                echo '<div class="notice notice-success"><p>会员已添加/续期成功！</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>参数错误：请检查用户ID/用户名和等级</p></div>';
            }
        }
        
        $members = $wpdb->get_results(
            "SELECT m.*, u.user_login, u.display_name, u.user_email 
             FROM {$table} m LEFT JOIN {$wpdb->users} u ON m.user_id = u.ID 
             ORDER BY m.created_at DESC LIMIT 200"
        );
        ?>
        <div class="wrap">
            <h1>会员管理</h1>
            <form method="post" style="background:#fff;padding:16px;border:1px solid #e8e8e8;border-radius:8px;margin-bottom:20px;">
                <?php wp_nonce_field('vdp_member_action'); ?>
                <label>用户ID/用户名：<input type="text" name="user_id" placeholder="输入用户ID" style="width:100px;"></label>
                <label>等级：
                    <select name="level">
                        <?php foreach (\VDP\Member::get_products() as $key => $p): ?>
                        <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($p['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>天数（0=终身）：<input type="number" name="extra_days" value="30" style="width:60px;" min="0"></label>
                <button type="submit" name="vdp_add_member" class="button button-primary">添加/续期</button>
            </form>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th>用户</th><th>等级</th><th>开始</th><th>到期</th><th>状态</th><th>订单</th></tr></thead>
                <tbody>
                <?php if ($members): foreach ($members as $m):
                    $active = ($m->status == 1 && ($m->end_date >= '2099-01-01' || $m->end_date > current_time('mysql')));
                    $lname = '未知';
                    foreach (\VDP\Member::get_products() as $k => $p) { if ($k === $m->level) { $lname = $p['name']; break; } }
                ?>
                    <tr>
                        <td><?php echo esc_html($m->display_name ?: $m->user_login); ?><br><small><?php echo esc_html($m->user_email); ?></small></td>
                        <td><?php echo esc_html($lname); ?></td>
                        <td><?php echo $m->start_date > '2000-01-01' ? $m->start_date : '-'; ?></td>
                        <td><?php echo $m->end_date >= '2099-01-01' ? '永久' : ($m->end_date > '2000-01-01' ? $m->end_date : '-'); ?></td>
                        <td><?php echo $active ? '<span style="color:green;font-weight:600;">有效</span>' : '<span style="color:#999;">已过期</span>'; ?></td>
                        <td><small><?php echo esc_html($m->order_num); ?></small></td>
                    </tr>
                <?php endforeach; else: ?><tr><td colspan="6">暂无记录</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    /**
     * 联系开发者页面
     */
    public function render_contact_page() {
        ?>
        <div class="wrap" style="max-width:800px;">
            <h1>联系开发者</h1>
            <div style="background:#fff;border:1px solid #e8e8e8;border-radius:12px;padding:32px;margin-top:20px;line-height:1.8;">
                <p style="font-size:16px;color:#333;">
                    自己做虚拟产品多年，现在用AI手搓了这么个插件，我会根据虚拟产品项目的运营点，
                    不断的完善这个插件，比如自动更新，自动转发小红书、公众号等功能，
                    让它真正成为<strong>AI时代自动赚钱的机器</strong>。
                </p>
                <p style="font-size:16px;color:#333;">
                    对虚拟产品感兴趣的朋友欢迎联系交流。
                </p>
                <div style="background:#f0f8ff;border-left:4px solid #1677ff;padding:16px 20px;margin:20px 0;border-radius:4px;">
                    <div style="font-size:16px;font-weight:600;color:#1677ff;margin-bottom:8px;">
                        📱 微信：baomafenxiang520
                    </div>
                    <div style="font-size:14px;color:#666;">
                        备注：wordpress插件
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
