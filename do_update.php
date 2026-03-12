<?php
/**
 * 系统更新处理API
 * 处理更新包的下载、解压和安装
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

function generateVersionCode($version) {
    $version = str_replace('V', '', $version);
    $parts = explode('.', $version);
    $major = isset($parts[0]) ? intval($parts[0]) : 0;
    $minor = isset($parts[1]) ? intval($parts[1]) : 0;
    $patch = isset($parts[2]) ? intval($parts[2]) : 0;
    return $major * 1000 + $minor * 100 + $patch;
}

$action = $_GET['action'] ?? '';

if ($action === 'download') {
    $download_url = $_GET['url'] ?? '';
    $md5_hash = $_GET['md5'] ?? '';
    $version = $_GET['version'] ?? '';

    if (empty($download_url)) {
        echo json_encode(['success' => false, 'message' => '下载链接不能为空']);
        exit;
    }

    $update_dir = __DIR__ . '/../temp_update/';
    if (!is_dir($update_dir)) {
        mkdir($update_dir, 0777, true);
    }

    $zip_file = $update_dir . 'update_' . $version . '.zip';
    
    // 如果文件已存在，先删除（避免使用旧的缓存文件）
    if (file_exists($zip_file)) {
        unlink($zip_file);
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $download_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');

    $file_data = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($http_code != 200) {
        $debug_info = [
            'url' => $download_url, 
            'http_code' => $http_code,
            'error' => $error
        ];
        if (!empty($file_data) && strlen($file_data) < 500) {
            $debug_info['response'] = $file_data;
        }
        echo json_encode(['success' => false, 'message' => '下载失败 HTTP ' . $http_code . ' - ' . $error, 'debug' => $debug_info]);
        exit;
    }
    
    if (empty($file_data)) {
        echo json_encode(['success' => false, 'message' => '下载内容为空']);
        exit;
    }
    
    // 直接保存，不检查content-type
    file_put_contents($zip_file, $file_data);
    
    // 验证是否为有效ZIP文件
    if (!extension_loaded('zip')) {
        // 如果zip扩展未加载，尝试通过文件头判断
        $file_header = substr($file_data, 0, 4);
        if ($file_header !== "PK\x03\x04" && $file_header !== "PK\x05\x06") {
            unlink($zip_file);
            echo json_encode(['success' => false, 'message' => '下载的文件不是有效的ZIP压缩包', 'debug' => ['file_header' => bin2hex($file_header)]]);
            exit;
        }
    } else {
        $zip = new ZipArchive();
        if ($zip->open($zip_file) !== true) {
            unlink($zip_file);
            echo json_encode(['success' => false, 'message' => '下载的文件不是有效的ZIP压缩包']);
            exit;
        }
        $zip->close();
    }

    // 文件已在第73行保存，这里进行MD5校验
    if (!empty($md5_hash)) {
        $file_md5 = md5_file($zip_file);
        if ($file_md5 !== $md5_hash) {
            unlink($zip_file);
            echo json_encode(['success' => false, 'message' => '文件校验失败，MD5不匹配']);
            exit;
        }
    }

    echo json_encode([
        'success' => true,
        'message' => '下载成功',
        'zip_file' => basename($zip_file),
        'version' => $version
    ]);
    exit;
}

if ($action === 'install') {
    $zip_file = $_GET['file'] ?? '';
    $version = $_GET['version'] ?? '';

    if (empty($zip_file)) {
        echo json_encode(['success' => false, 'message' => '更新包文件不能为空']);
        exit;
    }

    $update_dir = __DIR__ . '/../temp_update/';
    $full_path = $update_dir . $zip_file;

    if (!file_exists($full_path)) {
        echo json_encode(['success' => false, 'message' => '更新包不存在']);
        exit;
    }

    $extract_dir = $update_dir . 'extract_' . time() . '/';
    mkdir($extract_dir, 0777, true);

    $zip = new ZipArchive();
    $zipResult = $zip->open($full_path);
    if ($zipResult !== true) {
        echo json_encode(['success' => false, 'message' => '无法打开更新包，错误代码: ' . $zipResult]);
        exit;
    }

    $extractResult = $zip->extractTo($extract_dir);
    $zip->close();
    
    if (!$extractResult) {
        echo json_encode(['success' => false, 'message' => '解压更新包失败']);
        exit;
    }

    $root_dir = dirname(__DIR__);

    function copyDirectory($src, $dst, $hasSubfolder = false) {
        $files = scandir($src);
        error_log("复制目录: src=" . $src . ", dst=" . $dst . ", hasSubfolder=" . ($hasSubfolder ? '是' : '否') . ", 文件数=" . count($files));
        
        foreach ($files as $file) {
            if ($file == '.' || $file == '..') continue;
            
            $src_file = $src . '/' . $file;
            
            // 如果有子文件夹，则保持文件夹名称
            if ($hasSubfolder) {
                $dst_file = $dst . '/' . $file;
            } else {
                $dst_file = $dst . '/' . $file;
            }
            
            error_log("处理: " . $file . " -> " . $dst_file);
            
            if (is_dir($src_file)) {
                if (!is_dir($dst_file)) {
                    mkdir($dst_file, 0777, true);
                }
                copyDirectory($src_file, $dst_file, $hasSubfolder);
            } else {
                $result = copy($src_file, $dst_file);
                error_log("复制文件结果: " . ($result ? '成功' : '失败'));
            }
        }
    }

    $files = scandir($extract_dir);
    $main_extract_dir = $extract_dir;
    $folderName = '';
    
    // 过滤掉 . 和 ..
    $realFiles = array_diff($files, ['.', '..']);
    
    // 调试日志
    error_log("ZIP解压目录内容: " . print_r($realFiles, true));
    
    // 如果解压后有文件夹，使用整个解压目录（包含文件夹）
    foreach ($realFiles as $file) {
        $checkDir = $extract_dir . '/' . $file;
        if (is_dir($checkDir)) {
            $folderName = $file;
            error_log("检测到文件夹: " . $folderName . "，将复制整个文件夹到根目录");
            break;
        }
    }
    
    // 如果解压后只有1个文件，直接用根目录
    if (count($realFiles) == 1 && !is_dir($extract_dir . '/' . reset($realFiles))) {
        $main_extract_dir = $extract_dir;
        $folderName = '';
        error_log("只有文件，使用根目录: " . $main_extract_dir);
    }

    $root_dir = dirname(__DIR__);
    error_log("开始复制: folderName=" . $folderName);
    
    if ($folderName) {
        // 复制整个文件夹
        $src = $extract_dir . '/' . $folderName;
        $dst = $root_dir;
        error_log("复制文件夹: " . $src . " -> " . $dst);
        
        // 复制文件夹
        if (!is_dir($dst . '/' . $folderName)) {
            mkdir($dst . '/' . $folderName, 0777, true);
        }
        copyDirectory($src, $dst . '/' . $folderName);
    } else {
        // 复制文件
        copyDirectory($main_extract_dir, $root_dir);
    }
    
    error_log("复制完成，检查目标目录:");
    error_log("templates目录存在: " . (is_dir($root_dir . '/templates') ? '是' : '否'));
    error_log("simple-clean.php存在: " . (file_exists($root_dir . '/templates/simple-clean.php') ? '是' : '否'));

    $deleteListFile = $main_extract_dir . '/delete_files.txt';
    if (file_exists($deleteListFile)) {
        $deleteFiles = file($deleteListFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($deleteFiles as $deleteFile) {
            $deleteFile = trim($deleteFile);
            if (empty($deleteFile) || strpos($deleteFile, '#') === 0) continue;
            
            $fileToDelete = $root_dir . '/' . $deleteFile;
            if (file_exists($fileToDelete)) {
                if (is_dir($fileToDelete)) {
                    rmdir($fileToDelete);
                } else {
                    unlink($fileToDelete);
                }
            }
        }
    }

    $versionFile = $root_dir . '/config/version.php';
    $versionData = [
        'version' => $version,
        'version_code' => generateVersionCode($version),
        'update_time' => date('Y-m-d H:i:s')
    ];
    file_put_contents($versionFile, '<?php return ' . var_export($versionData, true) . ';');

    function deleteDirectory($dir) {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir), array('.','..'));
        foreach ($files as $file) {
            $path = "$dir/$file";
            is_dir($path) ? deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    deleteDirectory($extract_dir);
    unlink($full_path);

    $updateServerUrl = 'http://gengxin.zhiyuantongxin.cn/api/check_update.php';
    $fromVersion = '0';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $updateServerUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'action' => 'update_complete',
        'version_id' => 1,
        'from_version' => $fromVersion,
        'to_version' => $version,
        'version_type' => '598',
        'domain' => $_SERVER['HTTP_HOST'] ?? ''
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_exec($ch);
    curl_close($ch);

    $ch2 = curl_init();
    curl_setopt($ch2, CURLOPT_URL, $updateServerUrl . '?action=check&current_version=0&version_type=598');
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch2, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch2, CURLOPT_SSL_VERIFYHOST, false);
    $versionInfoResponse = curl_exec($ch2);
    curl_close($ch2);
    
    $versionInfo = json_decode($versionInfoResponse, true);
    $updateLog = isset($versionInfo['update_log']) ? $versionInfo['update_log'] : '系统更新';
    $releaseDate = isset($versionInfo['release_date']) ? $versionInfo['release_date'] : date('Y-m-d');

    // 处理更新日志 - 按序号分割或按换行分割
    $updateLog = str_replace(["\r\n", "\r"], "\n", $updateLog);
    
    // 先尝试按序号分割 (1、 2、 3、 或 1. 2. 3.)
    $featuresArray = [];
    
    // 匹配中文序号格式：1、xxx 2、xxx 或 1.xxx 2.xxx
    if (preg_match('/^\d+[、\.]/', trim($updateLog))) {
        // 按序号分割 - 使用更精确的正则，匹配序号到下一个序号之前
        preg_match_all('/(\d+[、\.].*?)(?=\s*\d+[、\.]|$)/su', $updateLog, $matches);
        if (!empty($matches[1])) {
            foreach ($matches[1] as $item) {
                $item = trim($item);
                if (!empty($item)) {
                    $featuresArray[] = "            '" . addslashes($item) . "'";
                }
            }
        }
    }
    
    // 如果没有按序号分割成功，则按换行分割
    if (empty($featuresArray)) {
        $updateLogLines = explode("\n", $updateLog);
        $updateLogLines = array_filter($updateLogLines, function($line) {
            return trim($line) !== '';
        });
        foreach ($updateLogLines as $line) {
            $featuresArray[] = "            '" . addslashes(trim($line)) . "'";
        }
    }
    
    if (empty($featuresArray)) {
        $featuresArray[] = "            '系统更新'";
    }
    $featuresStr = implode(",\n", $featuresArray);

    $updateLogsFile = $root_dir . '/admin/update-logs-data.php';
    if (file_exists($updateLogsFile)) {
        $updateLogsContent = file_get_contents($updateLogsFile);
        $updateLogsContent = str_replace("'is_current' => true", "'is_current' => false", $updateLogsContent);
        $newLogEntry = "    [
        'version' => '" . $version . "',
        'date' => '" . $releaseDate . "',
        'is_current' => true,
        'features' => [
" . $featuresStr . "
        ],
        'fixes' => [],
        'maintenance' => []
    ],";
        
        $updateLogsContent = str_replace('$updateLogs = [', '$updateLogs = [' . "\n" . $newLogEntry, $updateLogsContent);
        file_put_contents($updateLogsFile, $updateLogsContent);
    }

    echo json_encode([
        'success' => true,
        'message' => '更新安装成功'
    ]);
    exit;
}

if ($action === 'cleanup') {
    $update_dir = __DIR__ . '/../temp_update/';
    
    if (is_dir($update_dir)) {
        function deleteDirectory($dir) {
            if (!is_dir($dir)) return;
            $files = array_diff(scandir($dir), array('.','..'));
            foreach ($files as $file) {
                $path = "$dir/$file";
                is_dir($path) ? deleteDirectory($path) : unlink($path);
            }
            rmdir($dir);
        }
        deleteDirectory($update_dir);
    }
    
    echo json_encode(['success' => true, 'message' => '清理完成']);
    exit;
}

echo json_encode(['success' => false, 'message' => '无效的操作']);
