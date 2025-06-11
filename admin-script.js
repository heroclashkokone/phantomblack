jQuery(document).ready(function($) {
    var mediaUploader;

    // .upload-button クラスを持つすべてのボタンにイベントを割り当てる
    $(document).on('click', '.upload-button', function(e) {
        e.preventDefault();
        var button = $(this);
        // data属性から、更新対象のIDを取得
        var targetInputId = button.data('target-id');
        var previewDivId = button.data('preview-id');

        if (mediaUploader) {
            mediaUploader.open();
            return;
        }

        mediaUploader = wp.media.frames.file_frame = wp.media({
            title: 'アイコンを選択',
            button: {
                text: 'このアイコンを使用'
            },
            multiple: false
        });

        mediaUploader.on('select', function() {
            var attachment = mediaUploader.state().get('selection').first().toJSON();
            // 対象のinputとpreviewを更新
            $('#' + targetInputId).val(attachment.id);
            $('#' + previewDivId).html('<img src="' + attachment.url + '" style="max-width: 60px; max-height: 60px; display: block;">');
        });

        mediaUploader.open();
    });
});