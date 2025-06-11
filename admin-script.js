jQuery(document).ready(function($) {
    var mediaUploader;

    // --- 従来のメディアライブラリ処理（Icon Settings用） ---
    $(document).on('click', '.upload-button', function(e) {
        e.preventDefault();
        var button = $(this);
        var targetInputId = button.data('target-id');
        var previewDivId = button.data('preview-id');

        if (targetInputId === 'member_icon_url') {
            return;
        }

        if (mediaUploader) {
            mediaUploader.open();
            return;
        }

        mediaUploader = wp.media.frames.file_frame = wp.media({
            title: 'アイコンを選択',
            button: { text: 'このアイコンを使用' },
            multiple: false
        });

        mediaUploader.on('select', function() {
            var attachment = mediaUploader.state().get('selection').first().toJSON();
            $('#' + targetInputId).val(attachment.id);
            $('#' + previewDivId).html('<img src="' + attachment.url + '" style="max-width: 60px; max-height: 60px; display: block;">');
        });

        mediaUploader.open();
    });

    // --- 新しいカスタムアップロード処理（メンバーアイコン用） ---
$(document).on('click', '#select-member-icon-button, #select-edit-icon-button, #select-add-icon-button', function() {
        $(this).next('input[type="file"]').click();
    });

    $(document).on('change', '#member_icon_file_input, #edit_icon_file_input, #add_icon_file_input', function() {
        if (this.files.length === 0) return;

        var fileInput = $(this);
        var parentContainer = fileInput.closest('td, .form-row');
        var previewDiv = parentContainer.find('.icon-preview');
        var urlInput = parentContainer.find('input[type="hidden"]');
        var progressIndicator = parentContainer.find('.upload-progress');
        var nonce = redEmblemAjax.nonce;

        var formData = new FormData();
        formData.append('icon_file', this.files[0]);
        formData.append('action', 're_upload_icon');
        formData.append('nonce', nonce);

        progressIndicator.show().text('Uploading...').css('color', '');

        $.ajax({
            // ★★★ ここが最重要修正ポイントです ★★★
            url: redEmblemAjax.ajax_url, 
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                progressIndicator.hide();
                if (response.success) {
                    var imageUrl = response.data.url;
                    previewDiv.html('<img src="' + imageUrl + '" style="max-width: 60px; max-height: 60px; display: block;">');
                    urlInput.val(imageUrl);
                } else {
                    progressIndicator.show().text('Error: ' + response.data.message).css('color', 'red');
                }
            },
            error: function() {
                progressIndicator.show().text('Upload failed.').css('color', 'red');
            }
        });
    });
});