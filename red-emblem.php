<?php
/**
 * Plugin Name: Red Emblem Tracker
 * Description: Webフォームから赤色聖章の数を登録し、週ごとに集計・管理します。
 * Version: 8.0.0 (Stable)
 * Author: kokone + Gemini
 */

if (!defined('ABSPATH')) exit;

// === プラグイン有効化時の処理 ===
register_activation_hook(__FILE__, function() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    
    // emblemsテーブルのSQLは変更なし
    $emblems_table = $wpdb->prefix . 'red_emblems';
    $sql1 = "CREATE TABLE IF NOT EXISTS $emblems_table ( id BIGINT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, red_emblems INT NOT NULL, submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP ) $charset_collate;";
    dbDelta($sql1);

    // ★★ ここからが変更箇所です ★★
    $members_table = $wpdb->prefix . 'red_emblem_members';
    
    // カラムを icon_url (数字) から icon_url (文字列) に変更します。
    $sql2 = "CREATE TABLE IF NOT EXISTS $members_table (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        user_no VARCHAR(255) DEFAULT '' NOT NULL,
        icon_url VARCHAR(255) DEFAULT '' NOT NULL, -- ★★ 変更: icon_url を icon_url に ★★
        password VARCHAR(255) NOT NULL,
        display_order INT DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY (user_no)
    ) $charset_collate;";
    dbDelta($sql2);

    // ★★ 追加: 画像アップロード用のディレクトリを作成します ★★
    $upload_dir = plugin_dir_path(__FILE__) . 'images';
    if (!file_exists($upload_dir)) {
        wp_mkdir_p($upload_dir);
    }
});

// === 管理画面メニューのセットアップ ===
add_action('admin_menu', function() {
    add_menu_page('Red Emblem', 'Red Emblem', 'manage_options', 'red-emblem-main', 'render_red_emblem_entries_page', 'dashicons-database', 26);
    add_submenu_page('red-emblem-main', 'Submissions', 'Submissions', 'manage_options', 'red-emblem-main', 'render_red_emblem_entries_page');
    add_submenu_page('red-emblem-main', 'Members', 'Members', 'manage_options', 'red-emblem-members', 'render_red_emblem_members_page');
});

// === スクリプトの読み込み（管理画面・フロントエンド） ===
// 管理画面用
add_action('admin_enqueue_scripts', function($hook) {
    if ($hook === 'red-emblem_page_red-emblem-members') {
        // Icon Settingsのためにメディアライブラリを読み込む
        wp_enqueue_media(); 
        // 汎用スクリプトを読み込む（★★バージョンを1.4に更新★★）
        wp_enqueue_script('red-emblem-admin-script', plugin_dir_url(__FILE__) . 'admin-script.js', ['jquery'], '1.4', true);

        // Nonceを管理画面のJavaScriptにも渡す
        wp_localize_script('red-emblem-admin-script', 'redEmblemAjax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('red_emblem_edit_action')
        ]);
    }
});

// フロントエンド用
add_action('wp_enqueue_scripts', function() {
    global $post;
    if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'red_emblem_form')) {
        wp_enqueue_script('jquery');
        // 汎用スクリプトとモーダル用スクリプトを読み込む（★★バージョンを1.4に更新★★）
        wp_enqueue_script('red-emblem-admin-script', plugin_dir_url(__FILE__) . 'admin-script.js', ['jquery'], '1.4', true);
        wp_enqueue_script('red-emblem-front-script', plugin_dir_url(__FILE__) . 'front-script.js', ['jquery'], '2.1', true);
        
        // NonceをフロントエンドのJavaScriptに渡す
        wp_localize_script('red-emblem-admin-script', 'redEmblemAjax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('red_emblem_edit_action')
        ]);
    }
});

// === メンバー管理ページの表示と処理 ===

function render_red_emblem_members_page() {
    global $wpdb;
    $members_table = $wpdb->prefix . 'red_emblem_members';
    $editing_member = null;

    // ★★ 追加: 編集ボタンが押された時の処理 ★★
    // 編集対象のメンバー情報を取得して、フォームに表示する準備
    if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['member_id'])) {
        if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'edit_member_' . $_GET['member_id'])) {
            $editing_member = $wpdb->get_row($wpdb->prepare("SELECT * FROM $members_table WHERE id = %d", intval($_GET['member_id'])));
        }
    }

    // --- 各種設定の保存処理 ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_admin_referer('red_emblem_settings_action')) {
        
        // ★★ 追加: メンバー情報更新処理 ★★
        if (isset($_POST['member_update'])) {
            $member_id = intval($_POST['editing_member_id']);
            $name = sanitize_text_field($_POST['member_name']);
            $user_no = sanitize_text_field($_POST['member_user_no']);
            $icon_url = esc_url_raw($_POST['member_icon_url']);
            $password = $_POST['member_password']; // パスワードは空の場合更新しない

            if ($member_id > 0 && !empty($name) && !empty($user_no)) {
                $update_data = [
                    'name' => $name,
                    'user_no' => $user_no,
                ];
                // アイコンが新しく選択された場合のみ更新
if (!empty($icon_url)) {
    $update_data['icon_url'] = $icon_url;
}
                // パスワードが入力された場合のみ更新
                if (!empty($password)) {
                    $update_data['password'] = wp_hash_password($password);
                }
                $wpdb->update($members_table, $update_data, ['id' => $member_id]);
                echo '<div class="notice notice-success is-dismissible"><p>Member updated.</p></div>';
            } else {
                 echo '<div class="notice notice-error is-dismissible"><p>Name and User No are required.</p></div>';
            }

        // メンバー追加
        } elseif (isset($_POST['member_submit'])) {
            $name = sanitize_text_field($_POST['member_name']);
            $user_no = sanitize_text_field($_POST['member_user_no']);
            $icon_url = esc_url_raw($_POST['member_icon_url']);
            $password = $_POST['member_password'];

            if (!empty($name) && !empty($user_no) && !empty($icon_url) && !empty($password)) {
                $wpdb->insert($members_table, [
                    'name' => $name,
                    'user_no' => $user_no,
                    'icon_url' => $icon_url,
                    'password' => wp_hash_password($password)
                ]);
                echo '<div class="notice notice-success is-dismissible"><p>Member added.</p></div>';
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>Name, User No, Icon, and Password are required.</p></div>';
            }
        }
        
        // アイコン設定の保存は変更なし
if (isset($_POST['count_icon_submit'])) {
    update_option('red_emblem_icon_0', intval($_POST['count_icon_id_0']));
    update_option('red_emblem_icon_1', intval($_POST['count_icon_id_1']));
    update_option('red_emblem_icon_2', intval($_POST['count_icon_id_2']));
    update_option('red_emblem_icon_edit', intval($_POST['count_icon_id_edit']));
    echo '<div class="notice notice-success is-dismissible"><p>Icons saved.</p></div>';
}
    }

    // --- メンバー削除処理 ---
    if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['member_id']) && check_admin_referer('delete_member_' . $_GET['member_id'])) {
        $wpdb->delete($members_table, ['id' => intval($_GET['member_id'])]);
        echo '<div class="notice notice-success is-dismissible"><p>Member deleted.</p></div>';
    }

    // --- DBから各種設定とメンバー一覧を取得 ---
    $members = $wpdb->get_results("SELECT * FROM $members_table ORDER BY display_order ASC, name ASC");
    $icon_url_0 = get_option('red_emblem_icon_0');
    $icon_url_1 = get_option('red_emblem_icon_1');
    $icon_url_2 = get_option('red_emblem_icon_2');
    $icon_url_edit = get_option('red_emblem_icon_edit');
    ?>
    <div class="wrap">
        <h1>Member & Icon Management</h1>
        <form method="post">
            <?php wp_nonce_field('red_emblem_settings_action'); ?>

            <h2>Icon Settings</h2>
            <p>フロントエンドのフォームで使用するアイコンを設定します。</p>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Count "0" Icon</th>
                    <td>
                        <div class="icon-preview" id="preview_count_icon_0"><?php if($icon_url_0) echo wp_get_attachment_image($icon_url_0, [60,60]); ?></div>
                        <input type="hidden" id="count_icon_id_0" name="count_icon_id_0" value="<?php echo esc_attr($icon_url_0); ?>">
                        <p><button type="button" class="button upload-button" data-target-id="count_icon_id_0" data-preview-id="preview_count_icon_0">Select Image</button></p>
                    </td>
                </tr>
                 <tr valign="top">
                    <th scope="row">Count "1" Icon</th>
                    <td>
                        <div class="icon-preview" id="preview_count_icon_1"><?php if($icon_url_1) echo wp_get_attachment_image($icon_url_1, [60,60]); ?></div>
                        <input type="hidden" id="count_icon_id_1" name="count_icon_id_1" value="<?php echo esc_attr($icon_url_1); ?>">
                        <p><button type="button" class="button upload-button" data-target-id="count_icon_id_1" data-preview-id="preview_count_icon_1">Select Image</button></p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Count "2" Icon</th>
                    <td>
                        <div class="icon-preview" id="preview_count_icon_2"><?php if($icon_url_2) echo wp_get_attachment_image($icon_url_2, [60,60]); ?></div>
                        <input type="hidden" id="count_icon_id_2" name="count_icon_id_2" value="<?php echo esc_attr($icon_url_2); ?>">
                        <p><button type="button" class="button upload-button" data-target-id="count_icon_id_2" data-preview-id="preview_count_icon_2">Select Image</button></p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">"Edit" Icon</th>
                    <td>
                        <div class="icon-preview" id="preview_count_icon_edit"><?php if($icon_url_edit) echo wp_get_attachment_image($icon_url_edit, [60,60]); ?></div>
                        <input type="hidden" id="count_icon_id_edit" name="count_icon_id_edit" value="<?php echo esc_attr($icon_url_edit); ?>">
                        <p><button type="button" class="button upload-button" data-target-id="count_icon_id_edit" data-preview-id="preview_count_icon_edit">Select Image</button></p>
                    </td>
                </tr>
            </table>
            <?php submit_button('Save Icons', 'primary', 'count_icon_submit'); ?>
            <style>.icon-preview { width: 60px; height: 60px; border: 1px dashed #ccc; display: flex; align-items: center; justify-content: center; text-align: center; background-color: #f7f7f7; margin-bottom: 5px; }</style>
            <hr>

            <h2><?php echo $editing_member ? 'Edit Member' : 'Member Management'; ?></h2>
            <p><?php if($editing_member) echo 'Editing: ' . esc_html($editing_member->name); ?></p>
            
            <input type="hidden" name="editing_member_id" value="<?php echo esc_attr($editing_member ? $editing_member->id : ''); ?>">
            
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="member_name">Name</label></th>
                    <td><input type="text" id="member_name" name="member_name" value="<?php echo esc_attr($editing_member ? $editing_member->name : ''); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="member_user_no">User No</label></th>
                    <td><input type="text" id="member_user_no" name="member_user_no" value="<?php echo esc_attr($editing_member ? $editing_member->user_no : ''); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="member_password">Password</label></th>
                    <td><input type="password" id="member_password" name="member_password" placeholder="<?php echo $editing_member ? 'Leave blank to keep current password' : ''; ?>"><p class="description">This password will be used by the member to edit their own info.</p></td>
                </tr>
<tr>
    <th scope="row">Icon</th>
    <td>
        <div class="icon-preview" id="preview_member_icon">
            <?php if ($editing_member && $editing_member->icon_url) { echo '<img src="' . esc_url($editing_member->icon_url) . '" style="max-width: 60px; max-height: 60px;">'; } ?>
        </div>
        <input type="hidden" id="member_icon_url" name="member_icon_url" value="<?php echo esc_attr($editing_member ? $editing_member->icon_url : ''); ?>">
        
        <p>
            <button type="button" class="button" id="select-member-icon-button">Select Image File</button>
            <input type="file" id="member_icon_file_input" style="display: none;" accept="image/png, image/jpeg, image/gif">
            <span class="upload-progress" style="display: none; margin-left: 10px;"></span>
        </p>
        </td>
</tr>
            </table>

            <?php if ($editing_member): ?>
                <?php submit_button('Update Member', 'primary', 'member_update'); ?>
                <a href="<?php echo admin_url('admin.php?page=red-emblem-members'); ?>" class="button">Cancel Editing</a>
            <?php else: ?>
                <?php submit_button('Add New Member', 'secondary', 'member_submit'); ?>
            <?php endif; ?>
        </form>

        <hr>

        <h2>Member List</h2>
        <table class="wp-list-table widefat fixed striped">
            <thead><tr><th style="width: 60px;">Icon</th><th>Name</th><th>User No</th><th>Actions</th></tr></thead>
            <tbody>
                <?php if ($members) : foreach ($members as $member) : ?>
                    <tr>
<td>
    <?php if ($member->icon_url): ?>
        <img src="<?php echo esc_url($member->icon_url); ?>" alt="" style="width: 60px; height: 60px; object-fit: cover;">
    <?php endif; ?>
</td>
                        <td><?php echo esc_html($member->name); ?></td>
                        <td><?php echo esc_html($member->user_no); ?></td>
                        <td>
                            <?php $edit_url = wp_nonce_url(admin_url('admin.php?page=red-emblem-members&action=edit&member_id=' . $member->id), 'edit_member_' . $member->id); ?>
                            <a href="<?php echo esc_url($edit_url); ?>" class="button button-small">Edit</a>

                            <?php $delete_url = wp_nonce_url(admin_url('admin.php?page=red-emblem-members&action=delete&member_id=' . $member->id), 'delete_member_' . $member->id); ?>
                            <a href="<?php echo esc_url($delete_url); ?>" onclick="return confirm('Are you sure?');" class="button button-small">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; else : ?>
                    <tr><td colspan="4">No members registered yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

// === 集計データページの表示 ===
function render_red_emblem_entries_page() {
    // この関数の中身は変更ありません
    global $wpdb;
    $table = $wpdb->prefix . 'red_emblems';
    if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id']) && isset($_GET['_wpnonce'])) {
        if (wp_verify_nonce($_GET['_wpnonce'], 'delete_emblem_' . $_GET['id'])) {
            $wpdb->delete($table, ['id' => intval($_GET['id'])]);
            echo '<div class="updated"><p>Deleted.</p></div>';
        }
    }
    $weeks = $wpdb->get_col("SELECT DISTINCT DATE(DATE_SUB(submitted_at, INTERVAL (WEEKDAY(submitted_at)) DAY)) as week_start FROM $table ORDER BY week_start DESC");
    $current_week_start = date('Y-m-d', strtotime('monday this week'));
    $selected_week_start = isset($_GET['week']) ? sanitize_text_field($_GET['week']) : $current_week_start;
    $start_date = $selected_week_start;
    $end_date = date('Y-m-d 23:59:59', strtotime($start_date . ' +6 days'));
    $results = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE submitted_at BETWEEN %s AND %s ORDER BY submitted_at DESC", $start_date, $end_date));
    ?>
    <div class="wrap">
        <h1>Submission Data</h1>
        <form method="get">
            <input type="hidden" name="page" value="red-emblem-main">
            <select name="week" onchange="this.form.submit()">
                <option value="<?php echo esc_attr($current_week_start); ?>">-- This Week --</option>
                <?php foreach ($weeks as $week_start) : ?>
                    <?php $week_end = date('n/j', strtotime($week_start . ' +6 days')); $display_text = date('n/j', strtotime($week_start)) . ' - ' . $week_end; ?>
                    <option value="<?php echo esc_attr($week_start); ?>" <?php selected($selected_week_start, $week_start); ?>><?php echo esc_html($display_text); ?></option>
                <?php endforeach; ?>
            </select>
            <noscript><input type="submit" value="View" class="button"></noscript>
        </form>
        <h3>Data for: <?php echo esc_html(date('Y/n/j', strtotime($start_date))); ?> - <?php echo esc_html(date('Y/n/j', strtotime($end_date))); ?></h3>
        <table class="wp-list-table widefat striped">
            <thead><tr><th>ID</th><th>Name</th><th>Count</th><th>Timestamp</th><th>Actions</th></tr></thead>
            <tbody>
                <?php if ($results) : ?>
                    <?php foreach ($results as $row) : $delete_url = wp_nonce_url(admin_url('admin.php?page=red-emblem-main&action=delete&id=' . $row->id . '&week=' . $selected_week_start), 'delete_emblem_' . $row->id); ?>
                        <tr>
                            <td><?php echo esc_html($row->id); ?></td>
                            <td><?php echo esc_html($row->name); ?></td>
                            <td><?php echo esc_html($row->red_emblems); ?></td>
                            <td><?php echo esc_html($row->submitted_at); ?></td>
                            <td><a href="<?php echo esc_url($delete_url); ?>" onclick="return confirm('Are you sure?');">Delete</a></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr><td colspan="5">No data for this week.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}


// [red_emblem_form] Web入力フォーム（最終版）
add_shortcode('red_emblem_form', function() {
    global $wpdb;
    $members_table = $wpdb->prefix . 'red_emblem_members';
    $emblems_table = $wpdb->prefix . 'red_emblems';
    $members = $wpdb->get_results("SELECT * FROM $members_table ORDER BY display_order ASC, name ASC");
    $icon_url_0 = get_option('red_emblem_icon_0');
    $icon_url_1 = get_option('red_emblem_icon_1');
    $icon_url_2 = get_option('red_emblem_icon_2');
    $icon_url_0 = $icon_url_0 ? wp_get_attachment_image_url($icon_url_0, 'thumbnail') : '';
    $icon_url_1 = $icon_url_1 ? wp_get_attachment_image_url($icon_url_1, 'thumbnail') : '';
    $icon_url_2 = $icon_url_2 ? wp_get_attachment_image_url($icon_url_2, 'thumbnail') : '';
    $message = '';

    // ★★ ここから追加: 提出状況確認ロジック ★★
    // 今週の月曜日と日曜日の日付を取得
    $monday = date('Y-m-d 00:00:00', strtotime('monday this week'));
    $sunday = date('Y-m-d 23:59:59', strtotime('sunday this week'));
    // 今週提出済みのユーザー名をリストで取得
    $submitted_names = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT name FROM $emblems_table WHERE submitted_at BETWEEN %s AND %s",
        $monday, $sunday
    ));
    // ★★ ここまで追加 ★★

    // --- 編集フォームの送信処理 ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_member_submit']) && check_admin_referer('red_emblem_edit_action')) {
        $member_id = intval($_POST['edit_member_id']);
        $password = $_POST['edit_member_password'];
        $new_name = sanitize_text_field($_POST['edit_member_name']);
        $new_icon_url = esc_url_raw($_POST['edit_member_icon_url']);
        $member = $wpdb->get_row($wpdb->prepare("SELECT * FROM $members_table WHERE id = %d", $member_id));
        if ($member && wp_check_password($password, $member->password)) {
            $update_data = [];
            if (!empty($new_name) && $new_name !== $member->name) {
                $update_data['name'] = $new_name;
                $wpdb->update($emblems_table, ['name' => $new_name], ['name' => $member->name]);
            }
            if (!empty($new_icon_url)) { $update_data['icon_url'] = $new_icon_url; }
            if (!empty($update_data)) { $wpdb->update($members_table, $update_data, ['id' => $member_id]); }
            wp_redirect(add_query_arg('message', 'updated', remove_query_arg('message')));
            exit;
        } else {
            $message = '<p style="font-weight: bold; color: red;">パスワードが違います。</p>';
        }
    }

    // --- 聖章数登録の送信処理 ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['red_emblem_submit'])) {
        $name = isset($_POST['red_emblem_name']) ? sanitize_text_field($_POST['red_emblem_name']) : '';
        $count = isset($_POST['red_emblem_count']) ? intval($_POST['red_emblem_count']) : -1;
        if (empty($name) || $count < 0) {
            $message = '<p style="color: red; font-weight: bold;">名前と聖章数を選択してください。</p>';
        } else {
            $monday = date('Y-m-d 00:00:00', strtotime('monday this week'));
            $sunday = date('Y-m-d 23:59:59', strtotime('sunday this week'));
            $existing_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $emblems_table WHERE name = %s AND submitted_at BETWEEN %s AND %s", $name, $monday, $sunday));
            if ($existing_id) {
                $wpdb->update($emblems_table, ['red_emblems' => $count, 'submitted_at' => current_time('mysql')], ['id' => $existing_id]);
            } else {
                $wpdb->insert($emblems_table, ['name' => $name, 'red_emblems' => $count]);
            }
            wp_redirect(add_query_arg('message', 'submitted', remove_query_arg('message')));
            exit;
        }
    }

    // ★★ ここから新規ユーザー登録の送信処理を追加 ★★
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user_submit'])) {
        check_admin_referer('red_emblem_add_user_action');
        
        $name = sanitize_text_field($_POST['add_user_name']);
        $user_no = sanitize_text_field($_POST['add_user_no']);
        $icon_url = esc_url_raw($_POST['add_member_icon_url']);
        $password = $_POST['add_user_password'];

        // バリデーション
        if (empty($name) || empty($user_no) || empty($icon_url) || empty($password)) {
            $message = '<p style="font-weight: bold; color: red;">すべての項目を入力してください。</p>';
        } else {
            // User No の重複チェック
            $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $members_table WHERE user_no = %s", $user_no));
            if ($exists > 0) {
                $message = '<p style="font-weight: bold; color: red;">そのUser Noは既に使用されています。</p>';
            } else {
                // データベースに登録
                $wpdb->insert($members_table, [
                    'name' => $name,
                    'user_no' => $user_no,
                    'icon_url' => $icon_url,
                    'password' => wp_hash_password($password)
                ]);
                wp_redirect(add_query_arg('message', 'user_added', remove_query_arg('message')));
                exit;
            }
        }
    }

    // --- リダイレクト後のメッセージ表示処理 ---
    if (isset($_GET['message'])) {
        if ($_GET['message'] === 'updated') { $message = '<p style="font-weight: bold; color: blue;">情報を更新しました。</p>'; }
        if ($_GET['message'] === 'submitted') { $message = '<p style="font-weight: bold; color: green;">登録しました！</p>'; }
        if ($_GET['message'] === 'user_added') { $message = '<p style="font-weight: bold; color: green;">新しいユーザーを登録しました！</p>'; } // ★★ この行を追加 ★★
    }

    ob_start();
    ?>
    <style>
        .red-emblem-form-container { max-width: 600px; }
        .icon-selector-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(80px, 1fr)); gap: 10px; margin-bottom: 20px; }
        .icon-selector-grid.count-selector { grid-template-columns: repeat(auto-fill, minmax(60px, 1fr)); }
        .icon-selector-grid input[type="radio"] { display: none; }
        .icon-selector-grid label { display: flex; flex-direction: column; align-items: center; padding: 5px; border: 3px solid #ddd; border-radius: 8px; cursor: pointer; transition: all 0.2s ease-in-out; background-color: #fff; }
        .icon-selector-grid label:hover { border-color: #999; background-color: #f7f7f7; }
        .icon-selector-grid input[type="radio"]:checked + label { border-color: #0073aa; background-color: #f0f8ff; transform: scale(1.05); }
        .icon-selector-grid img { width: 60px; height: 60px; border-radius: 50%; object-fit: cover; }
        .icon-selector-grid .member-name { font-size: 12px; text-align: center; word-break: break-all; margin-top: 5px; }
        .re-modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 999; display: none; align-items: center; justify-content: center; }
        .re-modal-content { background: #fff; padding: 20px; border-radius: 5px; max-width: 400px; width: 90%; position: relative; }
        .re-modal-close { position: absolute; top: 10px; right: 15px; font-size: 24px; font-weight: bold; cursor: pointer; }
        .re-modal-content h2 { margin-top: 0; }
        .re-modal-content .form-row { margin-bottom: 15px; }
        .re-modal-content label { font-weight: bold; display: block; margin-bottom: 5px; }
        .re-modal-content input[type="text"], .re-modal-content input[type="password"] { width: 100%; padding: 8px; box-sizing: border-box; }
        .add-new-label { justify-content: center; background-color: #f7f7f7; }
        .add-new-plus { font-size: 40px; color: #777; font-weight: bold; line-height: 60px; }

        /* ★★ ここから追加: 背景色用のスタイル ★★ */
        .icon-selector-grid .member-name { 
            font-size: 12px; text-align: center; word-break: break-all; margin-top: 5px; 
            padding: 2px 4px; border-radius: 4px; /* 見た目を整える */
        }
        .submitted .member-name {
            background-color: #e0f7ff; /* 薄い青色 */
            color: #006080;
        }
        .not-submitted .member-name {
            background-color: #ffe0e0; /* 薄い赤色 */
            color: #a00000;
        }
        /* ★★ ここまで追加 ★★ */

    </style>
    <div id="re-message-area"><?php echo $message; ?></div>
    <div class="red-emblem-form-container">
        <form method="post" id="red-emblem-main-form" action="">
            <p><strong>名前を選択してください：</strong></p>
            <div class="icon-selector-grid">
                <?php if ($members) : foreach ($members as $member) : ?>

                    <?php
                        // ★★ 追加: 提出済みか判定してCSSクラスを決定 ★★
                        $status_class = in_array($member->name, $submitted_names) ? 'submitted' : 'not-submitted';
                    ?>

                    <div>
                        <input type="radio" id="member_<?php echo esc_attr($member->id); ?>" name="red_emblem_name" value="<?php echo esc_attr($member->name); ?>" data-member-id="<?php echo esc_attr($member->id); ?>">
                        <label for="member_<?php echo esc_attr($member->id); ?>" class="<?php echo $status_class; ?>">
    <img src="<?php echo esc_url($member->icon_url); ?>" alt="<?php echo esc_attr($member->name); ?>">
    <span class="member-name"><?php echo esc_html($member->name); ?></span>
</label>
                    </div>
                <?php endforeach; else: ?><p>メンバーが登録されていません。</p><?php endif; ?>

                <div id="open-add-user-modal-button" title="新規ユーザー追加">
                    <label class="add-new-label">
                        <span class="add-new-plus">+</span>
                    </label>
                </div>
            </div>
            <p><strong>聖章の数を選択してください：</strong></p>
            <div class="icon-selector-grid count-selector">
                <?php if ($icon_url_0) : ?><div><input type="radio" id="count_0" name="red_emblem_count" value="0"><label for="count_0"><img src="<?php echo esc_url($icon_url_0); ?>" alt="0"></label></div><?php endif; ?>
                <?php if ($icon_url_1) : ?><div><input type="radio" id="count_1" name="red_emblem_count" value="1"><label for="count_1"><img src="<?php echo esc_url($icon_url_1); ?>" alt="1"></label></div><?php endif; ?>
                <?php if ($icon_url_2) : ?><div><input type="radio" id="count_2" name="red_emblem_count" value="2"><label for="count_2"><img src="<?php echo esc_url($icon_url_2); ?>" alt="2"></label></div><?php endif; ?>
            </div>
            
            <div class="form-actions" style="display: flex; gap: 10px; align-items: center; margin-top: 20px;">
                <input type="submit" name="red_emblem_submit" value="登録する" style="padding: 10px 20px; font-size: 16px; cursor: pointer;">
                <button type="button" id="open-edit-modal-button" class="button">情報を編集</button>
            </div>
        </form>
    </div>
    <div id="edit-modal" class="re-modal-overlay">
        <div class="re-modal-content">
            <span class="re-modal-close">&times;</span>
            <h2>情報編集</h2>
            <form method="post" action="">
                <?php wp_nonce_field('red_emblem_edit_action'); ?>
                <input type="hidden" id="edit_member_id" name="edit_member_id" value="">
                
                <div class="form-row">
                    <label>User No</label>
                    <span id="user_no_display" style="padding: 8px; display: block; background: #f0f0f0; border-radius: 3px;"></span>
                </div>
                <div class="form-row">
                    <label for="edit_member_name">新しい名前（変更しない場合は空欄）</label>
                    <input type="text" id="edit_member_name" name="edit_member_name" placeholder="現在の名前：">
                </div>
                <div class="form-row">
                    <label>新しいアイコン（変更する場合のみ選択）</label>
                    <div class="icon-preview" id="preview_edit_icon">
                        </div>
                    <input type="hidden" id="edit_member_icon_url" name="edit_member_icon_url" value="">
                    <p>
                        <button type="button" class="button" id="select-edit-icon-button">Select Image File</button>
                        <input type="file" id="edit_icon_file_input" style="display: none;" accept="image/png, image/jpeg, image/gif">
                        <span class="upload-progress" style="display: none; margin-left: 10px;"></span>
                    </p>
                </div>
                <div class="form-row">
                    <label for="edit_member_password">パスワード</label>
                    <input type="password" id="edit_member_password" name="edit_member_password" required>
                </div>
                <input type="submit" name="edit_member_submit" value="変更を保存">
            </form>
        </div>
    </div>
        <div id="add-user-modal" class="re-modal-overlay">
            <div class="re-modal-content">
                <span class="re-modal-close">&times;</span>
                <h2>新規ユーザー登録</h2>
                <form method="post" action="">
                    <?php wp_nonce_field('red_emblem_add_user_action'); ?>
                    
                    <div class="form-row">
                        <label for="add_user_name">ユーザー名</label>
                        <input type="text" id="add_user_name" name="add_user_name" required>
                    </div>
                    <div class="form-row">
                        <label for="add_user_no">User No</label>
                        <input type="text" id="add_user_no" name="add_user_no" required>
                    </div>
                    <div class="form-row">
                        <label>アイコン</label>
                        <div class="icon-preview" id="preview_add_icon"></div>
                        <input type="hidden" id="add_member_icon_url" name="add_member_icon_url" value="">
                        <p>
                            <button type="button" class="button" id="select-add-icon-button">Select Image File</button>
                            <input type="file" id="add_icon_file_input" style="display: none;" accept="image/png, image/jpeg, image/gif">
                            <span class="upload-progress" style="display: none; margin-left: 10px;"></span>
                        </p>
                    </div>
                    <div class="form-row">
                        <label for="add_user_password">パスワード</label>
                        <input type="password" id="add_user_password" name="add_user_password" required>
                    </div>
                    <input type="submit" name="add_user_submit" value="ユーザーを登録">
                </form>
            </div>
        </div>

    <?php
    return ob_get_clean();
});

// [red_emblem_table] Web表示用テーブル
add_shortcode('red_emblem_table', function() {
    global $wpdb;
    $table = $wpdb->prefix . 'red_emblems';

    $weeks = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT DATE(DATE_SUB(submitted_at, INTERVAL (WEEKDAY(submitted_at)) DAY)) as week_start FROM %s ORDER BY week_start DESC",
        $table
    ));
    
    $current_week_start = date('Y-m-d', strtotime('monday this week'));
    $selected_week_start = isset($_GET['week']) ? sanitize_text_field($_GET['week']) : $current_week_start;

    $monday = $selected_week_start;
    $sunday = date('Y-m-d 23:59:59', strtotime($monday . ' +6 days'));
    
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE submitted_at BETWEEN %s AND %s ORDER BY submitted_at ASC", $monday, $sunday));

    $first_round_users = [];
    $second_round_users = [];
    $zero_entries = [];

    foreach ($rows as $row) {
        $count = (int)$row->red_emblems;
        if ($count === 0) {
            $zero_entries[] = $row;
        } else {
            $first_round_users[] = $row->name;
            if ($count === 2) {
                $second_round_users[] = $row->name;
            }
        }
    }

    // --- クリップボードにコピーするテキストを生成 ---
    $display_list = array_merge($first_round_users, $second_round_users);
    $clipboard_text = '';
    // 1〜28番目までのリストを作成
    $main_list_for_copy = array_slice($display_list, 0, 28);
    foreach ($main_list_for_copy as $index => $name) {
        $clipboard_text .= ($index + 1) . ' ' . $name . "\n";
    }

    // 0個だった人のリストを作成
    if (!empty($zero_entries)) {
        $clipboard_text .= "\n【今回は不要】\n";
        foreach ($zero_entries as $entry) {
            $clipboard_text .= $entry->name . "\n";
        }
    }
    $clipboard_text = trim($clipboard_text); // 末尾の不要な改行を削除
    // --- ここまで ---

    ob_start();

    // --- コピーボタンとJavaScriptを追加 ---
    ?>
    <div style="margin-bottom: 1em;">
        <button id="copy-results-button" type="button" style="padding: 8px 16px; font-size: 16px; cursor: pointer;">結果をコピー</button>
        <textarea id="results-for-clipboard" style="position: absolute; left: -9999px; top: 0; opacity: 0;"><?php echo esc_textarea($clipboard_text); ?></textarea>
    </div>
    <script>
    document.getElementById('copy-results-button').addEventListener('click', function() {
        var textarea = document.getElementById('results-for-clipboard');
        textarea.select();
        try {
            var successful = document.execCommand('copy');
            var button = this;
            if(successful) {
                button.textContent = 'コピーしました！';
                setTimeout(function() {
                    button.textContent = '結果をコピー';
                }, 2000);
            } else {
                button.textContent = 'コピー失敗';
            }
        } catch (err) {
            console.error('コピーに失敗しました', err);
        }
    });
    </script>
    <?php
    // --- ここまで ---

    ?>
    <form method="get" action="">
        <select name="week" onchange="this.form.submit()">
            <option value="<?php echo esc_attr($current_week_start); ?>">-- 今週 --</option>
            <?php foreach ($weeks as $week_start) : ?>
                <?php
                $week_end_display = date('n月j日', strtotime($week_start . ' +6 days'));
                $display_text = date('n月j日', strtotime($week_start)) . ' ～ ' . $week_end_display;
                ?>
                <option value="<?php echo esc_attr($week_start); ?>" <?php selected($selected_week_start, $week_start); ?>>
                    <?php echo esc_html($display_text); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <noscript><input type="submit" value="表示"></noscript>
    </form>
    <?php

    echo '<h3>週別集計：' . esc_html(date('n/j', strtotime($monday))) . ' ～ ' . esc_html(date('n/j', strtotime($sunday))) . '</h3>';
    echo '<table border="1" style="width:100%; border-collapse: collapse;"><tr><th>番号</th><th>名前</th></tr>';

    $total = 0;

    foreach ($first_round_users as $name) {
        $total++;
        $color = 'black';
        echo '<tr style="color:' . $color . '"><td>' . $total . '</td><td>' . esc_html($name) . '</td></tr>';
    }

    foreach ($second_round_users as $name) {
        $total++;
        $color = 'red';
        echo '<tr style="color:' . $color . '"><td>' . $total . '</td><td>' . esc_html($name) . '</td></tr>';
    }

    echo '</table>';

    if (!empty($zero_entries)) {
        echo '<h4>【0個】</h4><ul>';
        foreach ($zero_entries as $entry) {
            echo '<li>' . esc_html($entry->name) . '</li>';
        }
        echo '</ul>';
    }
    return ob_get_clean();
});


// === フロントエンドからのアイコンアップロード処理 ===
// WordPressのAJAXアクションに、これから作成する関数を登録します。
add_action('wp_ajax_re_upload_icon', 'handle_re_upload_icon');
add_action('wp_ajax_nopriv_re_upload_icon', 'handle_re_upload_icon'); // ログインしていないユーザーでも実行可能にする

function handle_re_upload_icon() {
    // --- セキュリティチェック ---
    // Nonce（ワンタイムトークン）を検証し、正当なリクエストか確認します。
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'red_emblem_edit_action')) {
        wp_send_json_error(['message' => 'Nonce validation failed.'], 403);
        return;
    }
    
    // --- ファイル存在チェック ---
    if (!isset($_FILES['icon_file'])) {
        wp_send_json_error(['message' => 'No file was uploaded.'], 400);
        return;
    }

    $file = $_FILES['icon_file'];

    // --- アップロードエラーチェック ---
    if ($file['error']) {
        wp_send_json_error(['message' => 'File upload error. Code: ' . $file['error']], 500);
        return;
    }

    // --- ファイルサイズチェック (2MBまで) ---
    if ($file['size'] > 2 * 1024 * 1024) {
        wp_send_json_error(['message' => 'File is too large. Max size is 2MB.'], 400);
        return;
    }
    
    // --- ファイルタイプ(MIME)のチェック ---
    $allowed_mime_types = ['image/jpeg', 'image/png', 'image/gif'];
    $file_info = wp_check_filetype_and_ext($file['tmp_name'], $file['name']);
    if (!in_array($file_info['type'], $allowed_mime_types)) {
        wp_send_json_error(['message' => 'Invalid file type. Only JPG, PNG, GIF are allowed.'], 400);
        return;
    }
    
    // --- ファイルの保存処理 ---
    // 保存先のディレクトリパスと、ブラウザからアクセスするためのURLを定義します。
    $upload_dir_path = plugin_dir_path(__FILE__) . 'images/';
    $upload_dir_url = plugin_dir_url(__FILE__) . 'images/';
    
    // 同じ名前のファイルで上書きしないように、ユニークなファイル名を生成します。
    $file_name = wp_unique_filename($upload_dir_path, $file['name']);
    
    // WordPressの一時ディレクトリから、私たちの'images'ディレクトリにファイルを移動します。
    if (move_uploaded_file($file['tmp_name'], $upload_dir_path . $file_name)) {
        // 成功した場合、新しいファイルのURLをJSON形式で返します。
        wp_send_json_success(['url' => $upload_dir_url . $file_name]);
    } else {
        // 失敗した場合、エラーメッセージを返します。
        wp_send_json_error(['message' => 'Failed to move uploaded file.'], 500);
    }
}

// === ★★ ここから追加するコード ★★ ===

// === メンバー詳細情報を取得するAJAXハンドラ ===
add_action('wp_ajax_get_member_details', 'handle_get_member_details');
add_action('wp_ajax_nopriv_get_member_details', 'handle_get_member_details');

function handle_get_member_details() {
    // Nonceを検証
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'red_emblem_edit_action')) {
        wp_send_json_error(['message' => 'Nonce validation failed.'], 403);
        return;
    }

    if (!isset($_POST['member_id'])) {
        wp_send_json_error(['message' => 'Member ID is missing.'], 400);
        return;
    }

    global $wpdb;
    $members_table = $wpdb->prefix . 'red_emblem_members';
    $member_id = intval($_POST['member_id']);

    $member = $wpdb->get_row($wpdb->prepare("SELECT user_no, icon_url FROM $members_table WHERE id = %d", $member_id));

    if ($member) {
        wp_send_json_success([
            'user_no' => $member->user_no,
            'icon_url' => $member->icon_url,
        ]);
    } else {
        wp_send_json_error(['message' => 'Member not found.'], 404);
    }
}