<?php

defined( 'ABSPATH' ) || exit;

// Hook với priority cao để chạy sau khi shortcode và caption đã được xử lý
add_filter( 'the_content', 'init_plugin_suite_content_protector_filter_post_content', 99, 1 );

function init_plugin_suite_content_protector_filter_post_content( $content ) {
    // Chỉ chạy trên frontend và single post
    if ( is_admin() || ! is_singular() ) {
        return $content;
    }

    // AMP pages don't allow custom/inline <script> — injecting our payload
    // script or JS-protection script would make the page fail AMP validation.
    // Skip entirely and serve normal content on AMP endpoints.
    if ( function_exists( 'init_plugin_suite_content_protector_is_amp_endpoint' )
        && init_plugin_suite_content_protector_is_amp_endpoint()
    ) {
        return $content;
    }

    // Lấy post hiện tại
    global $post;
    if ( ! $post ) {
        return $content;
    }

    // Lấy cấu hình plugin
    $option = get_option( INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_OPTION, [] );

    // Nếu user thuộc nhóm bị loại trừ => trả nguyên content, không đụng gì cả
    if ( function_exists( 'init_plugin_suite_content_protector_is_excluded_for_current_user' )
        && init_plugin_suite_content_protector_is_excluded_for_current_user( $option )
    ) {
        return $content;
    }
    
    $allowed_post_types = $option['post_types'] ?? [];

    // Kiểm tra post type có được bảo vệ không
    if ( ! in_array( $post->post_type, $allowed_post_types, true ) ) {
        return $content;
    }

    // Xử lý inject noise nếu được bật
    if ( ! empty( $option['inject_noise'] ) && $option['inject_noise'] === '1' ) {
        $content = init_plugin_suite_content_protector_inject_noise( $content );
    }

    $content = init_plugin_suite_content_protector_replace_keywords( $content, $post->ID );

    // Xử lý encrypt mode
    if ( ! empty( $option['content_mode'] ) && $option['content_mode'] == 'encrypt' ) {

        // Cache theo post_id + post_modified_gmt + encrypt_key. Key thay đổi
        // tự động khi bài viết được sửa hoặc encrypt_key thay đổi — nên
        // KHÔNG hash theo $content ở đây (content tại điểm này đã bị noise
        // injection chèn chữ ngẫu nhiên theo mỗi request, nên hash sẽ đổi
        // gần như mỗi request → tạo dòng transient mới liên tục → phình
        // wp_options theo traffic/bot, đúng kiểu anti-pattern mà rate-limit
        // theo IP đã mắc phải). Ở encrypt mode, toàn bộ content đã bị AES
        // hóa nên noise "đông cứng" trong 12h không làm giảm khả năng chống
        // bot: bot không giải mã được thì có noise hay không cũng như nhau.
        $cache_key = 'icp_enc_' . $post->ID . '_' . md5(
            $post->post_modified_gmt . '|' . ( $option['encrypt_key'] ?? '' )
        );
        $encrypted_json = get_transient( $cache_key );

        if ( false === $encrypted_json ) {
            $encrypted_json = init_plugin_suite_content_protector_encrypt( $content );
            if ( false !== $encrypted_json ) {
                set_transient( $cache_key, $encrypted_json, 12 * HOUR_IN_SECONDS );
            }
        }

        // Host không hỗ trợ OpenSSL/PBKDF2, hoặc encrypt thất bại vì lý do khác.
        // Fail-open: hiển thị nội dung gốc cho khách thay vì chặn hẳn trang —
        // với plugin public dùng trên nhiều host khác nhau, "hiện nội dung thật"
        // vẫn tốt hơn "hiện lỗi vĩnh viễn cho mọi khách truy cập".
        if ( false === $encrypted_json ) {
            if ( current_user_can( 'manage_options' ) ) {
                return '<div class="uk-alert-danger">'
                    . esc_html__( 'Init Content Protector: encryption is unavailable on this server (missing OpenSSL or PBKDF2 support). Showing unprotected content. This notice is only visible to administrators.', 'init-content-protector' )
                    . '</div>' . $content;
            }
            return $content;
        }

        // Không cần wpautop vì content đã được xử lý đầy đủ.
        // $encrypted_json đã được đảm bảo là JSON string hợp lệ ở trên (early-return
        // nếu false), nên wp_json_encode() ở đây luôn cho kết quả truthy — không cần
        // nhánh else "Encryption failed" nữa (nhánh đó trước là dead code vì
        // wp_json_encode(false) trả về chuỗi "false", vẫn truthy trong PHP, nên
        // never actually triggered).
        $encrypted = wp_json_encode( $encrypted_json );

        // Chống chèn trùng (phòng khi filter chạy lại)
        static $imc_payload_printed = false;
        if ( ! $imc_payload_printed ) {
            $imc_payload_printed = true;

            // Tạo thẻ <script> in-line an toàn, không phụ thuộc enqueue
            $js  = 'window.InitContentEncryptedPayload = ' . $encrypted . ';';
            $js .= 'try{window.dispatchEvent(new CustomEvent("init-content-payload-ready"));}catch(e){}';

            if ( function_exists( 'wp_get_inline_script_tag' ) ) {
                // WP >= 5.7: tự thêm nonce/type chuẩn
                $script_tag = wp_get_inline_script_tag(
                    $js,
                    [
                        'id'   => 'init-content-protector-inline',
                        'type' => 'text/javascript',
                    ]
                );
            } else {
                // Fallback cho WP cũ
                $script_tag = '<script id="init-content-protector-inline" type="text/javascript">' . $js . '</script>';
            }

            // Ghép script vào đầu content để chắc chắn có payload sớm
            $content = $script_tag
                     . '<div class="imc-skeleton-line"></div>'
                     . '<div class="imc-skeleton-line short"></div>'
                     . '<div class="imc-skeleton-line"></div>'
                     . '<div class="imc-skeleton-line"></div>'
                     . '<div class="imc-skeleton-line short"></div>';
            return $content;
        }

        // Nếu vì lý do nào đó đã in rồi, thì vẫn trả skeleton
        $protected_content  = '<div class="imc-skeleton-line"></div>';
        $protected_content .= '<div class="imc-skeleton-line short"></div>';
        $protected_content .= '<div class="imc-skeleton-line"></div>';
        $protected_content .= '<div class="imc-skeleton-line"></div>';
        $protected_content .= '<div class="imc-skeleton-line short"></div>';
        return $protected_content;

    } else {
        // Content đã được xử lý đầy đủ, không cần wpautop
        return $content;
    }
}

/*
WordPress content processing priorities:
Priority 8: wpautop
Priority 9: do_blocks (Gutenberg blocks)
Priority 10: Capital_P_dangit
Priority 11: do_shortcode
Priority 12: img_caption_shortcode
Priority 99: Chạy sau tất cả để đảm bảo content đã được xử lý đầy đủ
*/
