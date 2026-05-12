/**
 * 虚拟资料赚钱机 - 批量上传 JS
 */
(function($) {
    'use strict';

    var fileQueue = [];
    var isUploading = false;
    var currentIndex = 0;
    var successCount = 0;
    var failCount = 0;
    var duplicateCount = 0;

    // ---- 初始化分类下拉 ----
    function initCategorySelect() {
        var $select = $('#vdp-category-select');
        if (!vdp_ajax.categories) return;

        // 构建树状分类选择
        var cats = vdp_ajax.categories;
        var tree = buildCategoryTree(cats);
        renderCategoryOptions(tree, $select, 0);
    }

    function buildCategoryTree(cats) {
        var map = {};
        var tree = [];
        cats.forEach(function(c) { map[c.id] = c; });
        cats.forEach(function(c) {
            if (c.parent && map[c.parent]) {
                if (!map[c.parent].children) map[c.parent].children = [];
                map[c.parent].children.push(c);
            } else {
                tree.push(c);
            }
        });
        return tree;
    }

    function renderCategoryOptions(tree, $select, depth) {
        tree.forEach(function(c) {
            var indent = '';
            for (var i = 0; i < depth; i++) indent += '— ';
            var $option = $('<option>').val(c.id).text(indent + c.name);
            $select.append($option);
            if (c.children) {
                renderCategoryOptions(c.children, $select, depth + 1);
            }
        });
    }

    // ---- 付费设置联动 ----
    function initPayFields() {
        $('#vdp-pay-type').on('change', function() {
            var val = $(this).val();
            $('#vdp-pay-price').toggle(val !== '0');
        }).trigger('change');
    }

    // ---- 拖拽上传 ----
    function initDropzone() {
        var $dropzone = $('#vdp-dropzone');
        var $input = $('#vdp-file-input');

        // 文件 input 直接覆盖在拖拽区上，点击由 input 原生处理（打开文件选择框）
        $input.on('change', function() {
            if (this.files.length) {
                addFiles(this.files);
                this.value = '';
            }
        });

        // 拖拽事件
        $dropzone.on('dragover', function(e) {
            e.preventDefault();
            $dropzone.addClass('vdp-dragover');
        });

        $dropzone.on('dragleave', function(e) {
            e.preventDefault();
            $dropzone.removeClass('vdp-dragover');
        });

        $dropzone.on('drop', function(e) {
            e.preventDefault();
            $dropzone.removeClass('vdp-dragover');
            if (e.originalEvent.dataTransfer.files.length) {
                addFiles(e.originalEvent.dataTransfer.files);
            }
        });
    }

    // ---- 添加文件到队列 ----
    function addFiles(files) {
        var maxSize = vdp_ajax.max_upload;
        var supportedExts = ['pdf','doc','docx','ppt','pptx','xls','xlsx','txt','zip','rar','7z'];
        var added = 0;

        for (var i = 0; i < files.length; i++) {
            var file = files[i];
            var ext = file.name.split('.').pop().toLowerCase();

            if (supportedExts.indexOf(ext) === -1) {
                continue;
            }

            if (file.size > maxSize) {
                alert('文件 ' + file.name + ' 超过大小限制 (' + vdp_ajax.max_upload_mb + 'MB)');
                continue;
            }

            fileQueue.push(file);
            addFileItem(file);
            added++;
        }

        if (added > 0) {
            $('#vdp-actions').show();
            updateUploadButton();
        }
    }

    // ---- 渲染文件项 ----
    function addFileItem(file) {
        var ext = file.name.split('.').pop().toLowerCase();
        var size = formatFileSize(file.size);
        var badgeClass = 'vdp-format-' + ext;

        var $item = $('<div class="vdp-file-item" data-filename="' + escHtml(file.name) + '">');
        $item.append('<span class="vdp-format-badge ' + badgeClass + '">' + ext.toUpperCase() + '</span>');
        $item.append('<span class="vdp-file-item-name">' + escHtml(file.name) + '</span>');
        $item.append('<span class="vdp-file-item-size">' + size + '</span>');
        $item.append('<span class="vdp-file-item-status">等待上传</span>');

        $('#vdp-file-list').append($item);
    }

    // ---- 开始上传 ----
    function startUpload() {
        if (isUploading) return;
        if (fileQueue.length === 0) return;

        isUploading = true;
        currentIndex = 0;
        successCount = 0;
        failCount = 0;
        duplicateCount = 0;

        $('#vdp-start-upload').prop('disabled', true).text('上传中...');
        $('#vdp-progress').show();
        $('#vdp-results').empty();
        updateProgress();

        processNext();
    }

    // ---- 处理下一个文件 ----
    function processNext() {
        if (currentIndex >= fileQueue.length) {
            // 全部处理完成
            isUploading = false;
            $('#vdp-start-upload').prop('disabled', false).text('开始上传并发布');
            var total = successCount + failCount;
            var msg = '全部完成！成功 ' + successCount + ' 个';
            if (duplicateCount > 0) msg += '，重复 ' + duplicateCount + ' 个';
            if (failCount > 0) msg += '，失败 ' + failCount + ' 个';
            $('#vdp-upload-status').text(msg);
            updateProgress(100);
            return;
        }

        var file = fileQueue[currentIndex];
        var $item = $('#vdp-file-list .vdp-file-item').eq(currentIndex);
        $item.removeClass('vdp-success vdp-error vdp-duplicate').addClass('vdp-uploading');
        $item.find('.vdp-file-item-status').text('上传中...');

        var categoryId = $('#vdp-category-select').val() || '';
        var payType = $('#vdp-pay-type').val() || '0';
        var payPrice = $('#vdp-pay-price').val() || '0';
        var vipLimit = $('#vdp-vip-limit').val() || '0';

        var formData = new FormData();
        formData.append('action', 'vdp_upload_file');
        formData.append('_ajax_nonce', vdp_ajax.nonce);
        formData.append('file', file);
        formData.append('category_id', categoryId);
        formData.append('pay_type', payType);
        formData.append('pay_price', payPrice);
        formData.append('vip_limit', vipLimit);
        formData.append('preview_pages', $('#vdp-preview-pages').val() || '3');

        $.ajax({
            url: vdp_ajax.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(response) {
                $item.removeClass('vdp-uploading');

                if (response.success) {
                    successCount++;
                    $item.addClass('vdp-success');
                    $item.find('.vdp-file-item-status').html(
                        '<a href="' + response.data.edit_link + '" target="_blank" class="vdp-result-link">发布成功</a>'
                    );
                    addResult('success', file.name, response.data.permalink);
                } else {
                    if (response.data && response.data.duplicate) {
                        duplicateCount++;
                        $item.addClass('vdp-duplicate');
                        $item.find('.vdp-file-item-status').text('已存在');
                        addResult('duplicate', file.name, '已存在');
                    } else {
                        failCount++;
                        $item.addClass('vdp-error');
                        var errMsg = response.data || response.data;
                        if (typeof errMsg === 'object') errMsg = errMsg.message || '未知错误';
                        $item.find('.vdp-file-item-status').text('失败');
                        addResult('error', file.name, errMsg);
                    }
                }

                currentIndex++;
                updateProgress();
                processNext();
            },
            error: function(xhr, status, error) {
                $item.removeClass('vdp-uploading').addClass('vdp-error');
                $item.find('.vdp-file-item-status').text('请求失败');
                failCount++;
                addResult('error', file.name, '请求失败: ' + error);

                currentIndex++;
                updateProgress();
                processNext();
            }
        });
    }

    // ---- 更新进度 ----
    function updateProgress(forcePercent) {
        var total = fileQueue.length;
        var done = currentIndex;
        var percent = forcePercent !== undefined ? forcePercent : (total > 0 ? Math.round(done / total * 100) : 0);

        $('#vdp-progress-fill').css('width', percent + '%');
        $('#vdp-progress-text').text('处理进度: ' + done + ' / ' + total + ' (成功 ' + successCount + '，失败 ' + failCount + '，重复 ' + duplicateCount + ')');
    }

    // ---- 添加结果日志 ----
    function addResult(type, filename, msg) {
        var icon = type === 'success' ? '✅' : (type === 'duplicate' ? '⚠️' : '❌');
        var cls = type === 'success' ? 'vdp-result-success' : 'vdp-result-error';
        var link = type === 'success' ? ' <a href="' + msg + '" target="_blank" class="vdp-result-link">查看</a>' : '';

        $('#vdp-results').append(
            '<div class="vdp-result-item ' + cls + '">' +
            icon + ' ' + escHtml(filename) + ' — ' + escHtml(type === 'success' ? '发布成功' : msg) + link +
            '</div>'
        );
    }

    // ---- 更新上传按钮状态 ----
    function updateUploadButton() {
        var count = fileQueue.length;
        $('#vdp-start-upload').text('开始上传并发布 (' + count + ' 个文件)');
    }

    // ---- 工具函数 ----
    function formatFileSize(bytes) {
        if (bytes <= 0) return '0 B';
        var units = ['B', 'KB', 'MB', 'GB'];
        var i = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
        return (bytes / Math.pow(1024, i)).toFixed(1) + ' ' + units[i];
    }

    function escHtml(str) {
        return String(str).replace(/[&<>"]/g, function(m) {
            if (m === '&') return '&amp;';
            if (m === '<') return '&lt;';
            if (m === '>') return '&gt;';
            if (m === '"') return '&quot;';
            return m;
        });
    }

    // ---- 初始化 ----
    $(document).ready(function() {
        initCategorySelect();
        initPayFields();
        initDropzone();

        $('#vdp-start-upload').on('click', function() {
            if (!confirm('确认开始上传 ' + fileQueue.length + ' 个文件？')) return;
            startUpload();
        });
    });

})(jQuery);
