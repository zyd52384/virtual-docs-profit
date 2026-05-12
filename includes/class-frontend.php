<?php
namespace VDP;

defined('ABSPATH') || exit;

class Frontend {
    
    public function __construct() {
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        
        // 文档预览短代码
        add_shortcode('vdp_preview', array($this, 'preview_shortcode'));
        
        // 在文章内容中自动追加预览区域
        add_filter('the_content', array($this, 'append_preview_to_content'));
        
        // 在每个文章容器的 data 属性中注入文库信息（供 JS/CSS 使用）
        add_filter('post_class', array($this, 'add_doc_post_class'), 10, 3);
        
        // 注入前端 JS 用于添加格式角标
        add_action('wp_footer', array($this, 'inject_badge_script'), 99);
        
        // 强制加载子比支付模块（直接 script 标签输出）
        add_action('wp_footer', array($this, 'force_load_pay_module'), 100);
        
        // 移除子比自带的付费框（用我们的替代）
        add_action('wp', array($this, 'remove_zib_pay_box'));
    }
    
    /**
     * 加载前端资源
     */
    public function enqueue_assets() {
        wp_enqueue_style(
            'vdp-frontend',
            VDP_PLUGIN_URL . 'assets/css/wenku.css',
            array(),
            VDP_VERSION
        );
    }
    
    /**
     * 移除子比自带的付费框
     */
    public function remove_zib_pay_box() {
        if (!is_singular('post')) return;
        
        global $post;
        $file_info = vdp_get_doc_file_info($post->ID);
        if (empty($file_info['file_name'])) return;
        
        $position = function_exists('_pz') ? _pz('pay_box_position', 'top') : 'top';
        $map = array(
            'box_top'    => 'zib_single_before',
            'top'        => 'zib_single_box_content_before',
            'bottom'     => 'zib_article_content_after',
            'box_bottom' => 'zib_single_after',
        );
        $hook = isset($map[$position]) ? $map[$position] : 'zib_single_box_content_before';
        remove_action($hook, 'zibpay_posts_pay_content', 1);
    }
    
    /**
     * 独立支付处理器（使用我们自己的虎皮椒模块）
     */
    public function force_load_pay_module() {
        if (!is_singular('post')) return;
        
        global $post;
        $meta = vdp_get_zibpay_meta($post->ID);
        $file_info = vdp_get_doc_file_info($post->ID);
        if (empty($file_info['file_name'])) return;
        
        // 检查是否已配置虎皮椒
        $pay_configured = \VDP\Pay::is_configured();
        $user_id = get_current_user_id();
        $already_paid = $user_id ? \VDP\Pay::has_paid($post->ID, $user_id) : false;
        $member_enabled = \VDP\Member::is_enabled();
        $member_products = $member_enabled ? \VDP\Member::get_products() : array();
        $member_active = ($member_enabled && $user_id) ? \VDP\Member::has_active_membership($user_id) : false;
        
        $price = isset($meta['pay_price']) ? floatval($meta['pay_price']) : 0;
        $is_free = empty($meta['pay_type']) || $meta['pay_type'] === 'no';
        ?>
        <script>
        (function() {
            var vdpAjaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';
            var vdpPrice = '<?php echo $price; ?>';
            var vdpOrderNum = '';
            
            <?php if ($member_enabled && !empty($member_products)): ?>
            var vdpMembershipProducts = <?php echo json_encode($member_products); ?>;
            <?php else: ?>
            var vdpMembershipProducts = null;
            <?php endif; ?>
            
            // …… 以下继续现有 JS ……
            
            // 支付按钮点击（每个按钮自带支付方式，简单可靠）
            document.addEventListener('click', function(e) {
                var btn = e.target.closest('.vdp-pay-btn');
                if (!btn) return;
                e.preventDefault();
                
                var postId = btn.getAttribute('data-post-id');
                var payType = btn.getAttribute('data-pay-type') || 'wechat';
                
                btn.classList.add('disabled');
                btn.textContent = '处理中...';
                
                var data = new URLSearchParams();
                data.append('action', 'vdp_initiate_pay');
                data.append('post_id', postId);
                data.append('pay_type', payType);
                
                var xhr = new XMLHttpRequest();
                xhr.open('POST', vdpAjaxUrl, true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
                xhr.onload = function() {
                    btn.classList.remove('disabled');
                    btn.textContent = payType === 'wechat' ? '微信支付' : '支付宝';
                    
                    if (xhr.status === 200) {
                        try {
                            var res = JSON.parse(xhr.responseText);
                            if (res.success && res.data) {
                                var d = res.data;
                                vdpOrderNum = d.order_num || '';
                                if (d.url_qrcode) {
                                    showQrcode(d.url_qrcode, payType, vdpPrice);
                                    if (vdpOrderNum) startPolling(vdpOrderNum);
                                    return;
                                }
                                if (d.url) { window.location.href = d.url; return; }
                            } else {
                                alert(res.data || '支付发起失败');
                            }
                        } catch(e) {
                            alert('服务器返回异常');
                        }
                    } else {
                        alert('网络错误');
                    }
                };
                xhr.send(data.toString());
            });
            
            // 会员购买按钮点击
            document.addEventListener('click', function(e) {
                var btn = e.target.closest('.vdp-mp-btn');
                if (!btn) return;
                e.preventDefault();
                
                var level = btn.getAttribute('data-level');
                var payType = btn.getAttribute('data-pay-type') || 'wechat';
                var product = vdpMembershipProducts ? vdpMembershipProducts[level] : null;
                if (!product) { alert('会员产品不存在'); return; }
                
                btn.classList.add('disabled');
                var origText = btn.textContent;
                btn.textContent = '处理中...';
                
                var data = new URLSearchParams();
                data.append('action', 'vdp_buy_membership');
                data.append('level', level);
                data.append('pay_type', payType);
                
                var xhr = new XMLHttpRequest();
                xhr.open('POST', vdpAjaxUrl, true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
                xhr.onload = function() {
                    btn.classList.remove('disabled');
                    btn.textContent = origText;
                    
                    if (xhr.status === 200) {
                        try {
                            var res = JSON.parse(xhr.responseText);
                            if (res.success && res.data) {
                                var d = res.data;
                                if (d.url_qrcode) {
                                    showQrcode(d.url_qrcode, payType, product.price);
                                    var pollTimer = setInterval(function() {
                                        var cx = new XMLHttpRequest();
                                        cx.open('POST', vdpAjaxUrl, true);
                                        cx.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
                                        cx.onload = function() {
                                            if (cx.status === 200) {
                                                try {
                                                    var cr = JSON.parse(cx.responseText);
                                                    if (cr.success && cr.data && cr.data.paid) {
                                                        clearInterval(pollTimer);
                                                        jQuery('#zibpay_modal').modal('hide');
                                                        var overlay = document.getElementById('vdp-qrcode-overlay');
                                                        if (overlay) overlay.style.display = 'none';
                                                        alert('会员开通成功！页面即将刷新');
                                                        window.location.reload();
                                                    }
                                                } catch(e) {}
                                            }
                                        };
                                        cx.send('action=vdp_check_order&order_num=' + encodeURIComponent(d.order_num));
                                    }, 5000);
                                    setTimeout(function() { clearInterval(pollTimer); }, 300000);
                                    return;
                                }
                                if (d.url) { window.location.href = d.url; return; }
                            } else {
                                alert(res.data || '购买失败');
                            }
                        } catch(e) { alert('服务器返回异常'); }
                    } else {
                        alert('网络错误');
                    }
                };
                xhr.send(data.toString());
            });
            
            // 轮询订单状态
            function startPolling(orderNum) {
                var pollCount = 0;
                var timer = setInterval(function() {
                    pollCount++;
                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', vdpAjaxUrl, true);
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
                    xhr.onload = function() {
                        if (xhr.status === 200) {
                            try {
                                var res = JSON.parse(xhr.responseText);
                                if (res.success && res.data && res.data.paid) {
                                    clearInterval(timer);
                                    // 关闭二维码弹窗
                                    jQuery('#zibpay_modal').modal('hide');
                                    var overlay = document.getElementById('vdp-qrcode-overlay');
                                    if (overlay) overlay.style.display = 'none';
                                    // 显示成功并刷新
                                    alert('支付成功！页面即将刷新');
                                    window.location.reload();
                                }
                            } catch(e) {}
                        }
                    };
                    xhr.send('action=vdp_check_order&order_num=' + encodeURIComponent(orderNum));
                    // 最多轮询5分钟（60次×5秒）
                    if (pollCount > 60) clearInterval(timer);
                }, 5000);
            }
            
            // 显示支付二维码
            function showQrcode(url, type, price) {
                // 使用子比的弹窗
                var img = document.querySelector('#zibpay_modal .pay-qrcode img');
                if (img) {
                    img.src = url;
                    document.querySelector('#zibpay_modal .pay-payment').className = 'pay-payment ' + type;
                    try { jQuery('#zibpay_modal').modal('show'); } catch(e) {}
                    return;
                }
                // 备用弹窗
                var overlay = document.getElementById('vdp-qrcode-overlay');
                if (!overlay) {
                    overlay = document.createElement('div');
                    overlay.id = 'vdp-qrcode-overlay';
                    overlay.innerHTML = '<div class="vdp-qrcode-box">' +
                        '<div class="vdp-qrcode-close">&times;</div>' +
                        '<div class="vdp-qrcode-header"><span class="vdp-qrcode-type">扫码支付</span></div>' +
                        '<div class="vdp-qrcode-body">' +
                            '<img class="vdp-qrcode-img" src="" alt="支付二维码">' +
                            '<div class="vdp-qrcode-tip">请扫码支付</div>' +
                            '<div class="vdp-qrcode-amount">￥<span class="vdp-qrcode-price"></span></div>' +
                        '</div></div>';
                    document.body.appendChild(overlay);
                    overlay.addEventListener('click', function(e) {
                        if (e.target === overlay || e.target.closest('.vdp-qrcode-close')) {
                            overlay.style.display = 'none';
                        }
                    });
                }
                overlay.querySelector('.vdp-qrcode-img').src = url;
                overlay.querySelector('.vdp-qrcode-price').textContent = price;
                overlay.style.display = 'flex';
            }
        })();
        </script>
        <?php
    }
    
    /**
     * 给文库文章添加 data 属性和 CSS class
     */
    public function add_doc_post_class($classes, $class, $post_id) {
        $file_info = vdp_get_doc_file_info($post_id);
        if (!empty($file_info['file_name'])) {
            $classes[] = 'vdp-doc-post';
            $classes[] = 'vdp-format-' . esc_attr($file_info['file_ext']);
        }
        return $classes;
    }
    
    /**
     * 注入前端 JS：在文章缩略图上添加格式角标
     */
    public function inject_badge_script() {
        // 只在有文库文章的页面执行
        global $wp_query;
        $has_doc = false;
        if (!empty($wp_query->posts)) {
            foreach ($wp_query->posts as $p) {
                $info = vdp_get_doc_file_info($p->ID);
                if (!empty($info['file_name'])) {
                    $has_doc = true;
                    break;
                }
            }
        }
        if (!$has_doc && !is_singular()) return;
        
        ?>
        <script>
        (function() {
            // 文库文章数据
            var vdpDocs = <?php 
                $docs_data = array();
                if (!empty($wp_query->posts)) {
                    foreach ($wp_query->posts as $p) {
                        $info = vdp_get_doc_file_info($p->ID);
                        if (!empty($info['file_name'])) {
                            $docs_data[$p->ID] = array(
                                'ext'  => $info['file_ext'],
                                'name' => $info['file_name'],
                                'size' => vdp_format_file_size($info['file_size']),
                            );
                        }
                    }
                }
                echo json_encode($docs_data);
            ?>;
            
            // 为每篇文库文章的缩略图添加格式角标
            document.addEventListener('DOMContentLoaded', function() {
                var posts = document.querySelectorAll('.vdp-doc-post');
                posts.forEach(function(post) {
                    var postId = post.getAttribute('id');
                    if (!postId) return;
                    var id = postId.replace('post-', '');
                    var doc = vdpDocs[id];
                    if (!doc) return;
                    
                    // 在缩略图容器上添加角标
                    var thumb = post.querySelector('.item-thumbnail');
                    if (thumb) {
                        var badge = document.createElement('span');
                        badge.className = 'vdp-format-badge vdp-format-' + doc.ext;
                        badge.textContent = doc.ext.toUpperCase();
                        thumb.appendChild(badge);
                    }
                });
            });
        })();
        </script>
        <?php
    }
    
    /**
     * 文档预览短代码
     * 用法: [vdp_preview]
     */
    public function preview_shortcode($atts, $content = null) {
        global $post;
        if (!$post) return '';
        
        $file_info = vdp_get_doc_file_info($post->ID);
        if (empty($file_info['file_name'])) {
            return '<!-- 非文库文章 -->';
        }
        
        $file_name = esc_html($file_info['file_name']);
        $file_size = vdp_format_file_size($file_info['file_size']);
        $file_ext  = strtoupper(esc_html($file_info['file_ext']));
        
        $cos_key = isset($file_info['cos_key']) ? $file_info['cos_key'] : '';
        
        // 获取预览页数
        // 规则：文章有单独设置→用它；否则用全局设置；都没有→默认3
        $meta = vdp_get_zibpay_meta($post->ID);
        $global_settings = get_option('vdp_cos_settings', array());
        $global_pages = isset($global_settings['preview_pages']) ? intval($global_settings['preview_pages']) : 3;
        
        if (array_key_exists('vdp_preview_pages', $meta)) {
            $preview_pages = intval($meta['vdp_preview_pages']);
        } else {
            $preview_pages = $global_pages;
        }
        
        ob_start();
        ?>
        <div class="vdp-preview-wrapper">
            <div class="vdp-file-header">
                <span class="vdp-format-badge vdp-format-<?php echo esc_attr($file_info['file_ext']); ?>">
                    <?php echo $file_ext; ?>
                </span>
                <span class="vdp-file-name"><?php echo $file_name; ?></span>
                <span class="vdp-file-size"><?php echo $file_size; ?></span>
            </div>
            
            <?php if ($preview_pages > 0 && $cos_key): ?>
            <div class="vdp-ci-preview">
                <?php for ($i = 1; $i <= min($preview_pages, 20); $i++): ?>
                    <?php $img_url = $this->get_ci_preview_image_url($cos_key, $i); ?>
                    <div class="vdp-ci-preview-page">
                        <img src="<?php echo esc_url($img_url); ?>" 
                             alt="<?php echo esc_attr(sprintf('第%d页预览', $i)); ?>"
                             class="vdp-ci-preview-img"
                             loading="lazy"
                             onerror="this.parentElement.style.display='none'">
                    </div>
                <?php endfor; ?>
            </div>
            <?php else: ?>
            <div class="vdp-preview-placeholder">
                <div class="vdp-file-icon vdp-icon-<?php echo esc_attr($file_info['file_ext']); ?>">
                    <span class="vdp-icon-text"><?php echo $file_ext; ?></span>
                </div>
                <p class="vdp-preview-tip">
                    <?php _e('文档预览暂不可用，请在腾讯云 COS 控制台开启数据万象（CI）文档预览服务', 'virtual-docs-profit'); ?>
                </p>
            </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * 获取腾讯云数据万象（CI）文档预览图片 URL
     * 生成单页预览图片，格式为 jpg
     *
     * @param string $cos_key  COS 对象键
     * @param int    $page     页码（从1开始）
     * @return string
     */
    private function get_ci_preview_image_url($cos_key, $page = 1) {
        $settings = get_option('vdp_cos_settings', array());
        $region = isset($settings['region']) ? $settings['region'] : 'ap-guangzhou';
        $bucket = isset($settings['bucket']) ? $settings['bucket'] : '';
        
        if (empty($bucket) || empty($cos_key)) return '';
        
        return 'https://' . $bucket . '.cos.' . $region . '.myqcloud.com/' . $cos_key . '?ci-process=doc-preview&page=' . intval($page) . '&dstType=jpg';
    }
    
    /**
     * 获取腾讯云文档预览 URL（iframe HTML 模式，保留兼容）
    private function get_tencent_preview_url($cos_key) {
        if (empty($cos_key)) return '';
        
        $settings = get_option('vdp_cos_settings', array());
        $region = isset($settings['region']) ? $settings['region'] : 'ap-guangzhou';
        $bucket = isset($settings['bucket']) ? $settings['bucket'] : '';
        
        if (empty($bucket)) return '';
        
        // 腾讯云文档预览: 通过 COS 的数据万象（CI）服务
        // 需要在 COS 控制台 → 数据处理 → 文档预览 中开启
        return 'https://' . $bucket . '.cos.' . $region . '.myqcloud.com/' . ltrim($cos_key, '/') . '?ci-process=doc-preview&page=1&dstType=html';
    }
    
    /**
     * 在文章内容中自动追加文档预览
     */
    public function append_preview_to_content($content) {
        if (!is_singular('post')) return $content;
        
        global $post;
        $file_info = vdp_get_doc_file_info($post->ID);
        if (empty($file_info['file_name'])) return $content;
        
        // 如果内容中已经包含预览短代码，不重复追加
        if (has_shortcode($content, 'vdp_preview')) return $content;
        
        $preview = $this->preview_shortcode(array());
        
        // 追加支付/下载/会员模块
        $meta = vdp_get_zibpay_meta($post->ID);
        $price = isset($meta['pay_price']) ? floatval($meta['pay_price']) : 0;
        $is_free = empty($meta['pay_type']) || $meta['pay_type'] === 'no';
        $user_id = get_current_user_id();
        $pay_configured = \VDP\Pay::is_configured();
        $member_enabled = \VDP\Member::is_enabled();
        $member_active = ($member_enabled && $user_id) ? \VDP\Member::has_active_membership($user_id) : false;
        $can_download = $user_id ? \VDP\Member::can_download($post->ID, $user_id) : false;
        
        // 会员已启用 + 有活跃会员 → 直接显示下载
        if ($member_active && !$is_free) {
            $preview .= $this->render_member_active_card($member_active);
            $preview .= $this->render_download_section($meta, true);
        }
        // 免费文档 → 显示下载
        elseif ($is_free) {
            $preview .= $this->render_download_section($meta, false);
        }
        // 需要付费 + 尚未购买
        elseif ($pay_configured && !$can_download) {
            // 会员已启用 + 用户未登录 → 显示登录提示 + 付费卡片
            if ($member_enabled && !$user_id) {
                $preview .= '<div class="zib-widget pay-box" style="margin-bottom:12px;"><div class="flex jc"><div class="muted-2-color em09">请<a class="c-blue signin-loader">登录</a>后查看</div></div></div>';
            }
            // 会员已启用 + 用户已登录但无会员 → 显示会员卡片
            elseif ($member_enabled && $user_id && !$member_active) {
                $preview .= $this->render_membership_card();
            }
            // 付费卡片
            $preview .= $this->render_pay_card($meta, $file_info, $post->ID, $price);
        }
        // 已购买 → 显示下载
        elseif ($can_download) {
            $preview .= $this->render_download_section($meta, true);
        }
        
        return $content . $preview;
    }
    
    /**
     * 渲染会员开通卡片
     */
    private function render_membership_card() {
        $products = \VDP\Member::get_products();
        $html = '<div class="zib-widget pay-box" id="vdp-membership-box">';
        $html .= '<div class="pay-flexbox" style="flex-direction:column;"><dt class="pay-title mb10"><span class="pay-tag badg badg-sm mr6 jb-blue">会员</span>开通会员，全站资料免费下载</dt>';
        $html .= '<div class="vdp-mp-list">';
        foreach ($products as $key => $product) {
            $price_fmt = number_format(floatval($product['price']), 2);
            $html .= '<div class="vdp-mp-item">';
            $html .= '<div class="vdp-mp-info"><div class="vdp-mp-name">' . esc_html($product['name']) . '</div>';
            $html .= '<div class="vdp-mp-desc">' . esc_html($product['desc']) . '</div></div>';
            $html .= '<div class="vdp-mp-action"><span class="c-red" style="font-size:20px;font-weight:700;">￥' . $price_fmt . '</span>';
            $html .= '<div class="mt6 text-right"><a class="but jb-blue vdp-mp-btn" data-level="' . esc_attr($key) . '" data-pay-type="wechat">微信</a> ';
            $html .= '<a class="but jb-blue vdp-mp-btn" data-level="' . esc_attr($key) . '" data-pay-type="alipay">支付宝</a></div></div>';
            $html .= '</div>';
        }
        $html .= '</div></div></div>';
        return $html;
    }
    
    /**
     * 渲染会员有效卡片
     */
    private function render_member_active_card($member) {
        $level_names = array('monthly' => '月度会员', 'yearly' => '年度会员', 'lifetime' => '终身会员');
        $name = isset($level_names[$member['level']]) ? $level_names[$member['level']] : $member['level'];
        $remaining = $member['remaining_days'] >= 99999 ? '永久有效' : '剩余 ' . $member['remaining_days'] . ' 天有效期';
        $html = '<div class="zib-widget pay-box" style="border-color:#27ae60;">';
        $html .= '<div class="pay-flexbox"><dt class="pay-title" style="color:#27ae60;">';
        $html .= '✅ 您已是 <strong>' . esc_html($name) . '</strong>，' . $remaining;
        $html .= '</dt></div></div>';
        return $html;
    }
    
    /**
     * 在子比付费框原本的位置渲染支付/下载模块
     */
    
    /**
     * 渲染支付卡片（子比风格）
     * 渲染支付卡片（子比风格）
     */
    private function render_pay_card($meta, $file_info, $post_id, $price) {
        $file_name = esc_html($file_info['file_name'] ?? '');
        $file_ext  = strtoupper(esc_html($file_info['file_ext'] ?? ''));
        $file_size = vdp_format_file_size($file_info['file_size'] ?? 0);
        $user_id   = get_current_user_id();
        $cos_key   = $file_info['cos_key'] ?? '';
        
        // 预览缩略图（第1页CI预览）
        $thumb_url = '';
        if ($cos_key) {
            $settings = get_option('vdp_cos_settings', array());
            $bucket = $settings['bucket'] ?? '';
            $region = $settings['region'] ?? 'ap-guangzhou';
            if ($bucket) {
                $thumb_url = 'https://' . $bucket . '.cos.' . $region . '.myqcloud.com/' . $cos_key . '?ci-process=doc-preview&page=1&dstType=jpg';
            }
        }
        
        $html = '<div class="zib-widget pay-box order-type-2" id="vdp-posts-pay">';
        $html .= '<div class="flex pay-flexbox">';
        
        // 左侧缩略图
        $html .= '<div class="flex0 relative mr20 hide-sm pay-thumb"><div class="graphic">';
        if ($thumb_url) {
            $html .= '<img class="fit-cover" src="' . esc_url($thumb_url) . '" alt="' . $file_name . '" style="width:180px;height:135px;object-fit:cover;">';
        } else {
            $html .= '<div class="vdp-file-icon vdp-icon-' . esc_attr($file_info['file_ext']) . '" style="width:180px;height:135px;">';
            $html .= '<span class="vdp-icon-text">' . $file_ext . '</span></div>';
        }
        $html .= '<div class="abs-center text-center left-bottom">';
        $html .= '<badge class="img-badge hot jb-blue px12">' . $file_size . '</badge>';
        $html .= '</div></div></div>';
        
        // 右侧信息
        $html .= '<div class="flex-auto-h flex xx jsb">';
        $html .= '<dt class="text-ellipsis pay-title">';
        $html .= '<span class="pay-tag badg badg-sm mr6">付费资源</span>';
        $html .= $file_name . '</dt>';
        $html .= '<div class="mt6 em09 muted-2-color">虚拟资料文件：' . $file_name . '</div>';
        
        // 价格
        $html .= '<div class=""><span class="pay-mark"><svg class="icon" aria-hidden="true"><use xlink:href="#icon-coin"></use></svg></span>';
        $html .= '<span class="c-red"><span class="em09">￥</span><span class="em12">' . number_format($price, 2) . '</span></span></div>';
        
        // 购买按钮（各按钮独立带支付方式，简单可靠）
        $html .= '<div class="text-right mt10">';
        
        if ($user_id) {
            $html .= '<a class="but jb-red vdp-pay-btn" data-post-id="' . $post_id . '" data-pay-type="wechat"><i class="fa fa-wechat mr6"></i>微信支付</a> ';
            $html .= '<a class="but hollow vdp-pay-btn" data-post-id="' . $post_id . '" data-pay-type="alipay"><i class="fa fa-alipay mr6 c-blue"></i>支付宝</a>';
        } else {
            $html .= '<div class="muted-2-color em09">请<a class="c-blue signin-loader">登录</a>后购买</div>';
        }
        
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';
        
        // 已售数量（占位）
        $html .= '<badge class="img-badge hot jb-blue px12" style="position:absolute;right:10px;top:10px;"></badge>';
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * 渲染下载区域（已购买或免费）
     */
    private function render_download_section($meta, $paid = false) {
        // 获取下载链接
        $download_url = '';
        if (!empty($meta['pay_download']) && is_array($meta['pay_download'])) {
            $download_url = $meta['pay_download'][0]['link'] ?? '';
        }
        
        $file_name = esc_html($meta['vdp_file_name'] ?? '');
        
        $html = '<div class="vdp-pay-section"><div class="vdp-pay-download-box">';
        
        if ($paid) {
            $html .= '<div class="vdp-paid-icon">✅</div>';
            $html .= '<div class="vdp-paid-text">已购买，可下载此资料</div>';
        } else {
            $html .= '<div class="vdp-paid-icon">📄</div>';
            $html .= '<div class="vdp-paid-text">免费资料</div>';
        }
        
        if ($download_url) {
            $html .= '<div class="vdp-pay-download-btn-wrap">';
            $html .= '<a href="' . esc_url($download_url) . '" class="vdp-download-btn" download="' . $file_name . '">';
            $html .= '<svg viewBox="0 0 24 24" width="20" height="20" style="fill:currentColor;margin-right:8px;"><path d="M5 20h14v-2H5v2zm7-18L5.33 9h3.17v6h5v-6h3.17L12 2z"/></svg>';
            $html .= '下载文件';
            $html .= '</a>';
        } else {
            $html .= '<div class="vdp-pay-download-btn-wrap">';
            $html .= '<a href="' . get_permalink() . '" class="vdp-download-btn vdp-download-btn-disabled">下载链接未配置</a>';
            $html .= '</div>';
        }
        
        $html .= '</div></div>';
        return $html;
    }
}
