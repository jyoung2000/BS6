<?php
/**
 * Box Document Viewer
 * Handles document viewing with shareable URLs
 */

if (!defined('ABSPATH')) {
    exit;
}

class Box_Document_Viewer {

    /**
     * Initialize
     */
    public static function init() {
        add_action('init', array(__CLASS__, 'add_rewrite_rules'));
        add_filter('query_vars', array(__CLASS__, 'add_query_vars'));
        add_action('template_redirect', array(__CLASS__, 'handle_document_view'));

        // AJAX handler for getting document info
        add_action('wp_ajax_box_get_document_url', array(__CLASS__, 'ajax_get_document_url'));
        add_action('wp_ajax_nopriv_box_get_document_url', array(__CLASS__, 'ajax_get_document_url'));

        // AJAX handler for Box AI chat
        add_action('wp_ajax_box_ai_chat', array(__CLASS__, 'ajax_box_ai_chat'));
        add_action('wp_ajax_nopriv_box_ai_chat', array(__CLASS__, 'ajax_box_ai_chat'));

        // Enqueue chat scripts on document viewer pages
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_chat_scripts'));
    }

    /**
     * Add rewrite rules for document viewing
     */
    public static function add_rewrite_rules() {
        add_rewrite_rule(
            '^box-document/([^/]+)/?$',
            'index.php?box_document_id=$matches[1]',
            'top'
        );
    }

    /**
     * Add custom query vars
     */
    public static function add_query_vars($vars) {
        $vars[] = 'box_document_id';
        return $vars;
    }

    /**
     * Handle document view requests
     */
    public static function handle_document_view() {
        $file_id = get_query_var('box_document_id');

        if (!$file_id) {
            return;
        }

        // Check authentication
        $auth_status = Box_Auth::get_auth_status();
        if (!$auth_status['authenticated'] || $auth_status['expired']) {
            wp_die(__('Not authenticated with Box. Please contact the site administrator.', 'box-api-integration'));
        }

        // Get file info from Box
        $credentials = Box_API_Integration::get_instance()->get_credentials();
        $client = new Box_API_Client($credentials);

        $file_info = $client->get_file_info($file_id);

        if (is_wp_error($file_info)) {
            wp_die(__('File not found or access denied.', 'box-api-integration'));
        }

        // Get embed/preview URL
        $embed_url = self::get_embed_url($file_id);

        // Display the document
        self::render_document_page($file_info, $embed_url);
        exit;
    }

    /**
     * Get embed URL for a document
     */
    public static function get_embed_url($file_id) {
        $credentials = Box_API_Integration::get_instance()->get_credentials();
        $client = new Box_API_Client($credentials);

        // Get embed link from Box
        $response = $client->request("files/{$file_id}?fields=expiring_embed_link", 'GET');

        if (is_wp_error($response)) {
            return null;
        }

        if (isset($response['expiring_embed_link']['url'])) {
            return $response['expiring_embed_link']['url'];
        }

        // Fallback to shared link
        $shared_link = $client->create_shared_link($file_id);
        if (!is_wp_error($shared_link) && isset($shared_link['shared_link']['url'])) {
            return $shared_link['shared_link']['url'];
        }

        return null;
    }

    /**
     * Render document viewing page
     */
    private static function render_document_page($file_info, $embed_url) {
        $file_name = isset($file_info['name']) ? $file_info['name'] : 'Document';
        $file_size = isset($file_info['size']) ? size_format($file_info['size'], 2) : '';
        $modified_at = isset($file_info['modified_at']) ? date('F j, Y g:i a', strtotime($file_info['modified_at'])) : '';

        // Get download URL
        $download_url = '';
        if (isset($file_info['id'])) {
            $credentials = Box_API_Integration::get_instance()->get_credentials();
            $client = new Box_API_Client($credentials);
            $download_response = $client->request("files/{$file_info['id']}/content", 'GET', array(), false);
            if (!is_wp_error($download_response)) {
                $download_url = $download_response;
            }
        }

        // Manually enqueue scripts here to ensure they load
        wp_enqueue_style('dashicons');
        wp_enqueue_script('jquery');

        // Enqueue the new Apple-inspired chat CSS
        wp_enqueue_style(
            'box-ai-chat-style',
            BOX_API_PLUGIN_URL . 'assets/css/box-ai-chat.css',
            array(),
            BOX_API_VERSION . '-' . time()
        );

        wp_enqueue_script(
            'box-ai-chat',
            BOX_API_PLUGIN_URL . 'assets/js/box-ai-chat.js',
            array('jquery'),
            BOX_API_VERSION . '-' . time(), // Add timestamp to prevent caching
            true
        );

        wp_localize_script('box-ai-chat', 'boxAiChat', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('box_ai_chat_nonce')
        ));

        // Add custom styles to wp_head
        add_action('wp_head', function() use ($file_name) {
            ?>
            <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
            <title><?php echo esc_html($file_name); ?> - <?php bloginfo('name'); ?></title>
            <style>
                /* Modern Apple-inspired document viewer styles */
                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }

                html {
                    -webkit-text-size-adjust: 100%;
                    touch-action: manipulation;
                }

                body {
                    font-family: -apple-system, BlinkMacSystemFont, "SF Pro Display", "SF Pro Text", "Helvetica Neue", Arial, sans-serif;
                    -webkit-font-smoothing: antialiased;
                    -moz-osx-font-smoothing: grayscale;
                }

                .box-document-viewer-wrapper {
                    min-height: 100vh;
                    display: flex;
                    flex-direction: column;
                    background: #F2F2F7;
                }

                .document-viewer-header {
                    position: sticky;
                    top: 0;
                    background: rgba(255, 255, 255, 0.98);
                    backdrop-filter: blur(20px) saturate(180%);
                    -webkit-backdrop-filter: blur(20px) saturate(180%);
                    border-bottom: 1px solid rgba(0, 0, 0, 0.08);
                    padding: 16px 24px;
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    z-index: 999;
                }

                .document-info {
                    flex: 1;
                    min-width: 0;
                    margin-right: 20px;
                }

                .document-name {
                    font-size: 18px;
                    font-weight: 600;
                    color: #1C1C1E;
                    margin-bottom: 4px;
                    overflow: hidden;
                    text-overflow: ellipsis;
                    white-space: nowrap;
                    letter-spacing: -0.02em;
                }

                .document-meta {
                    font-size: 13px;
                    color: #8E8E93;
                    font-weight: 400;
                }

                .document-actions {
                    display: flex;
                    gap: 10px;
                    align-items: center;
                    flex-shrink: 0;
                }

                /* Apple-inspired button styles */
                .btn {
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    gap: 6px;
                    padding: 10px 20px;
                    font-size: 15px;
                    font-weight: 500;
                    text-decoration: none;
                    border-radius: 10px;
                    transition: all 0.2s cubic-bezier(0.25, 0.46, 0.45, 0.94);
                    border: none;
                    cursor: pointer;
                    white-space: nowrap;
                    letter-spacing: -0.01em;
                    -webkit-tap-highlight-color: transparent;
                }

                .btn-primary {
                    color: #fff !important;
                    background: #007AFF;
                    box-shadow: 0 2px 8px rgba(0, 122, 255, 0.25);
                }

                .btn-primary:hover {
                    background: #0051D5;
                    transform: translateY(-1px);
                    box-shadow: 0 4px 16px rgba(0, 122, 255, 0.35);
                }

                .btn-primary:active {
                    transform: translateY(0);
                }

                .btn-secondary {
                    color: #1C1C1E !important;
                    background: rgba(0, 0, 0, 0.04);
                    border: 1px solid rgba(0, 0, 0, 0.08);
                }

                .btn-secondary:hover {
                    background: rgba(0, 0, 0, 0.08);
                    transform: translateY(-1px);
                }

                .btn-ai {
                    color: #fff !important;
                    background: linear-gradient(135deg, #0070E0, #0061D5);
                    box-shadow: 0 2px 8px rgba(0, 112, 224, 0.35);
                }

                .btn-ai:hover {
                    background: linear-gradient(135deg, #0080F0, #0070E0);
                    transform: translateY(-1px);
                    box-shadow: 0 4px 16px rgba(0, 112, 224, 0.45);
                }

                .btn-ai:active {
                    transform: translateY(0);
                    box-shadow: 0 2px 6px rgba(0, 112, 224, 0.3);
                }

                .btn-ai .dashicons {
                    font-size: 17px;
                    width: 17px;
                    height: 17px;
                }

                .document-viewer-container {
                    flex: 1;
                    display: flex;
                    background: #fff;
                    min-height: calc(100vh - 80px);
                }

                .document-viewer-container iframe {
                    width: 100%;
                    height: 100%;
                    min-height: 800px;
                    border: none;
                    display: block;
                }

                .document-viewer-error {
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    height: 100%;
                    flex-direction: column;
                    gap: 20px;
                    color: #1C1C1E;
                    padding: 24px;
                }

                .document-viewer-error p {
                    font-size: 16px;
                    color: #8E8E93;
                }

                /* Tablet responsive design */
                @media (max-width: 768px) {
                    .document-viewer-header {
                        flex-direction: column;
                        gap: 12px;
                        align-items: stretch;
                        padding: 14px 20px;
                    }

                    .document-info {
                        margin-right: 0;
                    }

                    .document-name {
                        font-size: 16px;
                    }

                    .document-meta {
                        font-size: 12px;
                    }

                    .document-actions {
                        width: 100%;
                        gap: 8px;
                    }

                    .btn {
                        flex: 1;
                        font-size: 14px;
                        padding: 10px 16px;
                    }

                    .btn-ai {
                        flex: 0 0 100%;
                        order: -1;
                    }

                    .document-viewer-container iframe {
                        min-height: 600px;
                    }
                }

                /* Mobile responsive design */
                @media (max-width: 480px) {
                    .document-viewer-header {
                        padding: 12px 16px;
                    }

                    .btn {
                        font-size: 13px;
                        padding: 9px 14px;
                    }
                }

                /* Dark mode support */
                @media (prefers-color-scheme: dark) {
                    body {
                        background: #000;
                        color: #fff;
                    }

                    .box-document-viewer-wrapper {
                        background: #1C1C1E;
                    }

                    .document-viewer-header {
                        background: rgba(28, 28, 30, 0.98);
                        border-bottom-color: rgba(255, 255, 255, 0.1);
                    }

                    .document-name {
                        color: #F2F2F7;
                    }

                    .document-meta {
                        color: #8E8E93;
                    }

                    .btn-secondary {
                        color: #F2F2F7 !important;
                        background: rgba(255, 255, 255, 0.08);
                        border-color: rgba(255, 255, 255, 0.12);
                    }

                    .btn-secondary:hover {
                        background: rgba(255, 255, 255, 0.12);
                    }

                    .document-viewer-container {
                        background: #2C2C2E;
                    }
                }

                /* =================================================
                   BOX AI CHAT - PREMIUM APPLE DESIGN SYSTEM
                   ================================================= */

                /* CSS Variables */
                :root {
                    --chat-primary: #007AFF;
                    --chat-primary-dark: #0051D5;
                    --chat-purple: #AF52DE;
                    --chat-purple-dark: #8E3AC0;
                    --chat-gray-50: #FAFAFA;
                    --chat-gray-100: #F2F2F7;
                    --chat-gray-200: #E5E5EA;
                    --chat-gray-300: #C7C7CC;
                    --chat-gray-400: #8E8E93;
                    --chat-gray-500: #636366;
                    --chat-gray-900: #1C1C1E;
                    --chat-background: #FFFFFF;
                    --chat-surface: #F5F5F7;
                    --chat-overlay: rgba(0, 0, 0, 0.4);
                    --chat-border: rgba(0, 0, 0, 0.06);
                    --chat-shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.08);
                    --chat-shadow-md: 0 4px 16px rgba(0, 0, 0, 0.12);
                    --chat-shadow-lg: 0 12px 32px rgba(0, 0, 0, 0.16);
                    --chat-shadow-2xl: 0 32px 64px rgba(0, 0, 0, 0.24);
                    --chat-radius-sm: 8px;
                    --chat-radius-lg: 16px;
                    --chat-radius-xl: 20px;
                    --chat-radius-2xl: 24px;
                    --chat-radius-3xl: 28px;
                    --chat-radius-full: 9999px;
                    --chat-spacing-sm: 8px;
                    --chat-spacing-md: 12px;
                    --chat-spacing-lg: 16px;
                    --chat-spacing-xl: 20px;
                    --chat-spacing-2xl: 24px;
                }

                /* Chat Wrapper */
                .box-ai-chat-wrapper {
                    position: fixed;
                    top: 0;
                    left: 0;
                    right: 0;
                    bottom: 0;
                    z-index: 9999;
                    font-family: -apple-system, BlinkMacSystemFont, "SF Pro Display", "SF Pro Text", "Helvetica Neue", Arial, sans-serif;
                }

                /* Backdrop */
                .box-ai-chat-backdrop {
                    position: absolute;
                    inset: 0;
                    background: var(--chat-overlay);
                    backdrop-filter: blur(24px) saturate(180%);
                    -webkit-backdrop-filter: blur(24px) saturate(180%);
                    animation: backdropFadeIn 0.35s ease-out;
                    cursor: pointer;
                }

                @keyframes backdropFadeIn {
                    from { opacity: 0; backdrop-filter: blur(0); }
                    to { opacity: 1; backdrop-filter: blur(24px) saturate(180%); }
                }

                /* Chat Container */
                .box-ai-chat-container {
                    position: absolute;
                    top: 50%;
                    left: 50%;
                    transform: translate(-50%, -50%);
                    width: 90%;
                    max-width: 920px;
                    height: 86vh;
                    max-height: 820px;
                    background: var(--chat-background);
                    border-radius: var(--chat-radius-3xl);
                    display: flex;
                    flex-direction: column;
                    overflow: hidden;
                    box-shadow: var(--chat-shadow-2xl);
                    border: 1px solid var(--chat-border);
                    animation: containerSlideUp 0.45s cubic-bezier(0.34, 1.56, 0.64, 1);
                }

                @keyframes containerSlideUp {
                    from { opacity: 0; transform: translate(-50%, -48%) scale(0.94); }
                    to { opacity: 1; transform: translate(-50%, -50%) scale(1); }
                }

                /* Header */
                .box-ai-chat-header {
                    background: linear-gradient(135deg, var(--chat-purple) 0%, var(--chat-purple-dark) 100%);
                    padding: var(--chat-spacing-xl) var(--chat-spacing-2xl);
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    position: relative;
                    overflow: hidden;
                    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
                }

                .box-ai-chat-header::before {
                    content: '';
                    position: absolute;
                    inset: 0;
                    background: linear-gradient(180deg, rgba(255, 255, 255, 0.12) 0%, transparent 100%);
                    pointer-events: none;
                }

                .box-ai-chat-header::after {
                    content: '';
                    position: absolute;
                    inset: 0;
                    background: radial-gradient(circle at 20% 50%, rgba(255, 255, 255, 0.1) 0%, transparent 60%);
                    pointer-events: none;
                }

                .box-ai-chat-header-content {
                    display: flex;
                    align-items: center;
                    gap: var(--chat-spacing-md);
                    position: relative;
                    z-index: 1;
                }

                .box-ai-chat-avatar {
                    width: 40px;
                    height: 40px;
                    background: rgba(255, 255, 255, 0.2);
                    border-radius: var(--chat-radius-full);
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    backdrop-filter: blur(10px);
                }

                .box-ai-chat-avatar svg {
                    width: 24px;
                    height: 24px;
                    fill: white;
                }

                .box-ai-chat-title {
                    color: white;
                }

                .box-ai-chat-title h3 {
                    font-size: 18px;
                    font-weight: 600;
                    letter-spacing: -0.02em;
                    margin: 0 0 2px 0;
                }

                .box-ai-chat-title p {
                    font-size: 14px;
                    opacity: 0.9;
                    margin: 0;
                }

                .box-ai-chat-close {
                    width: 38px;
                    height: 38px;
                    background: rgba(255, 255, 255, 0.18);
                    border: 1.5px solid rgba(255, 255, 255, 0.25);
                    border-radius: var(--chat-radius-full);
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    cursor: pointer;
                    transition: all 0.2s ease-out;
                    position: relative;
                    z-index: 1;
                    backdrop-filter: blur(12px);
                }

                .box-ai-chat-close:hover {
                    background: rgba(255, 255, 255, 0.28);
                    transform: scale(1.08);
                }

                .box-ai-chat-close svg {
                    width: 18px;
                    height: 18px;
                    fill: white;
                }

                /* Messages */
                .box-ai-chat-messages {
                    flex: 1;
                    overflow-y: auto;
                    padding: var(--chat-spacing-2xl);
                    background: linear-gradient(180deg, var(--chat-surface) 0%, var(--chat-gray-50) 100%);
                    -webkit-overflow-scrolling: touch;
                }

                .box-ai-chat-messages::-webkit-scrollbar {
                    width: 7px;
                }

                .box-ai-chat-messages::-webkit-scrollbar-thumb {
                    background: var(--chat-gray-300);
                    border-radius: var(--chat-radius-full);
                }

                /* Welcome */
                .box-ai-chat-welcome {
                    display: flex;
                    flex-direction: column;
                    align-items: center;
                    text-align: center;
                    padding: var(--chat-spacing-3xl);
                    min-height: 300px;
                    justify-content: center;
                }

                .box-ai-chat-welcome-icon {
                    width: 80px;
                    height: 80px;
                    background: linear-gradient(135deg, var(--chat-purple), var(--chat-purple-dark));
                    border-radius: var(--chat-radius-2xl);
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    margin-bottom: var(--chat-spacing-xl);
                    box-shadow: 0 12px 32px rgba(175, 82, 222, 0.3);
                }

                .box-ai-chat-welcome-icon svg {
                    width: 40px;
                    height: 40px;
                    fill: white;
                }

                .box-ai-chat-welcome h4 {
                    font-size: 20px;
                    font-weight: 600;
                    color: var(--chat-gray-900);
                    margin: 0 0 8px 0;
                }

                .box-ai-chat-welcome p {
                    font-size: 16px;
                    color: var(--chat-gray-500);
                    line-height: 1.5;
                    max-width: 400px;
                    margin: 0;
                }

                /* Messages */
                .box-ai-chat-message {
                    display: flex;
                    margin-bottom: var(--chat-spacing-xl);
                    animation: messageSlide 0.3s ease-out;
                }

                @keyframes messageSlide {
                    from { opacity: 0; transform: translateY(10px); }
                    to { opacity: 1; transform: translateY(0); }
                }

                .box-ai-chat-message-user {
                    justify-content: flex-end;
                }

                .box-ai-chat-message-ai {
                    justify-content: flex-start;
                }

                .box-ai-chat-message-content {
                    max-width: 70%;
                    padding: var(--chat-spacing-md) var(--chat-spacing-lg);
                    border-radius: var(--chat-radius-xl);
                    font-size: 16px;
                    line-height: 1.5;
                }

                .box-ai-chat-message-user .box-ai-chat-message-content {
                    background: linear-gradient(135deg, var(--chat-primary) 0%, var(--chat-primary-dark) 100%);
                    color: white;
                    border-bottom-right-radius: var(--chat-radius-sm);
                    box-shadow: 0 4px 16px rgba(0, 122, 255, 0.25);
                }

                .box-ai-chat-message-ai .box-ai-chat-message-content {
                    background: var(--chat-background);
                    color: var(--chat-gray-900);
                    border-bottom-left-radius: var(--chat-radius-sm);
                    box-shadow: var(--chat-shadow-sm);
                    border: 1px solid var(--chat-border);
                }

                /* Typing Indicator */
                .box-ai-chat-typing-indicator {
                    display: flex;
                    gap: 4px;
                    padding: var(--chat-spacing-md) var(--chat-spacing-lg);
                    background: var(--chat-background);
                    border-radius: var(--chat-radius-xl);
                    border-bottom-left-radius: var(--chat-radius-sm);
                    box-shadow: var(--chat-shadow-sm);
                    border: 1px solid var(--chat-border);
                }

                .box-ai-chat-typing-dot {
                    width: 8px;
                    height: 8px;
                    background: var(--chat-gray-400);
                    border-radius: var(--chat-radius-full);
                    animation: typingDot 1.4s infinite;
                }

                .box-ai-chat-typing-dot:nth-child(2) { animation-delay: 0.2s; }
                .box-ai-chat-typing-dot:nth-child(3) { animation-delay: 0.4s; }

                @keyframes typingDot {
                    0%, 60%, 100% { transform: translateY(0); opacity: 0.4; }
                    30% { transform: translateY(-10px); opacity: 1; }
                }

                /* Input Area */
                .box-ai-chat-input-area {
                    padding: var(--chat-spacing-lg) var(--chat-spacing-2xl);
                    background: var(--chat-background);
                    border-top: 1px solid var(--chat-border);
                    display: flex;
                    gap: var(--chat-spacing-md);
                    align-items: flex-end;
                }

                .box-ai-chat-input-wrapper {
                    flex: 1;
                }

                .box-ai-chat-input {
                    width: 100%;
                    padding: var(--chat-spacing-md) var(--chat-spacing-lg);
                    background: var(--chat-gray-50);
                    border: 1.5px solid var(--chat-gray-200);
                    border-radius: var(--chat-radius-lg);
                    font-size: 16px;
                    font-family: inherit;
                    resize: vertical;
                    transition: all 0.3s ease-out;
                    min-height: 80px;
                    max-height: 200px;
                    line-height: 1.5;
                }

                .box-ai-chat-input:focus {
                    outline: none;
                    border-color: var(--chat-purple);
                    background: var(--chat-background);
                    box-shadow: 0 0 0 4px rgba(175, 82, 222, 0.12), 0 2px 8px rgba(0, 0, 0, 0.04);
                    transform: translateY(-1px);
                }

                /* Send Button */
                .box-ai-chat-send {
                    width: 50px;
                    height: 50px;
                    background: linear-gradient(135deg, var(--chat-purple) 0%, var(--chat-purple-dark) 100%);
                    border: none;
                    border-radius: var(--chat-radius-full);
                    color: white;
                    cursor: pointer;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    transition: all 0.2s ease-out;
                    box-shadow: 0 4px 16px rgba(175, 82, 222, 0.35);
                }

                .box-ai-chat-send:hover {
                    transform: translateY(-2px) scale(1.02);
                    box-shadow: 0 8px 24px rgba(175, 82, 222, 0.45);
                }

                .box-ai-chat-send:disabled {
                    opacity: 0.35;
                    cursor: not-allowed;
                }

                .box-ai-chat-send svg {
                    width: 22px;
                    height: 22px;
                    fill: currentColor;
                }

                /* Mobile */
                @media (max-width: 480px) {
                    .box-ai-chat-container {
                        width: 100%;
                        height: 100%;
                        min-height: 90vh;
                        max-width: none;
                        max-height: none;
                        border-radius: 0;
                        border: none;
                    }

                    .box-ai-chat-header {
                        padding-top: max(16px, env(safe-area-inset-top));
                    }

                    .box-ai-chat-messages {
                        padding: 16px;
                    }

                    .box-ai-chat-message-content {
                        max-width: 82%;
                    }

                    .box-ai-chat-input-area {
                        padding: 16px;
                        padding-bottom: max(16px, env(safe-area-inset-bottom));
                    }

                    .box-ai-chat-input {
                        font-size: 16px; /* Prevents iOS zoom */
                        min-height: 70px;
                        border-radius: var(--chat-radius-md);
                    }

                    .box-ai-chat-send {
                        width: 46px;
                        height: 46px;
                    }
                }
            </style>
            <?php
        }, 999);


        // Start WordPress page
        get_header();
        ?>

        <div class="box-document-viewer-wrapper">
            <div class="document-viewer-header">
                <div class="document-info">
                    <div class="document-name"><?php echo esc_html($file_name); ?></div>
                    <div class="document-meta">
                        <?php if ($file_size) : ?>
                            <?php echo esc_html($file_size); ?>
                        <?php endif; ?>
                        <?php if ($modified_at) : ?>
                            <?php echo $file_size ? ' · ' : ''; ?>Modified <?php echo esc_html($modified_at); ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="document-actions">
                    <button id="box-ai-chat-button" class="btn btn-ai" data-file-id="<?php echo esc_attr($file_info['id']); ?>">
                        <span class="dashicons dashicons-format-chat"></span>
                        <span>Chat with AI</span>
                    </button>
                    <?php if ($download_url) : ?>
                        <a href="<?php echo esc_url($download_url); ?>" class="btn btn-primary" download>Download</a>
                    <?php endif; ?>
                    <button onclick="window.close();" class="btn btn-secondary">Close</button>
                </div>
            </div>

            <div class="document-viewer-container">
                <?php if ($embed_url) : ?>
                    <iframe src="<?php echo esc_url($embed_url); ?>" allowfullscreen></iframe>
                <?php else : ?>
                    <div class="document-viewer-error">
                        <p>Unable to preview this document.</p>
                        <?php if ($download_url) : ?>
                            <a href="<?php echo esc_url($download_url); ?>" class="btn btn-primary" download>Download File</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Box AI Chat Modal - Apple Design System -->
            <div id="box-ai-chat-modal" class="box-ai-chat-wrapper" style="display: none;">
                <div class="box-ai-chat-backdrop"></div>
                <div class="box-ai-chat-container">
                    <div class="box-ai-chat-header">
                        <div class="box-ai-chat-header-content">
                            <div class="box-ai-chat-avatar">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 3c1.66 0 3 1.34 3 3s-1.34 3-3 3-3-1.34-3-3 1.34-3 3-3zm0 14.2c-2.5 0-4.71-1.28-6-3.22.03-1.99 4-3.08 6-3.08 1.99 0 5.97 1.09 6 3.08-1.29 1.94-3.5 3.22-6 3.22z"/>
                                </svg>
                            </div>
                            <div class="box-ai-chat-title">
                                <h3>Box AI Assistant</h3>
                                <p>Ask anything about this document</p>
                            </div>
                        </div>
                        <button id="box-ai-chat-close" class="box-ai-chat-close" aria-label="Close chat">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
                            </svg>
                        </button>
                    </div>
                    
                    <div class="box-ai-chat-messages" id="box-ai-chat-messages">
                        <div class="box-ai-chat-welcome">
                            <div class="box-ai-chat-welcome-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M20 2H4c-1.1 0-1.99.9-1.99 2L2 22l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-7 9h-2V5h2v6zm0 4h-2v-2h2v2z"/>
                                </svg>
                            </div>
                            <h4>Welcome to Box AI</h4>
                            <p>I'm here to help you understand this document. Ask me anything - from summaries to specific details!</p>
                        </div>
                    </div>
                    
                    <div class="box-ai-chat-input-area">
                        <div class="box-ai-chat-input-wrapper">
                            <textarea 
                                id="box-ai-chat-input" 
                                class="box-ai-chat-input" 
                                placeholder="Type your question..." 
                                rows="1"
                                aria-label="Type your message"></textarea>
                        </div>
                        <button id="box-ai-chat-send" class="box-ai-chat-send" aria-label="Send message">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>
                            </svg>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <script>
        console.log('=== Box AI Chat Debug ===');
        console.log('Document viewer page loaded');
        console.log('jQuery available:', typeof jQuery !== 'undefined');
        console.log('boxAiChat object:', typeof boxAiChat !== 'undefined' ? boxAiChat : 'NOT FOUND');
        console.log('Chat button exists:', document.getElementById('box-ai-chat-button') !== null);
        console.log('File ID on button:', document.getElementById('box-ai-chat-button') ? document.getElementById('box-ai-chat-button').getAttribute('data-file-id') : 'NO BUTTON');
        </script>

        <?php
        get_footer();
    }

    /**
     * Get public URL for a document
     */
    public static function get_document_url($file_id) {
        return home_url('/box-document/' . $file_id . '/');
    }

    /**
     * AJAX handler to get document URL
     */
    public static function ajax_get_document_url() {
        check_ajax_referer('box_search_nonce', 'nonce');

        $file_id = isset($_POST['file_id']) ? sanitize_text_field($_POST['file_id']) : '';

        if (empty($file_id)) {
            wp_send_json_error('File ID is required');
        }

        $url = self::get_document_url($file_id);
        wp_send_json_success(array('url' => $url));
    }

    /**
     * Enqueue chat scripts
     */
    public static function enqueue_chat_scripts() {
        // Only enqueue on document viewer pages
        $file_id = get_query_var('box_document_id');

        if ($file_id) {
            // Enqueue dashicons for chat icon
            wp_enqueue_style('dashicons');

            wp_enqueue_script(
                'box-ai-chat',
                BOX_API_PLUGIN_URL . 'assets/js/box-ai-chat.js',
                array('jquery'),
                BOX_API_VERSION,
                true
            );

            wp_localize_script('box-ai-chat', 'boxAiChat', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('box_ai_chat_nonce')
            ));
        }
    }

    /**
     * AJAX handler for Box AI chat
     */
    public static function ajax_box_ai_chat() {
        check_ajax_referer('box_ai_chat_nonce', 'nonce');

        $file_id = isset($_POST['file_id']) ? sanitize_text_field($_POST['file_id']) : '';
        $message = isset($_POST['message']) ? sanitize_text_field($_POST['message']) : '';

        if (empty($file_id)) {
            wp_send_json_error('File ID is required');
        }

        if (empty($message)) {
            wp_send_json_error('Message is required');
        }

        // Check authentication
        $auth_status = Box_Auth::get_auth_status();
        if (!$auth_status['authenticated'] || $auth_status['expired']) {
            wp_send_json_error('Not authenticated with Box');
        }

        // Call Box AI
        $credentials = Box_API_Integration::get_instance()->get_credentials();
        $client = new Box_API_Client($credentials);

        $response = $client->ai_ask($file_id, $message);

        if (is_wp_error($response)) {
            // Get detailed error information
            $error_data = $response->get_error_data();
            $error_message = $response->get_error_message();

            // Add more context if available
            if (is_array($error_data) && isset($error_data['message'])) {
                $error_message = $error_data['message'];
            }

            $error_code = '';
            if (is_array($error_data) && isset($error_data['code'])) {
                $error_code = $error_data['code'];
            }

            // Handle specific error: insufficient_scope
            if ($error_code === 'insufficient_scope') {
                $error_message = "Box AI requires additional permissions. Please add 'AI' scopes to your Box App:\n\n" .
                                "1. Go to Box Developer Console (https://app.box.com/developers/console)\n" .
                                "2. Select your app\n" .
                                "3. Go to 'Configuration' tab\n" .
                                "4. Under 'Application Scopes', enable:\n" .
                                "   - 'Read and write all files and folders'\n" .
                                "   - 'Manage AI' (if available)\n" .
                                "5. Click 'Save Changes'\n" .
                                "6. Go back to WordPress and re-authenticate (logout and login again)\n\n" .
                                "Note: Your Box account must have Box AI enabled (requires Enterprise Plus plan)";
            } elseif ($error_code) {
                $error_message .= ' (Code: ' . $error_code . ')';
            }

            error_log('Box AI Error: ' . print_r($error_data, true));
            wp_send_json_error($error_message);
        }

        // Log successful response for debugging
        error_log('Box AI Response: ' . print_r($response, true));

        // Extract answer from response
        $answer = '';
        if (isset($response['answer'])) {
            $answer = $response['answer'];
        } elseif (isset($response['completion'])) {
            $answer = $response['completion'];
        } elseif (isset($response['choices'][0]['message']['content'])) {
            // Alternative response format
            $answer = $response['choices'][0]['message']['content'];
        } else {
            error_log('Box AI: Unexpected response format: ' . print_r($response, true));
            wp_send_json_error('Unexpected response format from Box AI. Please check the error logs.');
        }

        wp_send_json_success(array(
            'answer' => $answer,
            'created_at' => isset($response['created_at']) ? $response['created_at'] : current_time('mysql')
        ));
    }
}
