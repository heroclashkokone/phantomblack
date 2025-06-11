jQuery(document).ready(function($) {
    var modal = $('#edit-modal');
    
    // 「情報を編集」ボタンがクリックされた時の処理
    $('#open-edit-modal-button').on('click', function(e) {
        e.preventDefault();

        var selectedMemberRadio = $('#red-emblem-main-form').find('input[name="red_emblem_name"]:checked');

        // 名前が選択されているかチェック
        if (selectedMemberRadio.length === 0) {
            alert('編集したいメンバーの名前を選択してください。');
            return;
        }
        
        var memberId = selectedMemberRadio.data('member-id');
        var memberName = selectedMemberRadio.val();
        
        // モーダルに選択されたメンバーの情報をセット
        $('#edit_member_id').val(memberId);
        $('#edit_member_name').attr('placeholder', '現在の名前：' + memberName).val('');
        $('#preview_edit_icon').empty(); // プレビューをリセット
        $('#edit_member_icon_id').val(''); // アイコンIDをリセット
        $('#edit_member_password').val(''); // パスワード欄をリセット
        
        // モーダルを表示
        modal.css('display', 'flex');
    });

    // モーダルの閉じるボタン
    $('.re-modal-close').on('click', function() {
        modal.hide();
    });

    // モーダルの外側をクリックしたら閉じる
    $(modal).on('click', function(e) {
        if ($(e.target).is(modal)) {
            modal.hide();
        }
    });
});