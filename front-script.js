jQuery(document).ready(function($) {
    var editModal = $('#edit-modal');
    var addModal = $('#add-user-modal');
    
    // --- 「情報を編集」ボタンの処理 ---
    $('#open-edit-modal-button').on('click', function(e) {
        e.preventDefault();
        var selectedMemberRadio = $('#red-emblem-main-form').find('input[name="red_emblem_name"]:checked');
        if (selectedMemberRadio.length === 0) {
            alert('編集したいメンバーの名前を選択してください。');
            return;
        }
        var memberId = selectedMemberRadio.data('member-id');
        var memberName = selectedMemberRadio.val();
        
        $.ajax({
            url: redEmblemAjax.ajax_url,
            type: 'POST',
            data: { action: 'get_member_details', nonce: redEmblemAjax.nonce, member_id: memberId },
            success: function(response) {
                if (response.success) {
                    var member = response.data;
                    $('#edit_member_id').val(memberId);
                    $('#edit_member_name').attr('placeholder', '現在の名前：' + memberName).val('');
                    $('#user_no_display').text(member.user_no);
                    if (member.icon_url) {
                        $('#preview_edit_icon').html('<img src="' + member.icon_url + '" style="max-width: 60px; max-height: 60px; display: block;">');
                    } else {
                        $('#preview_edit_icon').empty();
                    }
                    $('#edit_member_icon_url').val('');
                    $('#edit_icon_file_input').val('');
                    $('.upload-progress').hide();
                    $('#edit_member_password').val('');
                    editModal.css('display', 'flex');
                } else {
                    alert('情報の取得に失敗しました。');
                }
            },
            error: function() {
                alert('通信エラーが発生しました。');
            }
        });
    });

    // --- 「+」ボタンの処理 ---
    $('#open-add-user-modal-button').on('click', function(e) {
        e.preventDefault();
        // フォームをリセット
        $('#add-user-modal form').trigger('reset');
        $('#preview_add_icon').empty();
        addModal.css('display', 'flex');
    });

    // --- モーダルを閉じる処理 ---
    $('.re-modal-close').on('click', function() {
        $(this).closest('.re-modal-overlay').hide();
    });
    
    $('.re-modal-overlay').on('click', function(e) {
        if ($(e.target).is('.re-modal-overlay')) {
            $(this).hide();
        }
    });
});