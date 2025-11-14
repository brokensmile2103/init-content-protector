<?php

defined( 'ABSPATH' ) || exit;

// Hook với priority cao để chạy sau khi shortcode và caption đã được xử lý
add_filter( 'the_content', 'init_plugin_suite_content_protector_filter_post_content', 99, 1 );

function init_plugin_suite_content_protector_filter_post_content( $content ) {
    // Chỉ chạy trên frontend và single post
    if ( is_admin() || ! is_singular() ) {
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
        // Không cần wpautop vì content đã được xử lý đầy đủ
        $encrypted = wp_json_encode( init_plugin_suite_content_protector_encrypt( $content ) );

        if ( $encrypted ) {

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
            return '<div class="uk-alert-danger">Encryption failed.</div>';
        }
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
