<?php
/**
 * 系统更新检查API
 * 用于连接更新服务器检查是否有新版本
 */

define('UPDATE_SERVER_URL', 'http://gengxin.zhiyuantongxin.cn');
define('UPDATE_API_URL', UPDATE_SERVER_URL . '/api/check_update.php');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'check') {
    $current_version = $_GET['current_version'] ?? '0';
    $domain = $_GET['domain'] ?? $_SERVER['HTTP_HOST'] ?? '';
    $version_type = $_GET['version_type'] ?? getLocalVersionType();
    
    $version_code = versionToCode($current_version);

    $post_data = [
        'action' => 'check',
        'current_version' => $version_code,
        'domain' => $domain,
        'version_type' => $version_type
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, UPDATE_API_URL);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($http_code == 200 && $response) {
        $result = json_decode($response, true);
        if ($result && isset($result['has_update'])) {
            echo json_encode([
                'success' => $result['success'] ?? true,
                'has_update' => $result['has_update'],
                'version' => $result['version'] ?? '',
                'version_code' => $result['version_code'] ?? 0,
                'release_date' => $result['release_date'] ?? '',
                'update_log' => $result['update_log'] ?? '',
                'download_url' => $result['download_url'] ?? '',
                'file_size' => $result['file_size'] ?? '',
                'md5_hash' => $result['md5_hash'] ?? '',
                'force_update' => $result['force_update'] ?? false,
                'message' => $result['message'] ?? ''
            ]);
            exit;
        }
    }

    echo json_encode([
        'success' => false,
        'has_update' => false,
        'message' => '检查更新失败，请稍后重试',
        'debug' => [
            'http_code' => $http_code,
            'response_length' => strlen($response),
            'response_preview' => substr($response, 0, 200)
        ]
    ]);
    exit;
}

if ($action === 'get_server_info') {
    echo json_encode([
        'success' => true,
        'server_url' => UPDATE_SERVER_URL,
        'api_url' => UPDATE_API_URL
    ]);
    exit;
}

if ($action === 'verify_license') {
    $domain = $_GET['domain'] ?? $_SERVER['HTTP_HOST'] ?? '';
    $version_type = $_GET['version_type'] ?? getLocalVersionType();
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, UPDATE_API_URL . '?action=verify_license&domain=' . urlencode($domain) . '&version_type=' . urlencode($version_type));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    if ($response) {
        echo $response;
    } else {
        echo json_encode(['success' => false, 'message' => '验证失败']);
    }
    exit;
}

function versionToCode($version) {
    $version = str_replace('V', '', $version);
    $parts = explode('.', $version);
    $major = isset($parts[0]) ? intval($parts[0]) : 0;
    $minor = isset($parts[1]) ? intval($parts[1]) : 0;
    $patch = isset($parts[2]) ? intval($parts[2]) : 0;
    return $major * 1000 + $minor * 100 + $patch;
}

function getLocalVersionType() {
    $possible_files = [
        __DIR__ . '/../config/license.php',
        __DIR__ . '/../config/database.php',
        __DIR__ . '/../../config/license.php'
    ];
    
    foreach ($possible_files as $file) {
        if (file_exists($file)) {
            $content = file_get_contents($file);
            if (preg_match('/VERSION_TYPE[\'"]?\s*[:=]\s*[\'"]?(\d+)/i', $content, $matches)) {
                return $matches[1];
            }
            if (preg_match('/version_type[\'"]?\s*[:=]\s*[\'"]?(\d+)/i', $content, $matches)) {
                return $matches[1];
            }
        }
    }
    
    return '598';
}

echo json_encode([
    'success' => false,
    'message' => '无效的操作'
]);
